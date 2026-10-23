<?php
// ===================================================================
// IMPORTA DA GOLEM LITE (CMS headless)
// Scaffolder Pro scarica il "blueprint" di un'istanza (struttura: lingue, sitemap, modelli, endpoint) e lo salva
// nella root in golem/, con le istruzioni per Claude (GOLEM.md) e un piccolo client PHP per il front.
// I contenuti restano sempre su Golem: il front li legge dagli endpoint, lato server, con la chiave in golem/config.php.
// La connessione è una richiesta della card «Richieste API» (chiave protetta come le altre), legata alla root
// in settings.golem_links (root → id della richiesta).
// Incluso da core.php.
// ===================================================================

const GOLEM_DIR        = 'golem';
const GOLEM_FORMAT_MAX = 1;        // ultima versione del formato del blueprint che Scaffolder Pro conosce
const GOLEM_CTX_MAX    = 120000;   // byte di GOLEM.md + blueprint compatto passati a Claude

/** Id della richiesta API collegata alla root attuale (0 = nessuna). */
function golemLinkId(): int {
    global $rootDir;
    $links = json_decode((string)getSetting('golem_links', '{}'), true);
    $id = is_array($links) ? (int)($links[$rootDir] ?? 0) : 0;
    return $id && httpGetRequest($id) ? $id : 0;
}

function golemSetLink(int $id): void {
    global $rootDir;
    $links = json_decode((string)getSetting('golem_links', '{}'), true);
    if (!is_array($links)) $links = [];
    $links[$rootDir] = $id;
    setSetting('golem_links', json_encode($links, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/**
 * Normalizza il link del blueprint incollato dall'utente: http(s), parametro instance, blueprint=1.
 * Restituisce l'URL oppure ''.
 */
function golemBlueprintUrl(string $url): string {
    $url = trim($url);
    if (!preg_match('#^https?://[^/?\#]+#i', $url)) return '';
    $url = preg_replace('/#.*$/', '', $url);
    $q = [];
    parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
    if (!preg_match('/^[0-9a-f-]{32,36}$/i', (string)($q['instance'] ?? ''))) return '';
    if ((string)($q['blueprint'] ?? '') !== '1') $url .= (strpos($url, '?') === false ? '?' : '&') . 'blueprint=1';
    return $url;
}

/** Stato per la finestra: collegamento, chiave mascherata, blueprint presente nella root. */
function golemStatus(): array {
    global $rootDir;
    $id = golemLinkId();
    $q  = $id ? httpPublicRequest(httpGetRequest($id)) : null;
    $bp = null;
    $file = $rootDir . '/' . GOLEM_DIR . '/blueprint.json';
    if (is_file($file)) {
        $d = json_decode((string)@file_get_contents($file), true);
        if (is_array($d) && ($d['type'] ?? '') === 'blueprint') {
            $bp = [
                'label'        => (string)($d['instance']['label'] ?? ''),
                'uuid'         => (string)($d['instance']['uuid'] ?? ''),
                'langs'        => array_values(array_map(function ($l) { return (string)($l['iso'] ?? ''); }, (array)($d['instance']['langs'] ?? []))),
                'data_version' => (string)($d['data_version'] ?? ''),
                'generated_at' => (string)($d['generated_at'] ?? ''),
                'saved_at'     => date('Y-m-d H:i', (int)filemtime($file)),
            ];
        }
    }
    return [
        'linked'   => $q !== null,
        'url'      => $q ? $q['url'] : '',
        'key_hint' => $q ? $q['auth']['hint'] : '',
        'has_key'  => $q ? $q['auth']['has_value'] : false,
        'request'  => $q ? $q['id'] : 0,
        'insecure' => $q ? !empty($q['options']['insecure']) : false,
        'blueprint' => $bp,
        'writable' => is_writable($rootDir) || is_writable($rootDir . '/' . GOLEM_DIR),
    ];
}

/** Controlla la forma del blueprint. Restituisce un messaggio d'errore o ''. */
function golemValidate($bp): string {
    if (!is_array($bp)) return t('golem.not_json');
    if (($bp['type'] ?? '') !== 'blueprint') return t('golem.not_blueprint');
    $v = $bp['format_version'] ?? null;
    if (!is_int($v) || $v < 1) return t('golem.invalid', ['what' => 'format_version']);
    if ($v > GOLEM_FORMAT_MAX) return t('golem.format_new', ['v' => $v, 'max' => GOLEM_FORMAT_MAX]);
    if (!preg_match('/^[0-9a-f-]{32,36}$/i', (string)($bp['instance']['uuid'] ?? ''))) return t('golem.invalid', ['what' => 'instance.uuid']);
    if (!is_array($bp['instance']['langs'] ?? null) || !$bp['instance']['langs']) return t('golem.invalid', ['what' => 'instance.langs']);
    if (!preg_match('#^https?://#i', (string)($bp['api']['base'] ?? ''))) return t('golem.invalid', ['what' => 'api.base']);
    if (!is_array($bp['sitemaps'] ?? null)) return t('golem.invalid', ['what' => 'sitemaps']);
    if (!is_array($bp['model_groups'] ?? null)) return t('golem.invalid', ['what' => 'model_groups']);
    return '';
}

/** Scarica il blueprint con la richiesta salvata e lo valida: ['bp' => array] oppure ['error' => …]. */
function golemFetch(array $req): array {
    if (!function_exists('curl_init')) return ['error' => t('api.no_curl')];
    $resp = httpRun($req);
    if (isset($resp['error']) && !isset($resp['request_id'])) return ['error' => $resp['error']];
    if ($resp['error']) return ['error' => $resp['error']];
    $data = json_decode((string)$resp['body'], true);
    if ($resp['status'] !== 200) {
        $msg = is_array($data) ? trim((string)($data['error'] ?? '') . (isset($data['code']) ? " ({$data['code']})" : '')) : '';
        return ['error' => t('golem.http_error', ['code' => $resp['status'], 'error' => $msg !== '' ? $msg : '—'])];
    }
    if ($resp['truncated']) return ['error' => t('golem.too_big', ['size' => humanSize(HTTP_MAX_STORE)])];
    $err = golemValidate($data);
    return $err !== '' ? ['error' => $err] : ['bp' => $data];
}

/**
 * Scrive un file in golem/ (versione salvata se cambia). $keep = non toccare un file esistente.
 * Restituisce 'written', 'same', 'kept' oppure un messaggio d'errore.
 */
function golemWrite(string $name, string $content, bool $keep = false): string {
    global $rootDir;
    $rel = cleanRelPath(GOLEM_DIR . '/' . $name);
    if ($rel === '') return t('golem.cannot_write', ['path' => GOLEM_DIR . '/' . $name]);
    $abs = $rootDir . '/' . $rel;
    if (!is_dir(dirname($abs)) && !@mkdir(dirname($abs), 0777, true)) return t('golem.cannot_write', ['path' => GOLEM_DIR . '/']);
    if (is_file($abs)) {
        if ($keep) return 'kept';
        $old = (string)@file_get_contents($abs);
        if ($old === $content) return 'same';
        saveVersion($rel, $old, 'golem');
    }
    if (@file_put_contents($abs, $content) === false) return t('golem.cannot_write', ['path' => $rel]);
    @chmod($abs, 0666);
    return 'written';
}

/** Numero di nodi di tutte le sitemap. */
function golemCountNodes(array $nodes): int {
    $n = 0;
    foreach ($nodes as $node) $n += 1 + golemCountNodes((array)($node['children'] ?? []));
    return $n;
}

/**
 * Importa (con $full = true anche client.php, config.php se manca, .htaccess, .gitignore) oppure
 * aggiorna solo blueprint.json e GOLEM.md. $key = chiave in chiaro (solo per config.php nuovo).
 * Restituisce il riepilogo oppure ['error' => …].
 */
function golemInstall(array $bp, string $key, bool $full, bool $insecure = false): array {
    global $rootDir;
    $files = [
        'blueprint.json' => json_encode($bp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n",
        'GOLEM.md'       => golemMarkdown($bp),
    ];
    if ($full) {
        $files['client.php'] = golemClientPhp();
        $files['.htaccess']  = golemHtaccess();
    }
    $out = ['written' => [], 'config_kept' => false, 'config_other' => false];
    foreach ($files as $name => $content) {
        $r = golemWrite($name, $content);
        if ($r !== 'written' && $r !== 'same') return ['error' => $r];
        if ($r === 'written') $out['written'][] = GOLEM_DIR . '/' . $name;
    }
    if ($full) {
        $cfgFile = $rootDir . '/' . GOLEM_DIR . '/config.php';
        $r = golemWrite('config.php', golemConfigPhp($bp, $key, $insecure), true);
        if ($r === 'kept') {
            $out['config_kept'] = true;
            $out['config_other'] = strpos((string)@file_get_contents($cfgFile), (string)$bp['instance']['uuid']) === false;
        } elseif ($r === 'written') {
            $out['written'][] = GOLEM_DIR . '/config.php';
        } else {
            return ['error' => $r];
        }
        if (golemWrite('.gitignore', "# Created by Scaffolder Pro: the API key and the cache must not end up in a repository\nconfig.php\ncache/\n", true) === 'written') {
            $out['written'][] = GOLEM_DIR . '/.gitignore';
        }
    }
    $nodes = 0;
    foreach ($bp['sitemaps'] as $sm) $nodes += golemCountNodes((array)($sm['nodes'] ?? []));
    $out['label']  = (string)($bp['instance']['label'] ?? '');
    $out['nodes']  = $nodes;
    $out['groups'] = count($bp['model_groups']);
    $out['data_version'] = (string)($bp['data_version'] ?? '');
    return $out;
}

/** Contesto per la chat di Claude se nella root c'è golem/blueprint.json ('' altrimenti). */
function golemClaudeContext(): string {
    global $rootDir;
    $dir = $rootDir . '/' . GOLEM_DIR;
    if (!is_file($dir . '/blueprint.json')) return '';
    $bp = json_decode((string)@file_get_contents($dir . '/blueprint.json'), true);
    if (golemValidate($bp) !== '') return '';
    $md = is_file($dir . '/GOLEM.md') ? (string)@file_get_contents($dir . '/GOLEM.md') : golemMarkdown($bp);
    // Blueprint compatto: senza gli esempi (Claude li legge con leggi_file se servono)
    foreach ($bp['model_groups'] as $k => $g) {
        if (is_array($g) && !empty($g['example'])) $bp['model_groups'][$k]['example'] = '(omitted here: read golem/blueprint.json)';
    }
    $json = json_encode($bp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    $ctx = "\n## Golem Lite (headless CMS): file golem/GOLEM.md\n" . $md;
    if (strlen($ctx) + strlen($json) < GOLEM_CTX_MAX) {
        $ctx .= "\n## Golem Lite: golem/blueprint.json (compact, examples omitted)\n```json\n$json\n```\n";
    } else {
        $ctx .= "\n(golem/blueprint.json is too large for the context: read it with leggi_file.)\n";
    }
    return strlen($ctx) > GOLEM_CTX_MAX * 2 ? substr($ctx, 0, GOLEM_CTX_MAX * 2) . "\n[… truncated]\n" : $ctx;
}

// -------------------------------------------------------------------
// File generati
// -------------------------------------------------------------------

/** Testo di un valore "per lingua" o semplice. */
function golemText($v, string $primary): string {
    if (is_array($v)) return (string)($v[$primary] ?? (reset($v) ?: ''));
    return (string)$v;
}

function golemMdCell($v): string {
    if (is_bool($v)) return $v ? 'yes' : 'no';
    if (is_array($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return str_replace(['|', "\r", "\n"], ['\\|', ' ', ' '], (string)$v);
}

/** Righe dell'albero di una sitemap per GOLEM.md. */
function golemMdNodes(array $nodes, string $primary, int $depth = 0): string {
    $out = '';
    foreach ($nodes as $n) {
        $pad   = str_repeat('  ', $depth);
        $title = golemText($n['title'] ?? '', $primary);
        $links = [];
        foreach ((array)($n['langs'] ?? []) as $iso => $l) $links[] = "$iso `" . (string)($l['permalink'] ?? '') . '`';
        $line = "$pad- **" . golemMdCell($title !== '' ? $title : ($n['slug'] ?? '?')) . '** — ' . (string)($n['kind'] ?? '?')
              . ' — node `' . (string)($n['uuid'] ?? '') . '`' . ($links ? ' — ' . implode(', ', $links) : '');
        if (!empty($n['hidden'])) $line .= ' — **hidden** (not in the API responses)';
        if (!empty($n['page'])) {
            $p = $n['page'];
            $line .= "\n$pad  - page: content `" . ($p['uuid'] ?? 'none') . '`, model group ' . ($p['model_group'] ?? '—')
                   . (empty($p['published']) ? ', **not published**' : '');
        }
        if (!empty($n['category'])) {
            $c = $n['category'];
            $line .= "\n$pad  - category: model group " . ($c['model_group'] ?? '—') . ', ' . (int)($c['articles_public'] ?? 0) . ' public articles'
                   . (!empty($c['order_by']) ? ', order `' . golemMdCell($c['order_by']) . '`' : '')
                   . (!empty($c['also_in']) ? ', articles also in ' . implode(', ', array_map(function ($u) { return "`$u`"; }, (array)$c['also_in'])) : '');
        }
        $out .= $line . "\n" . golemMdNodes((array)($n['children'] ?? []), $primary, $depth + 1);
    }
    return $out;
}

/** GOLEM.md: istruzioni per Claude (in inglese, come il prompt di sistema) + struttura leggibile. */
function golemMarkdown(array $bp): string {
    $inst    = $bp['instance'];
    $primary = (string)($inst['primary_lang'] ?? '');
    $langs   = [];
    foreach ((array)$inst['langs'] as $l) {
        $langs[] = '`' . ($l['iso'] ?? '') . '`' . (!empty($l['locale']) ? ' (' . $l['locale'] . ')' : '') . ' ' . ($l['label'] ?? '') . (!empty($l['primary']) ? ' — primary' : '');
    }
    $api = (array)$bp['api'];
    $md  = "# Golem Lite — front-end guide\n\n"
         . "_Generated by Scaffolder Pro from the Golem Lite blueprint. Do not edit by hand: "
         . "**Update structure** in Scaffolder Pro rewrites this file and `blueprint.json`._\n\n"
         . "- Instance: **" . golemMdCell($inst['label'] ?? '') . "** (`{$inst['uuid']}`)\n"
         . "- Languages: " . implode(', ', $langs) . "\n"
         . "- Data version: " . golemMdCell($bp['data_version'] ?? '') . ' · blueprint generated at ' . golemMdCell($bp['generated_at'] ?? '') . ' · format ' . (int)$bp['format_version'] . "\n"
         . "- API: `" . golemMdCell($api['base'] ?? '') . "` (OpenAPI: `?spec=openapi`)\n\n"
         . "## Rules for the front-end\n\n"
         . "1. **The contents always live on Golem Lite.** Never copy texts, pages or articles into the front's files: "
         . "read them at runtime from the API with `golem/client.php`. `blueprint.json` is only the structure to write the code "
         . "(routes, templates, field names); it is a snapshot and may be older than the real data.\n"
         . "2. **The API key stays on the server.** Call the API only from PHP (`golem()` in `golem/client.php`, which reads "
         . "`golem/config.php`). Never put the key, the API URL with the key, or a direct `fetch` to Golem in HTML or JavaScript. "
         . "If the browser needs data (e.g. \"load more\"), add a small PHP endpoint of the front that calls `golem()` and returns only what is needed.\n"
         . "3. **The front must be portable** to any domain or subfolder without changes: no domain or absolute path written in the code. "
         . "Golem permalinks are relative (`/`, `/{iso}/…`): turn them into links with `golem_url(\$permalink)` (it adds the base path "
         . "found by itself from `\$_SERVER['SCRIPT_NAME']`); assets of the front with relative paths or `golem_url('/assets/…')`. "
         . "Use a front controller (`index.php`) plus an `.htaccess` with `RewriteEngine On`, `RewriteCond %{REQUEST_FILENAME} !-f`, "
         . "`RewriteCond %{REQUEST_FILENAME} !-d`, `RewriteRule ^ index.php [L,QSA]` (no `RewriteBase`), and read the route with `golem_request_path()`.\n"
         . "4. **Media** (images, files, galleries, og_image) are objects with an absolute `url` towards Golem Lite: use them as they are, do not download them.\n"
         . "5. **Languages**: every response has all the languages in `langs.{iso}`; texts of one language with `golem_lang(\$item, \$iso)` "
         . "(falls back to the primary language). The language of a request comes from the permalink that matched (`golem_find_node()`).\n"
         . "6. **Errors**: `golem()` throws `GolemException` (`->status`, `->apiCode`). 404 → page \"not found\" of the front with HTTP 404; "
         . "401/403 → configuration problem (key, scope `content.read`, IP or domain filter of the key): show a generic error, log the detail. "
         . "If Golem is unreachable or answers 5xx/429 the client returns the last cached copy when it has one.\n"
         . "7. **Cache**: the client keeps every response in `golem/cache/` and revalidates it with `If-None-Match` after `cache_ttl` seconds "
         . "(304 = still valid, almost free). Do not add another cache on top unless asked.\n"
         . "8. Never edit `golem/config.php`, `golem/client.php`, `golem/.htaccess` for the site's logic: write the front outside `golem/`.\n\n"
         . "## Which endpoint for each node\n\n"
         . "| kind | what to call | notes |\n|---|---|---|\n"
         . "| `page` | `golem()->sitemap(SITEMAP)` already contains `page` (full content) for every page node; `golem()->content(page.uuid)` for one page alone | fields in `langs.{iso}.content.{model}.{field}` |\n"
         . "| `category` | `golem()->category(node.uuid, \$page, \$limit, \$filters)` | full articles, paginated (`page`, `limit` ≤ 100, `total`, `pages`); filters `q`, `from`, `to`, `order`, `field[key]` |\n"
         . "| `page+category` | both: the page content and its articles | |\n"
         . "| `container` | nothing: it only groups children (menus, sections) | |\n"
         . "| article | `golem()->content(article.uuid)` | the sitemap lists the articles of each category (`articles[{uuid, published_at, langs{iso:{title, permalink}}}]`) |\n\n"
         . "Routing: call `golem()->sitemap(SITEMAP)` (cached), then `golem_find_node(\$sitemap, golem_request_path())` returns "
         . "`['node' => …, 'article' => …|null, 'lang' => iso]` or null (→ 404). Build menus from the same sitemap response, so new pages "
         . "appear without regenerating the front. Hidden nodes and unpublished contents are never in the responses.\n\n";

    $conv = (array)($bp['conventions'] ?? []);
    if ($conv) {
        $md .= "## Conventions of Golem Lite\n\n";
        foreach ($conv as $c) $md .= '- ' . (is_string($c) ? $c : json_encode($c, JSON_UNESCAPED_UNICODE)) . "\n";
        $md .= "\n";
    }

    $md .= "## Sitemaps\n\n";
    foreach ($bp['sitemaps'] as $sm) {
        $md .= '### ' . golemMdCell($sm['label'] ?? '') . ' — sitemap `' . ($sm['uuid'] ?? '') . "`\n\n"
             . golemMdNodes((array)($sm['nodes'] ?? []), $primary) . "\n";
    }

    $md .= "## Model groups\n\nPath of a field: `langs.{iso}.content.{model key}.{field key}`; a repeatable model is a list of rows.\n\n";
    foreach ($bp['model_groups'] as $gid => $g) {
        if (!is_array($g)) continue;
        $md .= '### Group ' . $gid . ' — ' . golemMdCell($g['label'] ?? '') . "\n\n";
        foreach ((array)($g['models'] ?? []) as $m) {
            $md .= '**`' . ($m['key'] ?? '') . '`** ' . golemMdCell($m['label'] ?? '') . (!empty($m['repeatable']) ? ' — repeatable (list of rows)' : '') . "\n\n"
                 . "| field | label | type | value | multilingual | required | notes |\n|---|---|---|---|---|---|---|\n";
            foreach ((array)($m['fields'] ?? []) as $f) {
                $notes = [];
                if (!empty($f['options'])) {
                    $notes[] = 'options: ' . implode(', ', array_map(function ($o) use ($primary) {
                        return '`' . ($o['value'] ?? '') . '`' . (isset($o['labels']) ? ' ' . golemText($o['labels'], $primary) : '');
                    }, (array)$f['options']));
                }
                if (!empty($f['options_from'])) $notes[] = (string)$f['options_from'];
                if (!empty($f['max_char'])) $notes[] = 'max ' . (int)$f['max_char'] . ' chars';
                if (!empty($f['info'])) $notes[] = (string)$f['info'];
                $md .= '| `' . golemMdCell($f['key'] ?? '') . '` | ' . golemMdCell($f['label'] ?? '') . ' | ' . golemMdCell($f['type'] ?? '')
                     . ' | ' . golemMdCell($f['value'] ?? '') . ' | ' . golemMdCell(!empty($f['multilingual'])) . ' | ' . golemMdCell(!empty($f['required']))
                     . ' | ' . golemMdCell(implode('; ', $notes)) . " |\n";
            }
            $md .= "\n";
        }
        if (!empty($g['example']) && is_array($g['example'])) {
            $md .= 'Real example (' . golemMdCell($g['example']['from'] ?? '') . ' of node `' . ($g['example']['node'] ?? '')
                 . "`): `model_groups.$gid.example.data` in `golem/blueprint.json`.\n\n";
        }
    }

    $md .= "## Endpoints (from the blueprint)\n\n";
    foreach ((array)($api['endpoints'] ?? []) as $k => $u) $md .= "- $k: `" . str_replace('%7Buuid%7D', '{uuid}', (string)$u) . "`\n";
    if (!empty($api['caching'])) $md .= "\nCaching: " . golemMdCell($api['caching']) . "\n";
    return $md;
}

/** golem/config.php (creato solo se manca: poi è dell'utente). */
function golemConfigPhp(array $bp, string $key, bool $insecure = false): string {
    return "<?php\n"
         . "// Connection to Golem Lite for this front-end — created by Scaffolder Pro (" . date('Y-m-d H:i') . ").\n"
         . "// Contains the API key: golem/.htaccess denies web access to this folder. Never expose it to the browser.\n"
         . "// \"Update structure\" and a new import do not overwrite this file.\n"
         . "return [\n"
         . "    'api_base'  => " . var_export((string)$bp['api']['base'], true) . ",   // api.php of Golem Lite (also on another domain)\n"
         . "    'instance'  => " . var_export((string)$bp['instance']['uuid'], true) . ",\n"
         . "    'api_key'   => " . var_export($key, true) . ",   // key with scope content.read, no IP/domain filter\n"
         . "    'cache_dir' => __DIR__ . '/cache',   // responses + ETag (created by itself)\n"
         . "    'cache_ttl' => 30,                    // seconds before asking Golem again (with If-None-Match → 304)\n"
         . "    'timeout'   => 10,                    // seconds\n"
         . "    'verify_ssl' => " . ($insecure ? 'false' : 'true') . ",                // false only for local development (self-signed certificate)\n"
         . "];\n";
}

function golemHtaccess(): string {
    return "# Created by Scaffolder Pro: golem/ holds the API key (config.php), the cache and the structure of Golem Lite.\n"
         . "# Nothing here is served to the browser: the front includes client.php from PHP.\n"
         . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
         . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";
}

/** golem/client.php: client del front (PHP 7.2+, curl), riscritto a ogni importazione. */
function golemClientPhp(): string {
    return str_replace('{{DATE}}', date('Y-m-d'), <<<'PHPCODE'
<?php
/**
 * Golem Lite client for this front-end — generated by Scaffolder Pro ({{DATE}}).
 * Calls the Golem Lite headless API from the server (the API key in golem/config.php never reaches the browser),
 * with a file cache and ETag / If-None-Match (304 = the cached copy is still valid). PHP 7.2+ with curl.
 * A new "Import from Golem Lite" rewrites this file (Scaffolder Pro keeps the previous version); "Update structure" does not.
 *
 *   require __DIR__ . '/golem/client.php';
 *   $map  = golem()->sitemap('SITEMAP-UUID');               // tree: pages (with content), categories, articles
 *   $hit  = golem_find_node($map, golem_request_path());     // node of the current URL, or null → 404
 *   $page = golem()->content('CONTENT-UUID');                // one page or article, all languages
 *   $list = golem()->category('NODE-UUID', 1, 20);           // articles of a category, paginated
 *   $txt  = golem_lang($page, $hit['lang']);                 // title, seo, content of one language
 *   echo '<a href="' . htmlspecialchars(golem_url($txt['permalink'])) . '">';
 */

class GolemException extends RuntimeException
{
    /** @var int HTTP status (0 = network or configuration error) */
    public $status;
    /** @var string error code of Golem Lite (instance_not_found, invalid_api_key, scope_denied, …) */
    public $apiCode;

    public function __construct($message, $status = 0, $apiCode = '')
    {
        parent::__construct((string)$message, (int)$status);
        $this->status  = (int)$status;
        $this->apiCode = (string)$apiCode;
    }
}

class GolemClient
{
    private $base;
    private $instance;
    private $key;
    private $cacheDir;
    private $ttl;
    private $timeout;
    private $verifySsl;
    /** @var array headers of the last response from Golem (lowercase name => value) */
    public $lastHeaders = [];
    /** @var string where the last result came from: HIT (cache, Golem not asked), 304, 200, STALE (Golem unreachable: old copy) */
    public $lastSource = '';

    public function __construct(array $cfg)
    {
        $this->base     = (string)(isset($cfg['api_base']) ? $cfg['api_base'] : '');
        $this->instance = (string)(isset($cfg['instance']) ? $cfg['instance'] : '');
        $this->key      = (string)(isset($cfg['api_key']) ? $cfg['api_key'] : '');
        $this->cacheDir = rtrim((string)(isset($cfg['cache_dir']) ? $cfg['cache_dir'] : __DIR__ . '/cache'), '/');
        $this->ttl      = max(0, (int)(isset($cfg['cache_ttl']) ? $cfg['cache_ttl'] : 30));
        $this->timeout  = max(1, (int)(isset($cfg['timeout']) ? $cfg['timeout'] : 10));
        $this->verifySsl = !isset($cfg['verify_ssl']) || $cfg['verify_ssl'] !== false;
        if ($this->base === '' || $this->instance === '') throw new GolemException('golem/config.php: api_base and instance are required');
        if (!function_exists('curl_init')) throw new GolemException('Golem Lite client: the PHP curl extension is required');
    }

    public static function fromConfig($file = null)
    {
        $cfg = require ($file ? $file : __DIR__ . '/config.php');
        if (!is_array($cfg)) throw new GolemException('golem/config.php must return an array');
        return new self($cfg);
    }

    /** One page or article (all languages). $password only for protected contents (never cached on disk). */
    public function content($uuid, $password = null)
    {
        $h = ($password !== null && $password !== '') ? ['X-Content-Password: ' . str_replace(["\r", "\n"], '', (string)$password)] : [];
        return $this->get(['uuid' => (string)$uuid], $h);
    }

    /** Articles of a category node: page from 1, limit 1-100, filters q, from, to, order, field[key]. */
    public function category($uuid, $page = 1, $limit = 50, array $filters = [])
    {
        return $this->get(['category' => (string)$uuid, 'page' => max(1, (int)$page), 'limit' => min(100, max(1, (int)$limit))] + $filters);
    }

    /** Tree of a sitemap: nodes with page content, categories and their article list. */
    public function sitemap($uuid)
    {
        return $this->get(['sitemap' => (string)$uuid]);
    }

    /** Raw call: parameters of api.php besides instance. */
    public function get(array $params, array $headers = [])
    {
        $url = $this->base . (strpos($this->base, '?') === false ? '?' : '&')
             . http_build_query(['instance' => $this->instance] + $params, '', '&', PHP_QUERY_RFC3986);
        $private = $headers !== [];
        $file    = $this->cacheDir . '/' . sha1($url) . '.cache';
        $cached  = $private ? null : $this->cacheRead($file);
        if ($cached !== null && $this->ttl > 0 && time() - $cached['time'] < $this->ttl) {
            $this->lastSource = 'HIT';
            return $cached['data'];
        }
        $send = ['X-Api-Key: ' . str_replace(["\r", "\n"], '', $this->key), 'Accept: application/json'];
        if ($cached !== null && $cached['etag'] !== '') $send[] = 'If-None-Match: ' . $cached['etag'];
        try {
            list($status, $resHeaders, $body) = $this->http($url, array_merge($send, $headers));
        } catch (GolemException $e) {
            if ($cached !== null) { $this->lastSource = 'STALE'; return $cached['data']; }
            throw $e;
        }
        $this->lastHeaders = $resHeaders;
        if ($status === 304 && $cached !== null) {
            $cached['time'] = time();
            $this->cacheWrite($file, $cached);
            $this->lastSource = '304';
            return $cached['data'];
        }
        $data = json_decode($body, true);
        if ($status >= 200 && $status < 300 && is_array($data)) {
            if (!$private) $this->cacheWrite($file, ['time' => time(), 'etag' => isset($resHeaders['etag']) ? $resHeaders['etag'] : '', 'data' => $data]);
            $this->lastSource = (string)$status;
            return $data;
        }
        // Golem in difficoltà (5xx, troppe richieste): meglio una copia vecchia che una pagina d'errore
        if (($status >= 500 || $status === 429) && $cached !== null) {
            $this->lastSource = 'STALE';
            return $cached['data'];
        }
        if ($status === 404 || $status === 410) @unlink($file);
        $msg  = is_array($data) && isset($data['error']) ? (string)$data['error'] : 'HTTP ' . $status;
        $code = is_array($data) && isset($data['code']) ? (string)$data['code'] : '';
        throw new GolemException('Golem Lite: ' . $msg, $status, $code);
    }

    private function http($url, array $headers)
    {
        $resHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeout),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'GolemFront/1.0',
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$resHeaders) {
                $p = explode(':', $line, 2);
                if (stripos($line, 'HTTP/') === 0) $resHeaders = [];
                elseif (count($p) === 2) $resHeaders[strtolower(trim($p[0]))] = trim($p[1]);
                return strlen($line);
            },
        ]);
        if (!$this->verifySsl) {   // self-signed certificate in local development (verify_ssl = false)
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }
        $body   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err    = $body === false ? curl_error($ch) : '';
        if (PHP_VERSION_ID < 80000) curl_close($ch);   // deprecated from PHP 8.5, closed by itself from PHP 8
        if ($body === false) throw new GolemException('Golem Lite unreachable: ' . $err);
        return [$status, $resHeaders, (string)$body];
    }

    private function cacheRead($file)
    {
        if (!is_file($file)) return null;
        $c = json_decode((string)@file_get_contents($file), true);
        return is_array($c) && isset($c['time'], $c['data']) && is_array($c['data'])
            ? ['time' => (int)$c['time'], 'etag' => (string)(isset($c['etag']) ? $c['etag'] : ''), 'data' => $c['data']] : null;
    }

    private function cacheWrite($file, array $entry)
    {
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0775, true)) return;
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false) @rename($tmp, $file);
        else @unlink($tmp);
    }
}

/** The shared client, configured from golem/config.php. */
function golem()
{
    static $client = null;
    if ($client === null) $client = GolemClient::fromConfig();
    return $client;
}

/** Texts of one language of a response, node or article: langs[$iso], else the primary language, else the first one. */
function golem_lang(array $item, $iso = null)
{
    $langs = isset($item['langs']) && is_array($item['langs']) ? $item['langs'] : [];
    if ($iso !== null && isset($langs[$iso])) return $langs[$iso];
    if (isset($item['primary_lang']) && isset($langs[$item['primary_lang']])) return $langs[$item['primary_lang']];
    return $langs ? reset($langs) : [];
}

/** Base path of the front, found from the entry script: '' at the root of the domain, '/sub/folder' in a subfolder. */
function golem_base_path()
{
    $d = str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/'));
    return $d === '.' || $d === '/' ? '' : rtrim($d, '/');
}

/** Link of a relative Golem permalink (or of an asset of the front) under the base path: '/en/about' → '/sub/en/about'. */
function golem_url($permalink)
{
    return golem_base_path() . '/' . ltrim((string)$permalink, '/');
}

/** Path requested by the browser relative to the base path: '/', '/en/about', … */
function golem_request_path()
{
    $p = rawurldecode((string)parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH));
    $b = golem_base_path();
    if ($b !== '' && strpos($p . '/', $b . '/') === 0) $p = substr($p, strlen($b));
    if (substr($p, -10) === '/index.php') $p = substr($p, 0, -9);
    return '/' . ltrim($p, '/');
}

/** Node (or article of a category) of a sitemap response with this permalink: ['node', 'article' (or null), 'lang'] or null. */
function golem_find_node(array $sitemap, $path)
{
    $norm = function ($p) { $p = '/' . trim((string)$p, '/'); return $p; };
    $want = $norm($path);
    $walk = function (array $nodes) use (&$walk, $norm, $want) {
        foreach ($nodes as $n) {
            foreach ((isset($n['langs']) ? (array)$n['langs'] : []) as $iso => $l) {
                if (isset($l['permalink']) && $norm($l['permalink']) === $want) return ['node' => $n, 'article' => null, 'lang' => (string)$iso];
            }
            foreach ((isset($n['articles']) ? (array)$n['articles'] : []) as $a) {
                foreach ((isset($a['langs']) ? (array)$a['langs'] : []) as $iso => $l) {
                    if (isset($l['permalink']) && $norm($l['permalink']) === $want) return ['node' => $n, 'article' => $a, 'lang' => (string)$iso];
                }
            }
            $hit = $walk(isset($n['children']) ? (array)$n['children'] : []);
            if ($hit !== null) return $hit;
        }
        return null;
    };
    return $walk(isset($sitemap['nodes']) ? (array)$sitemap['nodes'] : []);
}

PHPCODE
    );
}
