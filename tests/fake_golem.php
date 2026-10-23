<?php
// Finto api.php di Golem Lite per i test (tests/run.sh lo copia accanto al finto Claude come golem.php).
// Chiave giusta: golem-test-key. blueprint=1 → tests/golem_blueprint.json con __BASE__ = questo URL;
// istanza ...0000 → JSON che non è un blueprint, istanza ...0002 → formato 2. sitemap= e uuid= per provare golem/client.php.
// ETag + If-None-Match → 304, come Golem.
$self = 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];
$out = function ($code, $data) {
    $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $etag = '"42-' . substr(sha1($body), 0, 16) . '"';
    header('Content-Type: application/json; charset=utf-8');
    if ($code === 200) {
        header('ETag: ' . $etag);
        header('X-Golem-Data-Version: 42');
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
    }
    http_response_code($code);
    echo $body;
    exit;
};
if (($_SERVER['HTTP_X_API_KEY'] ?? '') !== 'golem-test-key') $out(401, ['error' => 'Invalid API key', 'code' => 'invalid_api_key']);
$inst = (string)($_GET['instance'] ?? '');
$bp = json_decode(str_replace('__BASE__', $self, (string)file_get_contents(__DIR__ . '/golem_blueprint.json')), true);
if (isset($_GET['blueprint'])) {
    if ($inst === '00000000-0000-0000-0000-000000000000') $out(200, ['type' => 'sitemap', 'nodes' => []]);
    if ($inst === '00000000-0000-0000-0000-000000000002') $out(200, ['format_version' => 2] + $bp);
    $out(200, $bp);
}
if (isset($_GET['sitemap'])) {
    $sm = $bp['sitemaps'][0];
    $art = $bp['model_groups']['2']['example']['data'];
    $sm['nodes'][2]['articles'] = [['uuid' => $art['uuid'], 'published_at' => $art['published_at'],
        'langs' => ['it' => ['title' => 'Primo articolo', 'permalink' => '/blog/primo-articolo'], 'en' => ['title' => 'First post', 'permalink' => '/en/blog/first-post']]]];
    $out(200, ['type' => 'sitemap', 'instance' => $inst, 'primary_lang' => 'it', 'sitemap' => ['uuid' => $sm['uuid'], 'label' => $sm['label']], 'nodes' => $sm['nodes']]);
}
if (isset($_GET['uuid'])) {
    if ($_GET['uuid'] === $bp['model_groups']['1']['example']['data']['uuid']) $out(200, $bp['model_groups']['1']['example']['data']);
    $out(404, ['error' => 'Content not found', 'code' => 'not_found']);
}
$out(400, ['error' => 'Missing target', 'code' => 'missing_target']);
