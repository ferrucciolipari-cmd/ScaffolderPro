"""
Prova del caricamento a pezzi di un file grande (default 1 GB) con limiti PHP bassi: il file arrivato deve avere
lo stesso SHA-256 dell'originale. Lanciato da tests/run.sh --big:

    python3 big_upload_test.py <URL dell'app> <cartella www della copia> <dimensione in MB>
"""
import hashlib, http.cookiejar, io, json, os, re, sys, tempfile, time, urllib.request, uuid

BASE, WWW, MB = sys.argv[1], sys.argv[2], int(sys.argv[3])
op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
page = op.open(BASE + 'index.php').read().decode()
csrf = re.search(r'"csrf":"([a-f0-9]+)"', page).group(1)
chunk = json.loads(re.search(r'"limits":(\{[^}]+\})', page).group(1))['chunk_bytes']


def post(fields, files=None):
    bd = uuid.uuid4().hex
    out = io.BytesIO()
    for k, v in dict(fields, csrf=csrf).items():
        out.write(f'--{bd}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
    for k, (fn, data) in (files or {}).items():
        out.write(f'--{bd}\r\nContent-Disposition: form-data; name="{k}"; filename="{fn}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode())
        out.write(data)
        out.write(b'\r\n')
    out.write(f'--{bd}--\r\n'.encode())
    r = urllib.request.Request(BASE + 'api.php', out.getvalue(), {'Content-Type': 'multipart/form-data; boundary=' + bd, 'Accept': 'application/json'})
    return json.loads(op.open(r, timeout=300).read())


post({'action': 'set_root', 'root_folder': os.path.join(WWW, 'site')})
src = tempfile.NamedTemporaryFile(delete=False, suffix='.bin')
h = hashlib.sha256()
for _ in range(MB):
    block = os.urandom(1048576)
    h.update(block)
    src.write(block)
src.close()
size = os.path.getsize(src.name)
t0 = time.time()
r = post({'action': 'upload_start', 'target_dir': '', 'rel': 'big-test.mp4', 'size': size, 'mtime': 1})
uid, off = r['id'], r['received']
with open(src.name, 'rb') as f:
    while off < size:
        f.seek(off)
        r = post({'action': 'upload_chunk', 'id': uid, 'offset': off}, {'chunk': ('c', f.read(chunk))})
        assert r.get('ok'), r
        off = r['received']
r = post({'action': 'upload_finish', 'id': uid})
secs = time.time() - t0
dest = os.path.join(WWW, 'site', 'big-test.mp4')
h2 = hashlib.sha256()
with open(dest, 'rb') as f:
    for block in iter(lambda: f.read(8 * 1048576), b''):
        h2.update(block)
os.unlink(src.name)
ok = r.get('ok') and h.hexdigest() == h2.hexdigest()
print(('  ok   ' if ok else '  FAIL ') + f'{MB} MB a pezzi da {chunk // 1024} KB in {secs:.1f} s, SHA-256 ' + ('identico' if ok else 'DIVERSO'))
os.unlink(dest)
sys.exit(0 if ok else 1)
