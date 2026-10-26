<?php
// Finto server dell'API di GitHub per i test (tests/run.sh lo avvia con php -S e ci punta GITHUB_API_URL).
// Tiene in memoria (file JSON) repository, blob, alberi, commit e branch; GET /__state restituisce tutto.
header('Content-Type: application/json');
$file = __DIR__ . '/state.json';
$fp = fopen($file, 'c+'); flock($fp, LOCK_EX);
$st = json_decode((string)stream_get_contents($fp), true) ?: ['repos' => [], 'blobs' => [], 'trees' => [], 'commits' => [], 'n' => 0];
$save = function () use (&$st, $fp) { ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($st)); fflush($fp); };
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$m = $_SERVER['REQUEST_METHOD'];
$body = json_decode((string)file_get_contents('php://input'), true) ?: [];
$out = function ($code, $data) { http_response_code($code); echo json_encode($data); exit; };
if ($path === '/__state') $out(200, $st);
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($auth !== 'Bearer ghp_testtoken1234') $out(401, ['message' => 'Bad credentials']);
$newRepo = function ($owner, $name, $private) use (&$st, $save, $out) {
    $st['commits']['c0'] = ['tree' => 't0', 'parents' => []]; $st['trees']['t0'] = [];
    $st['repos']["$owner/$name"] = ['private' => $private, 'default_branch' => 'main', 'refs' => ['main' => 'c0']];
    $save();
    $out(201, ['html_url' => "https://github.com/$owner/$name", 'default_branch' => 'main', 'permissions' => ['push' => true]]);
};
if ($path === '/user') $out(200, ['login' => 'octo']);
if ($m === 'POST' && $path === '/user/repos') $newRepo('octo', $body['name'], !empty($body['private']));
if ($m === 'POST' && preg_match('#^/orgs/([^/]+)/repos$#', $path, $x)) $newRepo($x[1], $body['name'], !empty($body['private']));
if (preg_match('#^/repos/([^/]+)/([^/]+)(/.*)?$#', $path, $x)) {
    $key = "$x[1]/$x[2]"; $rest = $x[3] ?? '';
    $repo = $st['repos'][$key] ?? null;
    if (!$repo) $out(404, ['message' => 'Not Found']);
    if ($rest === '') $out(200, ['html_url' => "https://github.com/$key", 'default_branch' => $repo['default_branch'], 'permissions' => ['push' => true]]);
    if ($m === 'GET' && preg_match('#^/git/ref/heads/(.+)$#', $rest, $r)) {
        isset($repo['refs'][$r[1]]) ? $out(200, ['object' => ['sha' => $repo['refs'][$r[1]]]]) : $out(404, ['message' => 'Not Found']);
    }
    if ($m === 'POST' && $rest === '/git/blobs') { $sha = sha1((string)$body['content']); $st['blobs'][$sha] = 1; $save(); $out(201, ['sha' => $sha]); }
    if ($m === 'POST' && $rest === '/git/trees') { $id = 't' . (++$st['n']); $st['trees'][$id] = $body['tree']; $save(); $out(201, ['sha' => $id]); }
    if ($m === 'POST' && $rest === '/git/commits') { $id = 'c' . (++$st['n']); $st['commits'][$id] = ['tree' => $body['tree'], 'parents' => $body['parents'], 'message' => $body['message']]; $save(); $out(201, ['sha' => $id]); }
    if ($m === 'POST' && $rest === '/git/refs') { $st['repos'][$key]['refs'][substr($body['ref'], 11)] = $body['sha']; $save(); $out(201, ['ref' => $body['ref']]); }
    if ($m === 'PATCH' && preg_match('#^/git/refs/heads/(.+)$#', $rest, $r)) { $st['repos'][$key]['refs'][$r[1]] = $body['sha']; $save(); $out(200, ['ref' => $r[1]]); }
}
$out(404, ['message' => 'Not Found: ' . $m . ' ' . $path]);
