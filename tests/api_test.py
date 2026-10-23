"""
Prove automatiche di Scaffolder Pro: chiama api.php di una copia isolata servita da `php -S`.
Si lancia da tests/run.sh (che prepara la copia, il sito di prova e il finto server di Claude):

    python3 api_test.py <URL dell'app> <cartella www della copia> <binario php> <URL del sito di prova>

Ogni controllo stampa "ok" o "FAIL"; alla fine il numero di errori (uscita 1 se ce ne sono).
I test sono in ordine: quelli che attivano la password e il blocco dei tentativi vengono per ultimi.
"""
import base64, hashlib, hmac, http.cookiejar, io, json, os, re, struct, subprocess, sys, time, urllib.error, urllib.parse, urllib.request, uuid, zipfile, zlib

BASE, WWW, PHP, SITE_URL = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4]   # SITE_URL: il sito di prova su un altro server
APP = os.path.join(WWW, 'scaffolder-pro')
SITE = os.path.join(WWW, 'site')
fails = 0


def check(name, cond, extra=''):
    global fails
    print(('  ok   ' if cond else '  FAIL ') + name + ('' if cond else '  →  ' + str(extra)[:400]))
    if not cond:
        fails += 1


def section(title):
    print('\n# ' + title)


class Client:
    """Un browser: cookie propri, token CSRF letto da index.php."""

    def __init__(self, basic=None):
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.basic = basic
        self.csrf = ''

    def req(self, url, data=None, headers=None):
        h = dict(headers or {})
        if self.basic:
            h['Authorization'] = 'Basic ' + base64.b64encode(self.basic.encode()).decode()
        try:
            with self.op.open(urllib.request.Request(url, data=data, headers=h), timeout=180) as r:
                return r.status, r.read()
        except urllib.error.HTTPError as e:
            return e.code, e.read()

    def page(self):
        st, b = self.req(BASE + 'index.php')
        m = re.search(rb'"csrf":"([a-f0-9]+)"', b) or re.search(rb'name="csrf" value="([a-f0-9]+)"', b)
        if m:
            self.csrf = m.group(1).decode()
        return st, b

    def get(self, action, **p):
        st, b = self.req(BASE + 'api.php?' + urllib.parse.urlencode(dict(action=action, **p)), headers={'Accept': 'application/json'})
        try:
            return json.loads(b)
        except Exception:
            return {'_status': st, '_raw': b[:300]}

    def post(self, fields, files=None):
        fields = dict(fields, csrf=self.csrf)
        if files:
            bd = uuid.uuid4().hex
            out = io.BytesIO()
            for k, v in fields.items():
                out.write(f'--{bd}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
            for k, (fn, data) in files.items():
                out.write(f'--{bd}\r\nContent-Disposition: form-data; name="{k}"; filename="{fn}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode())
                out.write(data)
                out.write(b'\r\n')
            out.write(f'--{bd}--\r\n'.encode())
            data, ct = out.getvalue(), 'multipart/form-data; boundary=' + bd
        else:
            data, ct = urllib.parse.urlencode(fields).encode(), 'application/x-www-form-urlencoded'
        st, b = self.req(BASE + 'api.php', data, {'Content-Type': ct, 'Accept': 'application/json', 'X-CSRF-Token': self.csrf})
        try:
            return json.loads(b)
        except Exception:
            return {'_status': st, '_raw': b[:300]}

    def form(self, fields):
        """Invio del modulo di login (index.php)."""
        st, b = self.req(BASE + 'index.php', urllib.parse.urlencode(dict(fields, csrf=self.csrf)).encode())
        m = re.search(rb'"csrf":"([a-f0-9]+)"', b)
        if m:
            self.csrf = m.group(1).decode()
        return st, b


def totp(secret, step=None):
    """Codice a 6 cifre (RFC 6238), come quello di un'app di autenticazione."""
    key = base64.b32decode(secret + '=' * (-len(secret) % 8))
    step = int(time.time() // 30) if step is None else step
    h = hmac.new(key, struct.pack('>Q', step), hashlib.sha1).digest()
    o = h[19] & 15
    return '%06d' % ((struct.unpack('>I', h[o:o + 4])[0] & 0x7fffffff) % 1000000)


def php_hash(password):
    return subprocess.run([PHP, '-r', 'echo password_hash($argv[1], PASSWORD_BCRYPT);', password], capture_output=True, text=True).stdout


me = Client()
st, body = me.page()
check('index.php risponde', st == 200 and me.csrf, st)
lim = json.loads(re.search(rb'"limits":(\{[^}]+\})', body).group(1))
r = me.post({'action': 'set_root', 'root_folder': SITE})
check('root sul sito di prova', r.get('ok'), r)

# ---------------------------------------------------------------------------
section('Selettore della root')
r = me.get('get_roots')
names = {os.path.basename(i['path']): i for i in r.get('items', [])}
check('get_roots elenca sito e app', r.get('ok') and 'site' in names and 'scaffolder-pro' in names, list(names)[:10])
check("l'app è segnata come non selezionabile", names['scaffolder-pro']['tool'] and not names['scaffolder-pro']['writable'])
check('root attuale modificabile, 1 sottocartella', names['site']['current'] and names['site']['writable'] and names['site']['dirs_n'] == 1, names.get('site'))
os.makedirs(os.path.join(SITE, 'inc/deep/er'), exist_ok=True)
r = me.get('get_root_children', path=os.path.join(SITE, 'inc'), depth=2)
check('rami caricati su richiesta', r.get('ok') and [k['name'] for k in r['items']] == ['deep'] and r['items'][0]['dirs_n'] == 1 and r['items'][0]['depth'] == 3, r)
r = me.get('get_root_children', path=APP, depth=1)
check("nessuna sottocartella dentro l'app", r.get('ok') and r['items'] == [], r)
r = me.get('get_root_children', path='/nonexistent/x', depth=1)
check('percorso inesistente rifiutato', not r.get('ok'), r)

# ---------------------------------------------------------------------------
section('Salvataggio e conflitti')
f = me.get('get_file', file='inc/config.php')
crc = str(zlib.crc32(f['content'].encode()))
r = me.post({'action': 'save_file', 'filepath': 'inc/config.php', 'code': f['content'] + '$b = 2;\n', 'mtime': f['mtime'], 'base_crc': crc})
check('salvataggio con mtime', r.get('ok') and r.get('mtime'), r)
m1 = r['mtime']
time.sleep(1.1)
with open(os.path.join(SITE, 'inc/config.php'), 'a') as fh:
    fh.write('// modifica esterna\n')
r = me.post({'action': 'save_file', 'filepath': 'inc/config.php', 'code': "<?php\n$a = 9;\n", 'mtime': m1, 'base_crc': crc})
check('conflitto riconosciuto', not r.get('ok') and r.get('conflict'), r)
r = me.post({'action': 'save_file', 'filepath': 'inc/config.php', 'code': "<?php\n$a = 9;\n", 'mtime': m1, 'base_crc': crc, 'force_mtime': '1'})
check('sovrascrittura confermata', r.get('ok'), r)
f = me.get('get_file', file='inc/config.php')
r = me.post({'action': 'save_file', 'filepath': 'inc/config.php', 'code': f['content'] + "$b = 2;\n", 'mtime': 1, 'base_crc': str(zlib.crc32(f['content'].encode()))})
check('mtime vecchio ma stesso contenuto: nessun conflitto', r.get('ok'), r)
r = me.post({'action': 'save_file', 'filepath': 'inc/config.php', 'code': "<?php\nfunction (\n"})
check('PHP con errore di sintassi non salvato', not r.get('ok') and r.get('lint', {}).get('line'), r)

# ---------------------------------------------------------------------------
section('Duplica')
r = me.post({'action': 'duplicate_items', 'paths': json.dumps(['index.php', 'inc'])})
paths = [i['path'] for i in r.get('items', [])]
check('file e cartella duplicati', r.get('ok') and 'index copy.php' in paths and 'inc copy' in paths, r)
r = me.post({'action': 'duplicate_items', 'paths': json.dumps(['index.php'])})
check('secondo duplicato numerato', [i['path'] for i in r.get('items', [])] == ['index copy 2.php'], r)

# ---------------------------------------------------------------------------
section('Richieste API (anti-SSRF)')
r = me.post({'action': 'http_save', 'request': json.dumps({'name': 'meta', 'method': 'GET', 'url': 'http://169.254.169.254/latest/meta-data/'})})
r = me.post({'action': 'http_run', 'id': r['request']['id']})
check('metadati cloud bloccati', not r.get('ok') and '169.254' in r.get('error', ''), r)
r = me.post({'action': 'http_save', 'request': json.dumps({'name': 'redir', 'method': 'GET', 'url': SITE_URL + 'redir.php'})})
r = me.post({'action': 'http_run', 'id': r['request']['id']})
check('redirect verso i metadati bloccato', r.get('ok') and r['response']['error'] and '169.254' in r['response']['error'], r)
r = me.post({'action': 'http_save', 'request': json.dumps({'name': 'local', 'method': 'GET', 'url': SITE_URL + 'api.php',
                                                            'auth': {'type': 'bearer', 'value': 'secret123'}})})
r = me.post({'action': 'http_run', 'id': r['request']['id']})
check('localhost permesso', r.get('ok') and r['response']['status'] == 200 and '"ok":1' in r['response']['body'], r)

# ---------------------------------------------------------------------------
section('Claude (finto server): testo non salvato dell\'editor')
with open(os.path.join(SITE, 'inc/config.php'), 'w') as fh:
    fh.write("<?php\n$a = 1;\n")
me.post({'action': 'save_settings', 'settings': json.dumps({'api_key': 'sk-ant-test-1234'})})
over = json.dumps({'inc/config.php': "<?php\n$a = 1;\n$b = 2; // UNSAVED\n"})
r = me.post({'action': 'claude_chat', 'messages': json.dumps([{'role': 'user', 'content': 'read'}]), 'overrides': over})
check('legge il testo dell\'editor', r.get('ok') and 'UNSAVED' in r.get('text', ''), r)
r = me.post({'action': 'claude_chat', 'messages': json.dumps([{'role': 'user', 'content': 'PROPOSE'}]), 'overrides': over})
check('propone sul testo dell\'editor', r.get('ok') and len(r.get('proposals', [])) == 1, r)
r = me.post({'action': 'claude_chat', 'messages': json.dumps([{'role': 'user', 'content': 'PROPOSE'}])})
check('senza il testo dell\'editor la proposta non vale', r.get('ok') and not r.get('proposals'), r)

# ---------------------------------------------------------------------------
section('Caricamento a pezzi (limiti PHP bassi)')
chunk = lim['chunk_bytes']
data = os.urandom(50 * 1048576 + 12345)
sha = hashlib.sha256(data).hexdigest()


def send(uid, payload, off, until):
    r = {}
    while off < until:
        r = me.post({'action': 'upload_chunk', 'id': uid, 'offset': off}, {'chunk': ('chunk', payload[off:off + chunk])})
        if not r.get('ok'):
            break
        off = r['received']
    return off, r


r = me.post({'action': 'upload_start', 'target_dir': 'inc', 'rel': 'big/video.mp4', 'size': len(data), 'mtime': 111})
check('upload_start', r.get('ok') and r['received'] == 0, r)
uid = r['id']
off, r = send(uid, data, 0, len(data) // 2)
check('prima metà inviata', off >= len(data) // 2, r)
r = me.post({'action': 'upload_chunk', 'id': uid, 'offset': 5}, {'chunk': ('chunk', data[5:105])})
check('offset sbagliato → riallineamento', r.get('resync') and r['received'] == off, r)
r = me.post({'action': 'upload_start', 'target_dir': 'inc', 'rel': 'big/video.mp4', 'size': len(data), 'mtime': 111})
check('stesso file → ripresa da dove era arrivato', r.get('ok') and r['id'] == uid and r['received'] == off and r.get('resumed'), r)
off, r = send(uid, data, off, len(data))
r = me.post({'action': 'upload_finish', 'id': uid})
dest = os.path.join(SITE, 'inc/big/video.mp4')
check('file completo, SHA-256 identico', r.get('ok') and os.path.isfile(dest) and hashlib.sha256(open(dest, 'rb').read()).hexdigest() == sha, r)
check('nessun file parziale rimasto', not any(n.startswith('up_') for n in os.listdir(os.path.join(APP, 'data'))))
r = me.post({'action': 'upload_start', 'target_dir': 'inc', 'rel': 'big/video.mp4', 'size': 10, 'mtime': 1})
check('file esistente → chiede', not r.get('ok') and r.get('exists'), r)
r = me.post({'action': 'upload_start', 'target_dir': '', 'rel': 'huge.bin', 'size': 10 * 1073741824, 'mtime': 1})
check('oltre il limite → rifiutato', not r.get('ok') and 'GB' in r.get('error', ''), r)
r = me.post({'action': 'upload_start', 'target_dir': '', 'rel': 'c.bin', 'size': 300000, 'mtime': 1})
cid = r['id']
me.post({'action': 'upload_chunk', 'id': cid, 'offset': 0}, {'chunk': ('chunk', b'x' * 1000)})
r = me.post({'action': 'upload_cancel', 'id': cid})
check('annulla → pezzi cancellati', r.get('ok') and not os.path.exists(os.path.join(APP, f'data/up_{cid}.part')))
r = me.post({'action': 'upload_chunk', 'id': cid, 'offset': 0}, {'chunk': ('chunk', b'x')})
check('pezzo dopo l\'annullamento → sparito', r.get('gone'), r)
st, b = me.req(BASE + 'api.php?action=download&path=inc/big/video.mp4')
check('download a flusso, stesso SHA-256', st == 200 and hashlib.sha256(b).hexdigest() == sha, st)

# ---------------------------------------------------------------------------
section('ZIP: scompattamento a passi')
zb = io.BytesIO()
with zipfile.ZipFile(zb, 'w', zipfile.ZIP_STORED) as z:
    for i in range(400):
        z.writestr(f'pack/f{i:03}.txt', f'file {i}\n' * 50)
    z.writestr('pack/blob.bin', os.urandom(3 * 1048576))
    z.writestr('../evil.txt', 'x')
    z.writestr('__MACOSX/._x', 'x')
zd = zb.getvalue()
r = me.post({'action': 'upload_start', 'target_dir': '', 'rel': 'pack.zip', 'size': len(zd), 'mtime': 5, 'extract': '1'})
off, _ = send(r['id'], zd, 0, len(zd))
r = me.post({'action': 'upload_finish', 'id': r['id']})
check('ZIP caricato → lavoro di scompattamento', r.get('ok') and r.get('job') and r['total'] == 403, r)
job = r['job']
while True:
    r = me.post({'action': 'unzip_step', 'job': job})
    if not r.get('ok') or r.get('done'):
        break
check('401 file estratti, voce pericolosa saltata', r.get('ok') and r['files'] == 401 and r['skipped_n'] == 1, r)
check('ZIP tolto, niente fuori dalla root', os.path.isfile(os.path.join(SITE, 'pack/f399.txt')) and not os.path.exists(os.path.join(SITE, 'pack.zip'))
      and not os.path.exists(os.path.join(WWW, 'evil.txt')))
big = os.path.join(SITE, 'bigzip.zip')
with zipfile.ZipFile(big, 'w', zipfile.ZIP_STORED) as z:
    for i in range(6):
        z.writestr(f'b{i}.bin', os.urandom(9 * 1048576))
r = me.post({'action': 'unzip_item', 'path': 'bigzip.zip'})
check('"Scompatta qui" di uno ZIP > 50 MB → a passi', r.get('ok') and r.get('job') and r['path'] == 'bigzip', r)
job = r['job']
while True:
    r = me.post({'action': 'unzip_step', 'job': job})
    if not r.get('ok') or r.get('done'):
        break
check('6 file estratti, ZIP originale conservato', r.get('ok') and r['files'] == 6 and os.path.isfile(big), r)

# ---------------------------------------------------------------------------
section('Impostazioni')
r = me.post({'action': 'save_settings', 'settings': json.dumps({'theme': 'light', 'minimap': False, 'format_on_save': True, 'emmet': False})})
check('tema, minimappa, formattazione, Emmet', r.get('ok') and r['config']['theme'] == 'light' and r['config']['minimap'] is False
      and r['config']['format_on_save'] is True and r['config']['emmet'] is False, r)
r = me.post({'action': 'save_settings', 'settings': json.dumps({'theme': 'pink'})})
check('tema non valido ignorato', r.get('ok') and r['config']['theme'] == 'light', r)
st, b = me.req(BASE + 'index.php')
check('<html data-theme>', b'data-theme="light"' in b, st)
me.post({'action': 'save_settings', 'settings': json.dumps({'theme': 'dark', 'minimap': True, 'format_on_save': False, 'emmet': True})})
r = me.post({'action': 'save_settings', 'settings': json.dumps({'upload_max_gb': 0.1})})
check('limite per file salvato', r.get('ok') and r['limits']['upload_max'] == 107374182, r)
r = me.post({'action': 'upload_start', 'target_dir': '', 'rel': 'x.bin', 'size': 120 * 1048576, 'mtime': 1})
check('limite per file applicato', not r.get('ok'), r)
me.post({'action': 'save_settings', 'settings': json.dumps({'upload_max_gb': 5})})
r = me.get('security')
check('sicurezza senza password: disattivata', r.get('ok') and r.get('auth') is False, r)

# ---------------------------------------------------------------------------
section('Password, login perpetuo, uscita')
r = me.post({'action': 'setup_auth', 'user': 'admin', 'pass': 'short'})
check('password troppo corta rifiutata', not r.get('ok'), r)
r = me.post({'action': 'setup_auth', 'user': 'ad:min', 'pass': 'longenough1'})
check('nome utente non valido rifiutato', not r.get('ok'), r)
r = me.post({'action': 'setup_auth', 'user': 'admin', 'pass': 'longenough1'})
check('primo utente creato', r.get('ok'), r)
st, b = me.page()
check('chi crea la password resta dentro', st == 200 and b'APP_DATA' in b, st)
r = me.post({'action': 'setup_auth', 'user': 'x', 'pass': 'longenough1'})
check('secondo setup rifiutato', not r.get('ok'), r)

other = Client()
st, b = other.page()
check('browser nuovo → pagina di login (niente popup)', st == 200 and b'name="sp_login"' in b, st)
st, b = other.req(BASE + 'api.php?action=get_tree', headers={'Accept': 'application/json'})
check('api.php → 401 JSON', st == 401 and json.loads(b).get('login'), (st, b[:100]))
st, b = other.form({'sp_login': 1, 'user': 'admin', 'pass': 'wrong'})
check('password sbagliata', b'name="sp_login"' in b and b'class="err"' in b, st)
st, b = other.form({'sp_login': 1, 'user': 'admin', 'pass': 'longenough1'})
check('login → app', st == 200 and b'APP_DATA' in b and b'sp_login' not in b, st)
r = other.post({'action': 'logout'})
check('esci', r.get('ok'), r)
st, _ = other.req(BASE + 'api.php?action=get_tree', headers={'Accept': 'application/json'})
check('dopo l\'uscita → 401', st == 401, st)
check('l\'altro browser resta dentro', me.get('get_tree').get('ok'))
st, _ = Client(basic='admin:longenough1').req(BASE + 'api.php?action=get_tree', headers={'Accept': 'application/json'})
check('HTTP Basic accettato', st == 200, st)

# ---------------------------------------------------------------------------
section('Verifica in due passaggi')
r = me.post({'action': 'totp_begin'})
check('nuovo segreto + URI otpauth', r.get('ok') and len(r['secret']) == 32 and r['uri'].startswith('otpauth://totp/'), r)
secret = r['secret']
r = me.post({'action': 'totp_confirm', 'code': '000000' if totp(secret) != '000000' else '111111'})
check('codice sbagliato rifiutato', not r.get('ok'), r)
step = int(time.time() // 30)
r = me.post({'action': 'totp_confirm', 'code': totp(secret, step)})
check('conferma → 8 codici di emergenza', r.get('ok') and len(r.get('recovery', [])) == 8, r)
recovery = r.get('recovery', [])
r = me.get('security')
check('stato: attiva, 8 codici', r['totp']['enabled'] and r['totp']['recovery_left'] == 8, r)

c2 = Client()
c2.page()
st, b = c2.form({'sp_login': 1, 'user': 'admin', 'pass': 'longenough1'})
pending = re.search(rb'name="pending" value="([^"]+)"', b)
check('password giusta → secondo passaggio', pending is not None and b'name="sp_login2"' in b, b[:200])
st, b = c2.form({'sp_login2': 1, 'pending': pending.group(1).decode(), 'code': totp(secret, step)})
check('stesso codice riusato → rifiutato', b'class="err"' in b, b[:200])
pending = re.search(rb'name="pending" value="([^"]+)"', b)
st, b = c2.form({'sp_login2': 1, 'pending': pending.group(1).decode(), 'code': totp(secret, step + 1)})
check('codice nuovo → dentro', st == 200 and b'APP_DATA' in b, b[:200])
c3 = Client()
c3.page()
st, b = c3.form({'sp_login': 1, 'user': 'admin', 'pass': 'longenough1'})
pending = re.search(rb'name="pending" value="([^"]+)"', b).group(1).decode()
st, b = c3.form({'sp_login2': 1, 'pending': pending, 'code': recovery[0]})
check('codice di emergenza → dentro', st == 200 and b'APP_DATA' in b, b[:200])
check('codici di emergenza rimasti: 7', me.get('security')['totp']['recovery_left'] == 7)
c4 = Client()
c4.page()
st, b = c4.form({'sp_login2': 1, 'pending': 'YWRtaW4=.9999999999.falsa', 'code': '123456'})
check('passaggio intermedio falsificato → rifiutato', b'APP_DATA' not in b, b[:200])
st, _ = Client(basic='admin:longenough1').req(BASE + 'api.php?action=get_tree', headers={'Accept': 'application/json'})
check('HTTP Basic non basta con la verifica attiva', st == 401, st)

# ---------------------------------------------------------------------------
section('Dispositivi collegati')
r = me.get('security')
devs = r.get('devices', [])
check('3 dispositivi, uno è questo', len(devs) == 3 and sum(d['current'] for d in devs) == 1 and all(d['ip'] for d in devs), devs)
target = [d for d in devs if not d['current']][0]['id']
r = me.post({'action': 'device_revoke', 'id': target})
check('esci da un dispositivo', r.get('ok') and len(r['devices']) == 2, r)
r = me.post({'action': 'device_revoke', 'all_others': '1'})
check('esci da tutti gli altri', r.get('ok') and len(r['devices']) == 1 and r['devices'][0]['current'], r)
st2, _ = c2.req(BASE + 'api.php?action=get_tree', headers={'Accept': 'application/json'})
st3, _ = c3.req(BASE + 'api.php?action=get_tree', headers={'Accept': 'application/json'})
check('gli altri browser sono fuori', st2 == 401 and st3 == 401, (st2, st3))
r = me.post({'action': 'totp_disable', 'code': '000000'})
check('disattivazione con codice sbagliato rifiutata', not r.get('ok'), r)
r = me.post({'action': 'totp_disable', 'code': recovery[1]})
check('disattivata con un codice di emergenza', r.get('ok') and not me.get('security')['totp']['enabled'], r)

# ---------------------------------------------------------------------------
section('Blocco dopo 10 tentativi sbagliati')
bad = Client()
bad.page()
for i in range(9):
    bad.form({'sp_login': 1, 'user': 'admin', 'pass': 'wrong%d' % i})
st, b = bad.form({'sp_login': 1, 'user': 'admin', 'pass': 'wrong-last'})
check('10° errore → bloccato', b'15' in b and b'class="err"' in b, b[:300])
st, b = bad.form({'sp_login': 1, 'user': 'admin', 'pass': 'longenough1'})
check('bloccato anche con la password giusta', b'APP_DATA' not in b, b[:200])
st, _ = Client(basic='admin:longenough1').req(BASE + 'api.php?action=get_tree', headers={'Accept': 'application/json'})
check('bloccato anche con HTTP Basic', st == 401, st)
check('chi era già dentro continua a lavorare', me.get('get_tree').get('ok'))
logs = me.get('get_side', limit=20).get('logs', [])
check('voce nel Registro movimenti', any('127.0.0.1' in l['text'] and l['type'] == 'error' for l in logs), [l['text'] for l in logs[:3]])

# ---------------------------------------------------------------------------
section('Password cambiata in .htpasswd')
with open(os.path.join(APP, '.htpasswd'), 'w') as fh:
    fh.write('admin:' + php_hash('other-pass-9') + '\n')
st, _ = me.req(BASE + 'api.php?action=get_tree', headers={'Accept': 'application/json'})
check('i login già fatti non valgono più', st == 401, st)

print('\nFAIL: %d' % fails)
sys.exit(1 if fails else 0)
