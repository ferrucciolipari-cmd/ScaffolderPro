<?php
umask(0000);

// Hosting senza estensione mbstring: alternative minime (i nomi restano corretti per testo ASCII)
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $len = null) { return $len === null ? substr($s, $start) : substr($s, $start, $len); }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen($s) { return strlen($s); }
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

// ===================================================================
// CONFIGURAZIONE
// ===================================================================

const ALLOWED_EXT = [
    'php', 'html', 'htm', 'css', 'scss', 'js', 'mjs', 'ts', 'jsx', 'tsx', 'vue',
    'json', 'svg', 'txt', 'md', 'xml', 'sql', 'yml', 'yaml', 'ini', 'sh', 'env', 'htaccess',
];
const ALLOWED_DOTFILES  = ['.htaccess', '.env', '.gitignore', '.editorconfig'];
// File binari: visibili nell'Explorer, scaricabili, spostabili, ma non modificabili nell'editor
const IMAGE_EXT         = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'bmp', 'avif'];
const BINARY_EXT        = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'bmp', 'avif', 'tif', 'tiff', 'heic', 'psd', 'pdf',
                           'zip', 'gz', 'tgz', 'tar', 'bz2', 'xz', '7z', 'rar', 'woff', 'woff2', 'ttf', 'otf', 'eot',
                           'mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'mp4', 'm4v', 'mov', 'avi', 'mkv', 'webm', 'ogv',
                           'csv', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'sqlite', 'db', 'iso', 'dmg'];
const MAX_EDIT_SIZE     = 2 * 1048576;
const CLAUDE_MODELS     = ['claude-opus-5-5' => 'Claude Opus 5.5', 'claude-sonnet-5-5' => 'Claude Sonnet 5.5',
                           'claude-haiku-5-5' => 'Claude Haiku 5.5', 'claude-fable-5-1' => 'Claude Fable 5.1'];
const EXCLUDED_DIRS     = ['.git', '.idea', '.vscode', 'node_modules', 'vendor', '.backups'];
const MAX_LOGS          = 200;
const MAX_VERSIONS      = 30;
const MAX_RECENT_ROOTS  = 8;

$selfDir     = realpath(__DIR__);
$defaultRoot = realpath(dirname(__DIR__));

importLegacyBackups($defaultRoot);

// ===================================================================
// ROOT DI LAVORO (salvata in SQLite)
// ===================================================================

/** Cartella che contiene un'installazione di Scaffolder Pro (o di una sua versione precedente: TreeForge, Scaffolder): non può essere una root. */
function isToolDir(string $abs): bool {
    foreach (['api.php', 'core.php', 'index.php', 'app.js'] as $f) {
        if (!is_file($abs . '/' . $f)) return false;
    }
    return true;
}

/** La cartella è un'installazione di Scaffolder Pro o sta dentro una di esse. */
function insideToolDir(string $real): bool {
    for ($d = $real; $d !== '/' && $d !== '.' && $d !== ''; $d = dirname($d)) {
        if (isToolDir($d)) return true;
    }
    return false;
}

/** Valida una cartella candidata come root. Restituisce il realpath o null. */
function validateRoot(string $path): ?string {
    global $selfDir;
    $path = trim($path);
    if ($path === '' || $path[0] !== '/') return null;
    $real = realpath($path);
    if ($real === false || !is_dir($real) || $real === '/') return null;
    // Non si può lavorare dentro Scaffolder Pro stesso
    if ($real === $selfDir || strpos($real . '/', $selfDir . '/') === 0) return null;
    // ...né dentro altre copie (anche vecchie) di Scaffolder Pro (es. una copia di backup in un progetto)
    if (insideToolDir($real)) return null;
    return $real;
}

function getRecentRoots(): array {
    $list = json_decode((string)getSetting('recent_roots', '[]'), true);
    return is_array($list) ? array_values(array_filter($list, function ($d) { return is_string($d) && validateRoot($d) !== null; })) : [];
}

/**
 * Dati di una cartella per il selettore della root: permessi, installazione di Scaffolder Pro, numero di
 * sottocartelle e file. Restituisce [info, nomi delle sottocartelle da esplorare (niente symlink)].
 * $checkUp: controlla anche se sta DENTRO un'installazione (serve solo per le cartelle di partenza).
 */
function workspaceDirInfo(string $real, string $group, int $depth, string $base, bool $checkUp): array {
    global $rootDir, $selfDir;
    $tool = $real === $selfDir || isToolDir($real) || ($checkUp && insideToolDir($real));
    $readable = @is_readable($real);
    $info = [
        'path' => $real, 'name' => basename($real) ?: $real, 'group' => $group, 'depth' => $depth,
        'rel' => $base !== '' && strpos($real . '/', $base . '/') === 0 ? substr($real, strlen($base)) : $real,
        'readable' => $readable, 'writable' => !$tool && @is_writable($real), 'tool' => $tool,
        'current' => $real === $rootDir, 'dirs_n' => 0, 'files_n' => 0, 'scanned' => false,
    ];
    $subs = [];
    if ($readable && !$tool) {
        foreach (@scandir($real) ?: [] as $e) {
            if ($e === '.' || $e === '..' || isExcludedName($e)) continue;
            $full = $real . '/' . $e;
            if (is_dir($full)) {
                $info['dirs_n']++;
                if (!is_link($full)) $subs[] = $e;
            } else {
                $info['files_n']++;
            }
        }
        natcasesort($subs);
    }
    return [$info, array_values($subs)];
}

/**
 * Cartelle che si possono scegliere come root, per il selettore dell'intestazione: l'albero dello spazio web
 * (la cartella che contiene Scaffolder Pro, es. htdocs) e quello della root attuale se sta altrove, il livello superiore
 * e le cartelle recenti. Le cartelle oltre $maxDepth (o oltre $maxDirs) hanno scanned = false: il browser
 * ne chiede le sottocartelle quando le apre (rootChildren).
 */
function scanWorkspaceDirs(int $maxDepth = 5, int $maxDirs = 2500): array {
    global $defaultRoot, $rootDir;
    $items = [];
    $seen = [];
    $truncated = false;

    $walk = function (string $dir, int $depth, string $base) use (&$walk, &$items, &$seen, &$truncated, $maxDepth, $maxDirs) {
        if (isset($seen[$dir])) return;
        if (count($items) >= $maxDirs) { $truncated = true; return; }
        $seen[$dir] = true;
        // Nelle sottocartelle basta isToolDir: dentro un'installazione di Scaffolder Pro non si scende
        [$info, $subs] = workspaceDirInfo($dir, 'workspace', $depth, $base, $depth === 0);
        $i = count($items);
        $items[] = $info;
        if ($depth >= $maxDepth) return;
        $items[$i]['scanned'] = true;
        foreach ($subs as $e) {
            if (count($items) >= $maxDirs) { $truncated = true; $items[$i]['scanned'] = false; return; }
            $walk($dir . '/' . $e, $depth + 1, $base);
        }
    };

    // Livello superiore dello spazio web (se è una root valida)
    $up = dirname($defaultRoot);
    if ($up !== $defaultRoot && validateRoot($up) !== null) { $items[] = workspaceDirInfo($up, 'up', 0, '', true)[0]; $seen[$up] = true; }
    $walk($defaultRoot, 0, $defaultRoot);
    // Root attuale fuori dallo spazio web (o non raggiunta per la profondità): anche il suo albero
    if (!isset($seen[$rootDir])) $walk($rootDir, 0, dirname($rootDir));
    foreach (getRecentRoots() as $rec) {
        if (!isset($seen[$rec])) { $items[] = workspaceDirInfo($rec, 'recent', 0, '', true)[0]; $seen[$rec] = true; }
    }
    return ['items' => $items, 'truncated' => $truncated];
}

/** Sottocartelle dirette di una cartella (apertura di un ramo non ancora letto nel selettore della root). */
function rootChildren(string $path, int $depth, string $base): ?array {
    $real = realpath($path);
    if ($real === false || !is_dir($real) || $real === '/') return null;
    [$info, $subs] = workspaceDirInfo($real, 'workspace', $depth, $base, true);
    if ($info['tool'] || !$info['readable']) return [];
    $out = [];
    foreach (array_slice($subs, 0, 1000) as $e) $out[] = workspaceDirInfo($real . '/' . $e, 'workspace', $depth + 1, $base, false)[0];
    return $out;
}

function setRootDir(string $real): void {
    setSetting('root_dir', $real);
    $recent = array_values(array_diff(getRecentRoots(), [$real]));
    array_unshift($recent, $real);
    setSetting('recent_roots', json_encode(array_slice($recent, 0, MAX_RECENT_ROOTS)));
}

$rootDir = validateRoot((string)getSetting('root_dir', ''))
    ?? validateRoot((string)loadConfig()['default_root'])
    ?? $defaultRoot;
if ($rootDir === false || $rootDir === null) {
    die(htmlspecialchars(t('err.invalid_root')));
}

// Nome della cartella di Scaffolder Pro relativo alla root (se ci sta dentro), da nascondere
$selfRelInRoot = (strpos($selfDir . '/', $rootDir . '/') === 0) ? substr($selfDir, strlen($rootDir) + 1) : null;

// ===================================================================
// REGISTRO MOVIMENTI
// ===================================================================

function addLog(string $type, string $text): void {
    try {
        db()->prepare('INSERT INTO logs (type, text) VALUES (?, ?)')->execute([$type, $text]);
        db()->exec('DELETE FROM logs WHERE id NOT IN (SELECT id FROM logs ORDER BY id DESC LIMIT ' . MAX_LOGS . ')');
    } catch (Throwable $e) {
        // il log non deve mai bloccare l'azione principale
    }
}

function getLogs(int $limit = 80): array {
    $st = db()->prepare('SELECT id, created_at, type, text FROM logs ORDER BY id DESC LIMIT ?');
    $st->bindValue(1, $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

// ===================================================================
// VERSIONI DEI FILE (sostituiscono i vecchi .bak)
// ===================================================================

function saveVersion(string $relPath, string $content, string $reason): void {
    global $rootDir;
    $st = db()->prepare('INSERT INTO versions (root, path, content, reason) VALUES (?, ?, ?, ?)');
    $st->bindValue(1, $rootDir);
    $st->bindValue(2, $relPath);
    $st->bindValue(3, $content, PDO::PARAM_LOB);
    $st->bindValue(4, $reason);
    $st->execute();

    $st = db()->prepare('DELETE FROM versions WHERE root = ? AND path = ? AND id NOT IN (
        SELECT id FROM versions WHERE root = ? AND path = ? ORDER BY id DESC LIMIT ' . MAX_VERSIONS . ')');
    $st->execute([$rootDir, $relPath, $rootDir, $relPath]);
}

function getVersions(string $relPath): array {
    global $rootDir;
    $st = db()->prepare('SELECT id, reason, created_at, length(content) AS size
                         FROM versions WHERE root = ? AND path = ? ORDER BY id DESC');
    $st->execute([$rootDir, $relPath]);
    return $st->fetchAll();
}

function getVersion(int $id): ?array {
    global $rootDir;
    $st = db()->prepare('SELECT * FROM versions WHERE id = ? AND root = ?');
    $st->execute([$id, $rootDir]);
    $row = $st->fetch();
    if (!$row) return null;
    if (is_resource($row['content'])) $row['content'] = stream_get_contents($row['content']);
    return $row;
}

/** Ultima versione "eliminata" di ogni file che oggi non esiste più (cestino). */
function getTrash(): array {
    global $rootDir;
    $st = db()->prepare("SELECT v.id, v.path, v.created_at FROM versions v
        WHERE v.root = ? AND v.reason = 'delete'
          AND v.id = (SELECT MAX(id) FROM versions WHERE root = v.root AND path = v.path AND reason = 'delete')
        ORDER BY v.id DESC LIMIT 40");
    $st->execute([$rootDir]);
    return array_values(array_filter($st->fetchAll(), function ($r) use ($rootDir) { return !file_exists($rootDir . '/' . $r['path']); }));
}

// ===================================================================
// SANIFICAZIONE E PREVENZIONE PATH TRAVERSAL
// ===================================================================

function cleanRelPath($path): string {
    global $rootDir, $selfRelInRoot;

    $path  = str_replace(["\0", '\\'], ['', '/'], trim((string)$path));
    $parts = [];
    foreach (explode('/', $path) as $segment) {
        $segment = trim($segment);
        if ($segment === '' || $segment === '.' || $segment === '..') continue;
        $parts[] = $segment;
    }
    $clean = implode('/', $parts);
    if ($clean === '') return '';

    // Impedisce di toccare la cartella di Scaffolder Pro stesso
    if ($selfRelInRoot !== null && ($clean === $selfRelInRoot || strpos($clean . '/', $selfRelInRoot . '/') === 0)) {
        return '';
    }

    // Il percorso (o il suo primo antenato esistente) deve ricadere sotto la root
    $probe = $rootDir . '/' . $clean;
    while (!file_exists($probe) && $probe !== $rootDir) {
        $probe = dirname($probe);
    }
    $real = realpath($probe);
    if ($real === false || ($real !== $rootDir && strpos($real . '/', $rootDir . '/') !== 0)) {
        return '';
    }

    return $clean;
}

function isAllowedFile(string $relPath): bool {
    $base = basename($relPath);
    if (in_array($base, ALLOWED_DOTFILES, true)) return true;
    $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
    return $ext !== '' && in_array($ext, ALLOWED_EXT, true);
}

function isBinaryFile(string $relPath): bool {
    return in_array(strtolower(pathinfo($relPath, PATHINFO_EXTENSION)), BINARY_EXT, true);
}

function isImageFile(string $relPath): bool {
    $ext = strtolower(pathinfo($relPath, PATHINFO_EXTENSION));
    return $ext === 'svg' || in_array($ext, IMAGE_EXT, true);
}

/** File mostrati nell'Explorer: testo modificabile + binari noti. */
function isListedFile(string $relPath): bool {
    return isAllowedFile($relPath) || isBinaryFile($relPath);
}

function isExcludedName(string $name): bool {
    return $name === '.DS_Store' || in_array($name, EXCLUDED_DIRS, true);
}

// ===================================================================
// ALBERO DEL PROGETTO
// ===================================================================

function scanProjectTree(string $rootDir): array {
    global $selfRelInRoot;
    $dirs  = [''];
    $files = [];
    if (!is_dir($rootDir)) return ['dirs' => $dirs, 'files' => $files];

    $dirIterator = new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS);
    $filter = new RecursiveCallbackFilterIterator($dirIterator, function ($current) use ($rootDir, $selfRelInRoot) {
        $rel = str_replace('\\', '/', substr($current->getPathname(), strlen($rootDir) + 1));
        if (isExcludedName($current->getFilename())) return false;
        if ($selfRelInRoot !== null && ($rel === $selfRelInRoot || strpos($rel . '/', $selfRelInRoot . '/') === 0)) return false;
        if ($current->isLink() && $current->isDir()) return false; // evita cicli
        return $current->isReadable();
    });

    $iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD);
    foreach ($iterator as $item) {
        $rel = str_replace('\\', '/', substr($item->getPathname(), strlen($rootDir) + 1));
        if ($item->isDir()) {
            $dirs[] = $rel;
        } elseif ($item->isFile() && isListedFile($rel)) {
            $files[] = $rel;
        }
    }

    natcasesort($dirs);
    natcasesort($files);
    return ['dirs' => array_values($dirs), 'files' => array_values($files)];
}

function buildNestedTree(array $dirs, array $files): array {
    $tree = ['_dirs' => [], '_files' => []];
    foreach ($dirs as $d) {
        if ($d === '') continue;
        $curr = &$tree;
        foreach (explode('/', $d) as $p) {
            if (!isset($curr['_dirs'][$p])) $curr['_dirs'][$p] = ['_dirs' => [], '_files' => []];
            $curr = &$curr['_dirs'][$p];
        }
        unset($curr);
    }
    foreach ($files as $f) {
        $parts    = explode('/', $f);
        $fileName = array_pop($parts);
        $curr     = &$tree;
        foreach ($parts as $p) {
            if (!isset($curr['_dirs'][$p])) $curr['_dirs'][$p] = ['_dirs' => [], '_files' => []];
            $curr = &$curr['_dirs'][$p];
        }
        $curr['_files'][$fileName] = $f;
        unset($curr);
    }
    sortTree($tree);
    return $tree;
}

function sortTree(array &$node): void {
    uksort($node['_dirs'], 'strnatcasecmp');
    uksort($node['_files'], 'strnatcasecmp');
    foreach ($node['_dirs'] as &$sub) sortTree($sub);
}

function deleteDirRecursive(string $dirPath): bool {
    global $rootDir;
    $realDir = realpath($dirPath);
    if ($realDir === false || $realDir === $rootDir || strpos($realDir . '/', $rootDir . '/') !== 0) {
        return false;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($realDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $p = $item->getPathname();
        ($item->isDir() && !$item->isLink()) ? @rmdir($p) : @unlink($p);
    }
    return @rmdir($realDir);
}

/** Copia un file o una cartella (ricorsiva). Salta symlink, .DS_Store e la cartella di Scaffolder Pro. */
function copyRecursive(string $src, string $dst): bool {
    global $selfDir;
    if (is_link($src)) return true;
    if (is_file($src)) {
        if (!@copy($src, $dst)) return false;
        @chmod($dst, 0666);
        return true;
    }
    $real = realpath($src);
    if ($real === false || $real === $selfDir || isToolDir($real)) return true;
    if (!is_dir($dst) && !@mkdir($dst, 0777, true)) return false;
    $ok = true;
    foreach (@scandir($src) ?: [] as $item) {
        if ($item === '.' || $item === '..' || $item === '.DS_Store') continue;
        $ok = copyRecursive($src . '/' . $item, $dst . '/' . $item) && $ok;
    }
    return $ok;
}

function aceModeFor(string $relPath): string {
    $base = basename($relPath);
    if ($base === '.htaccess') return 'ace/mode/apache_conf';
    $modes = [
        'php' => 'php', 'html' => 'html', 'htm' => 'html', 'css' => 'css', 'scss' => 'scss',
        'js' => 'javascript', 'mjs' => 'javascript', 'jsx' => 'javascript', 'ts' => 'typescript', 'tsx' => 'typescript',
        'vue' => 'html', 'json' => 'json', 'svg' => 'xml', 'xml' => 'xml', 'md' => 'markdown', 'sql' => 'sql',
        'yml' => 'yaml', 'yaml' => 'yaml', 'ini' => 'ini', 'env' => 'ini', 'sh' => 'sh',
    ];
    $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
    return 'ace/mode/' . ($modes[$ext] ?? 'text');
}

// ===================================================================
// CREAZIONE FILE CON TEMPLATE
// ===================================================================

function fileTemplate(string $relFile): string {
    $ext  = strtolower(pathinfo($relFile, PATHINFO_EXTENSION));
    $base = basename($relFile);
    switch ($ext) {
        case 'php':  return "<?php\n/**\n * File: $relFile\n */\n";
        case 'html': return "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <title>$base</title>\n</head>\n<body>\n    \n</body>\n</html>\n";
        case 'css':  return "/* Styles: $relFile */\n";
        case 'js':   return "// Script: $relFile\ndocument.addEventListener('DOMContentLoaded', () => {\n    \n});\n";
        case 'json': return "{\n}\n";
        case 'md':   return '# ' . pathinfo($base, PATHINFO_FILENAME) . "\n";
        default:     return '';
    }
}

/** Crea un file vuoto (con template) se non esiste. Restituisce 'created', 'exists' o un messaggio d'errore. */
function createFileWithTemplate(string $relFile): string {
    global $rootDir;
    if (!isAllowedFile($relFile)) return t('err.ext_not_allowed');
    $fullPath = $rootDir . '/' . $relFile;
    if (file_exists($fullPath)) return 'exists';
    if (!is_dir(dirname($fullPath)) && !@mkdir(dirname($fullPath), 0777, true)) return t('err.no_permission');
    if (@file_put_contents($fullPath, fileTemplate($relFile)) === false) return t('err.no_permission');
    @chmod($fullPath, 0666);
    return 'created';
}

// ===================================================================
// CONFIGURAZIONE GENERALE (data/config.json, non servito via web)
// ===================================================================

function configDefaults(): array {
    return [
        'language'      => 'en',    // lingua dell'interfaccia (vedi LANGUAGES in i18n.php)
        'dev_name'      => '',
        'environment'   => '',
        'default_root'  => '',
        'api_key'       => '',
        'claude_model'  => 'claude-opus-5-5',
        'claude_effort' => 'medium',
        'editor_font'   => 14,
        'editor_wrap'   => true,
        'show_right'    => true,
        'backup_skip'   => 'node_modules,.git,vendor',
        'php_lint'      => true,    // controlla la sintassi dei .php prima di salvarli
        'php_lint_live' => true,    // e anche mentre si scrive (solo annotazioni)
        'claude_apply_save' => false, // proposte di Claude: false = nell'editor da salvare, true = salvate subito
        'error_log_path' => '',     // log degli errori PHP aggiuntivo (es. quello del dominio su Plesk)
        'upload_max_gb' => 5,       // dimensione massima di un singolo file caricato (a pezzi)
        'theme'         => 'dark',  // tema dell'interfaccia: dark | light | auto (segue il sistema)
        'minimap'       => true,    // minimappa a destra dell'editor
        'format_on_save' => false,  // formatta il documento (Prettier) prima di salvarlo
        'emmet'         => true,    // abbreviazioni Emmet con Tab in HTML/PHP/CSS
        // Scheda Configurazioni: ambiente di lavoro (la scheda Database Manager entra in MySQL con questi dati)
        'db_host'       => 'localhost',
        'db_port'       => '',
        'db_user'       => 'root',
        'db_pass'       => '',
        'db_name'       => '',      // database aperto all'avvio di Adminer (vuoto = elenco dei database)
        'github_user'   => '',
        'github_token'  => '',
        'mcp_keys'      => [],      // [{name, url, key}]: chiavi dei server MCP
    ];
}

function loadConfig(bool $reload = false): array {
    static $cfg = null;
    if ($cfg !== null && !$reload) return $cfg;
    $data = is_file(CONFIG_FILE) ? json_decode((string)@file_get_contents(CONFIG_FILE), true) : null;
    $cfg = array_merge(configDefaults(), is_array($data) ? $data : []);
    return $cfg;
}

function saveConfig(array $cfg): bool {
    $ok = @file_put_contents(CONFIG_FILE, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false;
    if ($ok) {
        @chmod(CONFIG_FILE, 0666);
        loadConfig(true);
    }
    return $ok;
}

/** Configurazione da mandare al browser: la API key non lascia mai il server. */
function publicConfig(): array {
    $cfg = loadConfig();
    $key = (string)$cfg['api_key'];
    $dbPass = (string)$cfg['db_pass'];
    $ghToken = (string)$cfg['github_token'];
    $mcp = is_array($cfg['mcp_keys']) ? $cfg['mcp_keys'] : [];
    unset($cfg['api_key'], $cfg['db_pass'], $cfg['github_token'], $cfg['mcp_keys']);
    $cfg['has_api_key'] = $key !== '';
    $cfg['api_key_hint'] = $key !== '' ? '••••' . substr($key, -4) : '';
    $cfg['has_db_pass'] = $dbPass !== '';
    $cfg['has_github_token'] = $ghToken !== '';
    $cfg['github_token_hint'] = $ghToken !== '' ? '••••' . substr($ghToken, -4) : '';
    // Le chiavi dei server MCP, come la API key, non lasciano mai il server: al browser arrivano solo nome, indirizzo e ultimi 4 caratteri
    $cfg['mcp'] = array_values(array_map(function ($m) {
        $k = (string)($m['key'] ?? '');
        return ['name' => (string)($m['name'] ?? ''), 'url' => (string)($m['url'] ?? ''), 'has_key' => $k !== '', 'key_hint' => $k !== '' ? '••••' . substr($k, -4) : ''];
    }, array_filter($mcp, 'is_array')));
    return $cfg;
}

// ===================================================================
// PUBBLICAZIONE SU GITHUB (API REST, senza il programma git)
// ===================================================================

const GH_MAX_FILES = 1500;
const GH_MAX_FILE  = 10 * 1048576;     // un file più grande non viene inviato
const GH_MAX_TOTAL = 80 * 1048576;
/** Mai pubblicati: segreti e file di lavoro. */
const GH_NEVER = ['.htpasswd', '.env', '.DS_Store'];

const GITHUB_API_URL = 'https://api.github.com';   // i test lo puntano a un finto server (tests/fake_github.php)
function ghBase(): string { return GITHUB_API_URL; }

/** Chiamata all'API di GitHub con il token salvato. Restituisce [codice HTTP, JSON decodificato]. */
function ghApi(string $method, string $path, ?array $body = null): array {
    $ch = curl_init(ghBase() . $path);
    curl_setopt_array($ch, ghCurlOpts($method, $body));
    $out = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($out === false) return [0, ['message' => $err ?: 'network error']];
    return [$code, json_decode((string)$out, true) ?: []];
}

function ghCurlOpts(string $method, ?array $body): array {
    $headers = ['Accept: application/vnd.github+json', 'User-Agent: ScaffolderPro', 'X-GitHub-Api-Version: 2022-11-28',
                'Authorization: Bearer ' . loadConfig()['github_token']];
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_CUSTOMREQUEST => $method];
    if ($body !== null) { $o[CURLOPT_POSTFIELDS] = json_encode($body); $headers[] = 'Content-Type: application/json'; }
    $o[CURLOPT_HTTPHEADER] = $headers;
    return $o;
}

function ghError(array $j, int $code): string {
    return (string)($j['message'] ?? ('HTTP ' . $code));
}

/** File della cartella da pubblicare: [percorso relativo => percorso assoluto] + elenco dei saltati. */
function ghCollectFiles(string $absDir, array $skipNames): array {
    global $selfDir;
    $skip = array_merge(['.git'], $skipNames);
    $files = []; $skipped = []; $total = 0;
    $filter = new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($absDir, FilesystemIterator::SKIP_DOTS),
        function ($cur) use ($skip, $selfDir) {
            if (in_array($cur->getFilename(), $skip, true)) return false;
            $real = $cur->getRealPath();
            if ($real !== false && ($real === $selfDir || strpos($real . '/', $selfDir . '/') === 0)) return false;
            return $cur->isReadable() && !$cur->isLink();
        }
    );
    foreach (new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD) as $f) {
        if (!$f->isFile()) continue;
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($absDir) + 1));
        $name = $f->getFilename();
        if (in_array($name, GH_NEVER, true) || preg_match('/\.(sqlite|sqlite-wal|sqlite-shm)$/i', $name)) { $skipped[] = $rel; continue; }
        $size = $f->getSize();
        if ($size > GH_MAX_FILE) { $skipped[] = $rel; continue; }
        $files[$rel] = $f->getPathname();
        $total += $size;
    }
    ksort($files);
    return ['files' => $files, 'skipped' => $skipped, 'bytes' => $total];
}

/** Carica i file come blob (a gruppi in parallelo) e restituisce le voci dell'albero Git. */
function ghUploadBlobs(string $owner, string $repo, array $files): array {
    $entries = []; $keys = array_keys($files); $i = 0; $n = count($keys);
    while ($i < $n) {
        $mh = curl_multi_init(); $handles = []; $bytes = 0;
        while ($i < $n && count($handles) < 8 && ($bytes < 6 * 1048576 || !$handles)) {
            $rel = $keys[$i++];
            $data = (string)@file_get_contents($files[$rel]);
            $bytes += strlen($data);
            $ch = curl_init(ghBase() . "/repos/$owner/$repo/git/blobs");
            curl_setopt_array($ch, ghCurlOpts('POST', ['content' => base64_encode($data), 'encoding' => 'base64']));
            curl_multi_add_handle($mh, $ch);
            $handles[$rel] = $ch;
            $mode = is_executable($files[$rel]) ? '100755' : '100644';
            $entries[$rel] = ['path' => $rel, 'mode' => $mode, 'type' => 'blob', 'sha' => null];
        }
        do { $st = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); } while ($running && $st === CURLM_OK);
        foreach ($handles as $rel => $ch) {
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $j = json_decode((string)curl_multi_getcontent($ch), true) ?: [];
            curl_multi_remove_handle($mh, $ch); curl_close($ch);
            if ($code !== 201 || empty($j['sha'])) return ['error' => t('gh.upload_failed', ['file' => $rel, 'error' => ghError($j, $code)])];
            $entries[$rel]['sha'] = $j['sha'];
        }
        curl_multi_close($mh);
    }
    return ['entries' => array_values($entries)];
}

/** Indirizzo del server MySQL per Adminer e mysqli: "host" oppure "host:porta". */
function dbServerString(array $cfg): string {
    $host = trim((string)$cfg['db_host']) ?: 'localhost';
    $port = trim((string)$cfg['db_port']);
    return $port !== '' ? $host . ':' . $port : $host;
}

function serverLimits(): array {
    return [
        'upload_max_filesize' => (string)ini_get('upload_max_filesize'),
        'post_max_size'       => (string)ini_get('post_max_size'),
        'max_execution_time'  => (string)ini_get('max_execution_time'),
        'memory_limit'        => (string)ini_get('memory_limit'),
        'max_file_uploads'    => (string)ini_get('max_file_uploads'),
        'upload_bytes'        => iniBytes((string)ini_get('upload_max_filesize')),
        'post_bytes'          => iniBytes((string)ini_get('post_max_size')),
        'chunk_bytes'         => uploadChunkInfo()['chunk'],
        'chunk_above'         => uploadChunkInfo()['above'],
        'upload_max'          => uploadMaxBytes(),
        'disk_free'           => ($f = @disk_free_space($GLOBALS['rootDir'])) === false ? null : (int)$f,
        'zip'                 => class_exists('ZipArchive'),
    ];
}

function iniBytes(string $v): int {
    $v = trim($v);
    $n = (int)$v;
    $units = ['g' => 1073741824, 'm' => 1048576, 'k' => 1024];
    return $n * ($units[strtolower(substr($v, -1))] ?? 1);
}

// ===================================================================
// ZIP: archivio di una cartella / estrazione sicura
// ===================================================================

/** Crea uno ZIP di $absDir (saltando Scaffolder Pro stesso e le cartelle escluse). Restituisce il numero di file. */
function zipDirectory(string $absDir, string $zipPath, array $skipNames): int {
    global $selfDir;
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return -1;
    $skip = array_merge(['.DS_Store'], $skipNames);
    $filter = new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($absDir, FilesystemIterator::SKIP_DOTS),
        function ($current) use ($skip, $selfDir) {
            if (in_array($current->getFilename(), $skip, true)) return false;
            $real = $current->getRealPath();
            if ($real !== false && ($real === $selfDir || strpos($real . '/', $selfDir . '/') === 0)) return false;
            return $current->isReadable() && !$current->isLink();
        }
    );
    $count = 0;
    foreach (new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD) as $f) {
        $rel = substr($f->getPathname(), strlen($absDir) + 1);
        if ($f->isDir()) {
            $zip->addEmptyDir($rel);
        } elseif ($f->isFile()) {
            $zip->addFile($f->getPathname(), $rel);
            $count++;
        }
    }
    $zip->close();
    return $count;
}

/** Aggiunge file e cartelle (percorsi relativi alla root) a un nuovo ZIP, ognuno con il proprio nome come radice. */
function zipPaths(array $rels, string $zipPath): int {
    global $rootDir, $selfDir;
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return -1;
    $count = 0;
    foreach ($rels as $rel) {
        $abs = $rootDir . '/' . $rel;
        $base = basename($rel);
        if (is_file($abs)) {
            $zip->addFile($abs, $base);
            $count++;
            continue;
        }
        if (!is_dir($abs)) continue;
        $zip->addEmptyDir($base);
        $filter = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS),
            function ($current) use ($selfDir) {
                if ($current->getFilename() === '.DS_Store' || $current->isLink()) return false;
                $real = $current->getRealPath();
                return $real !== false && $real !== $selfDir && strpos($real . '/', $selfDir . '/') !== 0 && $current->isReadable();
            }
        );
        foreach (new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD) as $f) {
            $inner = $base . '/' . substr($f->getPathname(), strlen($abs) + 1);
            if ($f->isDir()) {
                $zip->addEmptyDir($inner);
            } elseif ($f->isFile()) {
                $zip->addFile($f->getPathname(), $inner);
                $count++;
            }
        }
    }
    $zip->close();
    return $count;
}

/** Primo nome libero nella cartella: "nome.zip", "nome 2.zip", ... */
function uniqueName(string $dirRel, string $stem, string $ext): string {
    global $rootDir;
    $prefix = $dirRel === '' ? '' : $dirRel . '/';
    $name = $stem . $ext;
    for ($i = 2; file_exists($rootDir . '/' . $prefix . $name); $i++) $name = "$stem $i$ext";
    return $prefix . $name;
}

/**
 * Estrae uno ZIP dentro $targetRel controllando ogni voce (niente "../", path assoluti, symlink).
 * Restituisce ['files' => n, 'dirs' => n, 'skipped' => [...], 'overwritten' => n].
 */
function extractZipSafe(string $zipFile, string $targetRel, bool $overwrite): array {
    $res = ['files' => 0, 'dirs' => 0, 'skipped' => [], 'overwritten' => 0];
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        $res['skipped'][] = t('zip.invalid');
        return $res;
    }
    extractZipEntries($zip, $targetRel, $overwrite, 0, INF, $res);
    $zip->close();
    return $res;
}

/**
 * Estrae le voci di uno ZIP già aperto a partire da $from, finché c'è tempo (microtime < $deadline).
 * Aggiorna $res e restituisce l'indice della prossima voce da estrarre (= numFiles quando ha finito).
 */
function extractZipEntries(ZipArchive $zip, string $targetRel, bool $overwrite, int $from, float $deadline, array &$res): int {
    global $rootDir;
    for ($i = $from; $i < $zip->numFiles; $i++) {
        if ($i > $from && microtime(true) >= $deadline) return $i;
        $stat = $zip->statIndex($i);
        $name = str_replace('\\', '/', (string)$stat['name']);
        if ($name === '' || strpos($name, "\0") !== false || $name[0] === '/' || preg_match('#(^|/)\.\.(/|$)#', $name)
            || strpos($name, '__MACOSX/') === 0 || basename($name) === '.DS_Store') {
            if (strpos($name, '__MACOSX/') !== 0 && basename($name) !== '.DS_Store') $res['skipped'][] = "$name (" . t('zip.unsafe_path') . ")";
            continue;
        }
        // I symlink nello ZIP (attributi Unix 0120000) vengono ignorati
        if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
            $res['skipped'][] = "$name (" . t('zip.symlink') . ")";
            continue;
        }
        $isDir = substr($name, -1) === '/';
        $rel   = cleanRelPath(ltrim($targetRel . '/' . rtrim($name, '/'), '/'));
        if ($rel === '') { $res['skipped'][] = "$name (" . t('zip.invalid_path') . ")"; continue; }
        $abs = $rootDir . '/' . $rel;

        if ($isDir) {
            if (!is_dir($abs) && @mkdir($abs, 0777, true)) $res['dirs']++;
            continue;
        }
        if (is_dir($abs)) { $res['skipped'][] = "$rel (" . t('zip.folder_exists') . ")"; continue; }
        if (file_exists($abs)) {
            if (!$overwrite) { $res['skipped'][] = "$rel (" . t('zip.exists') . ")"; continue; }
            if (isAllowedFile($rel) && filesize($abs) < MAX_EDIT_SIZE) saveVersion($rel, (string)@file_get_contents($abs), 'save');
            $res['overwritten']++;
        }
        if (!is_dir(dirname($abs)) && !@mkdir(dirname($abs), 0777, true)) { $res['skipped'][] = "$rel (" . t('err.no_permission') . ")"; continue; }
        $in  = $zip->getStream($stat['name']);
        $out = $in ? @fopen($abs, 'wb') : false;
        if (!$in || !$out) { $res['skipped'][] = "$rel (" . t('err.no_permission') . ")"; continue; }
        stream_copy_to_stream($in, $out);
        fclose($in); fclose($out);
        @chmod($abs, 0666);
        $res['files']++;
    }
    return $zip->numFiles;
}

// ===================================================================
// CARICAMENTO A PEZZI (file grandi, oltre i limiti di upload_max_filesize / post_max_size)
// Il browser invia il file in pezzi da pochi MB: si accodano a data/up_<id>.part, i metadati stanno in
// data/up_<id>.json. A fine caricamento il .part si sposta nella destinazione (o si scompatta a passi).
// ===================================================================

const UPLOAD_CHUNK_MAX   = 5 * 1048576;   // dimensione massima di un pezzo
const UPLOAD_CHUNK_ABOVE = 20 * 1048576;  // i file più grandi viaggiano a pezzi
const UPLOAD_STALE       = 86400;         // caricamenti abbandonati: cancellati dopo 24 ore
const UNZIP_STEP_ABOVE   = 50 * 1048576;  // "Scompatta qui" a passi per gli ZIP più grandi

/** Dimensione dei pezzi e soglia oltre la quale un file viaggia a pezzi, entro i limiti PHP del server. */
function uploadChunkInfo(): array {
    $lims = array_filter([iniBytes((string)ini_get('upload_max_filesize')), iniBytes((string)ini_get('post_max_size'))], function ($v) { return $v > 0; });
    $lim = $lims ? min($lims) : PHP_INT_MAX;
    $chunk = max(65536, min(UPLOAD_CHUNK_MAX, $lim - 262144));
    return ['chunk' => $chunk, 'above' => min(UPLOAD_CHUNK_ABOVE, $chunk)];
}

/** Limite configurabile per un singolo file (impostazione upload_max_gb). */
function uploadMaxBytes(): int {
    return (int)round(max(0.1, (float)loadConfig()['upload_max_gb']) * 1073741824);
}

/** Proprietario di un caricamento/scompattamento: l'utente di .htpasswd (in modalità aperta tutti sono uguali). */
function jobOwner(): string {
    global $authUser;
    return sha1('scaffolder-pro|' . (AUTH_ENABLED ? (string)$authUser : ''));
}

function jobFile(string $prefix, string $id, string $ext): string {
    return DATA_DIR . '/' . $prefix . '_' . $id . '.' . $ext;
}

/** Metadati di un lavoro (caricamento "up" o scompattamento "unz") dell'utente attuale, oppure null. */
function jobLoad(string $prefix, string $id): ?array {
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) return null;
    $meta = json_decode((string)@file_get_contents(jobFile($prefix, $id, 'json')), true);
    if (!is_array($meta) || !hash_equals((string)($meta['owner'] ?? ''), jobOwner())) return null;
    return $meta;
}

function jobSave(string $prefix, string $id, array $meta): bool {
    $f = jobFile($prefix, $id, 'json');
    $ok = @file_put_contents($f, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
    if ($ok) @chmod($f, 0666);
    return $ok;
}

/** Cancella i caricamenti e gli scompattamenti abbandonati da più di 24 ore. */
function jobCleanup(): void {
    $limit = time() - UPLOAD_STALE;
    foreach (array_merge(glob(DATA_DIR . '/up_*') ?: [], glob(DATA_DIR . '/unz_*') ?: []) as $f) {
        if (@filemtime($f) < $limit) {
            $meta = substr($f, -5) === '.json' ? json_decode((string)@file_get_contents($f), true) : null;
            if (is_array($meta) && !empty($meta['delete_zip']) && strpos((string)($meta['zip'] ?? ''), DATA_DIR . '/up_') === 0) @unlink($meta['zip']);
            @unlink($f);
        }
    }
}

/** Spazio libero sufficiente per un file di $size byte (più altrettanto se va scompattato)? null = ok, altrimenti il messaggio. */
function diskSpaceError(int $size, bool $extract): ?string {
    global $rootDir;
    $freeData = @disk_free_space(DATA_DIR);
    $freeRoot = @disk_free_space($rootDir);
    if ($freeData === false || $freeRoot === false) return null;   // funzione disattivata: si prova comunque
    $sameDisk = (@stat(DATA_DIR)['dev'] ?? 1) === (@stat($rootDir)['dev'] ?? 2);
    $checks = $sameDisk ? [[$freeData, $size * ($extract ? 2 : 1)]] : [[$freeData, $size], [$freeRoot, $size]];
    foreach ($checks as $c) {
        if ($c[0] < $c[1] + 16 * 1048576) return t('up.no_space', ['need' => humanSize((int)$c[1]), 'free' => humanSize((int)$c[0])]);
    }
    return null;
}

/** Nuovo scompattamento a passi: restituisce l'id del lavoro (stato in data/unz_<id>.json). */
function unzipJobCreate(string $zipAbs, string $targetRel, bool $overwrite, bool $deleteZip, string $name): ?array {
    if (!class_exists('ZipArchive')) return null;
    $zip = new ZipArchive();
    if ($zip->open($zipAbs) !== true) return null;
    $total = $zip->numFiles;
    $zip->close();
    $id = bin2hex(random_bytes(16));
    $meta = ['owner' => jobOwner(), 'root' => $GLOBALS['rootDir'], 'zip' => $zipAbs, 'target' => $targetRel, 'overwrite' => $overwrite,
             'delete_zip' => $deleteZip, 'name' => $name, 'next' => 0, 'total' => $total,
             'res' => ['files' => 0, 'dirs' => 0, 'skipped' => [], 'overwritten' => 0]];
    return jobSave('unz', $id, $meta) ? ['job' => $id, 'total' => $total] : null;
}

// ===================================================================
// RICHIESTE HTTP VERSO API ESTERNE (eseguite dal server con curl)
// La chiave dell'autenticazione resta nel database: al browser arriva solo mascherata.
// ===================================================================

const HTTP_METHODS     = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
const HTTP_MAX_READ    = 5 * 1048576;  // oltre si interrompe la lettura
const HTTP_MAX_STORE   = 2 * 1048576;  // quanto se ne conserva nel database
const HTTP_CLAUDE_MAX  = 60000;        // quanto se ne passa a Claude per ogni risposta

function httpGetRequest(int $id): ?array {
    $st = db()->prepare('SELECT * FROM http_requests WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) return null;
    $r['id']      = (int)$r['id'];
    $r['headers'] = json_decode($r['headers'], true) ?: [];
    $r['auth']    = array_merge(['type' => 'none', 'name' => '', 'value' => ''], json_decode($r['auth'], true) ?: []);
    $r['options'] = array_merge(['insecure' => false], json_decode((string)($r['options'] ?? '{}'), true) ?: []);
    return $r;
}

function httpGetResponse(int $id): ?array {
    $st = db()->prepare('SELECT * FROM http_responses WHERE request_id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) return null;
    if (is_resource($r['body'])) $r['body'] = stream_get_contents($r['body']);
    $r['headers'] = json_decode((string)$r['headers'], true) ?: [];
    // Prima di PHP 8.1 PDO restituisce i numeri come stringhe: il browser li confronta come numeri
    foreach (['request_id', 'status', 'size', 'truncated', 'duration_ms'] as $k) $r[$k] = (int)$r[$k];
    return $r;
}

/** Versione della richiesta da mandare al browser: il valore della chiave diventa "••••abcd". */
function httpPublicRequest(array $r): array {
    $v = (string)$r['auth']['value'];
    $r['auth']['value'] = '';
    $r['auth']['has_value'] = $v !== '';
    $r['auth']['hint'] = $v !== '' ? '••••' . substr($v, -4) : '';
    return $r;
}

/** URL da mostrare a Claude e nel registro: niente chiave in chiaro. */
function httpSafeUrl(array $r): string {
    $url = (string)$r['url'];
    if ($r['auth']['type'] === 'query' && $r['auth']['name'] !== '') {
        $url .= (strpos($url, '?') === false ? '?' : '&') . rawurlencode($r['auth']['name']) . '=***';
    }
    return $url;
}

/** Indirizzi dei metadati cloud (link-local) e indirizzo "nessuno": mai raggiungibili dal client HTTP. */
function httpBlockedIp(string $ip): bool {
    $ip = strtolower(trim($ip, '[]'));
    if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/', $ip, $m)) $ip = $m[1];   // IPv4 scritto come IPv6
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $n = ip2long($ip);
        return ($n & 0xFFFF0000) === (ip2long('169.254.0.0') & 0xFFFF0000)   // 169.254.0.0/16 (AWS, GCP, Azure, Hetzner…)
            || ($n & 0xFF000000) === 0                                      // 0.0.0.0/8
            || $ip === '100.100.100.200';                                   // metadati Alibaba Cloud
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $bin = inet_pton($ip);
        return $ip === '::' || (ord($bin[0]) === 0xfe && (ord($bin[1]) & 0xc0) === 0x80)   // fe80::/10
            || strpos($ip, 'fd00:ec2:') === 0;                                               // metadati AWS IPv6
    }
    return true;
}

/**
 * Controlla l'host di un URL prima di contattarlo e restituisce la voce per CURLOPT_RESOLVE
 * (l'indirizzo controllato è quello usato davvero: niente "DNS rebinding"), oppure ['error' => …].
 * localhost e le reti private restano permessi: servono per provare le API in sviluppo.
 */
function httpCheckHost(string $url): array {
    $p = parse_url($url);
    $host = strtolower(trim((string)($p['host'] ?? ''), '[]'));
    if ($host === '') return ['error' => t('http.url_scheme')];
    $port = (int)($p['port'] ?? (strtolower((string)$p['scheme']) === 'https' ? 443 : 80));
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return httpBlockedIp($host) ? ['error' => t('http.blocked_host', ['host' => $host])] : ['resolve' => null];
    }
    $ips = @gethostbynamel($host) ?: [];
    if (!$ips && function_exists('dns_get_record')) {
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $rec) if (!empty($rec['ipv6'])) $ips[] = $rec['ipv6'];
    }
    if (!$ips) return ['resolve' => null];   // non risolvibile: sarà curl a dare l'errore
    foreach ($ips as $ip) {
        if (httpBlockedIp($ip)) return ['error' => t('http.blocked_host', ['host' => "$host ($ip)"])];
    }
    $list = implode(',', array_map(function ($ip) { return strpos($ip, ':') !== false ? "[$ip]" : $ip; }, $ips));
    return ['resolve' => "$host:$port:$list"];
}

/** Esegue la richiesta salvata e memorizza la risposta. */
function httpRun(array $r): array {
    $url = trim((string)$r['url']);
    if (!preg_match('#^https?://#i', $url)) return ['error' => t('http.url_scheme')];
    $chk = httpCheckHost($url);
    if (isset($chk['error'])) return $chk;
    $auth = $r['auth'];
    if ($auth['type'] === 'query' && $auth['name'] !== '') {
        $url .= (strpos($url, '?') === false ? '?' : '&') . rawurlencode($auth['name']) . '=' . rawurlencode($auth['value']);
    }

    $headers = [];
    $hasAccept = false;
    foreach ($r['headers'] as $h) {
        $name = trim((string)($h['name'] ?? ''));
        if ($name === '' || empty($h['on'] ?? true) || preg_match('/[\r\n:]/', $name)) continue;
        if (strcasecmp($name, 'Accept') === 0) $hasAccept = true;
        $headers[] = $name . ': ' . str_replace(["\r", "\n"], '', (string)($h['value'] ?? ''));
    }
    if (!$hasAccept) $headers[] = 'Accept: application/json, */*;q=0.8';
    $authHeaders = [];
    if ($auth['type'] === 'header' && $auth['name'] !== '') $authHeaders[] = $auth['name'] . ': ' . str_replace(["\r", "\n"], '', $auth['value']);
    if ($auth['type'] === 'bearer') $authHeaders[] = 'Authorization: Bearer ' . str_replace(["\r", "\n"], '', $auth['value']);
    $authHost = strtolower((string)parse_url($url, PHP_URL_HOST));

    $respHeaders = [];
    $body = '';
    $truncated = false;
    $opts = [
        CURLOPT_FOLLOWLOCATION => false,   // i redirect si seguono a mano: ogni nuovo host va controllato
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'ScaffolderPro/1.0',
        CURLOPT_ENCODING       => '',
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
            $t = trim($line);
            if (stripos($t, 'HTTP/') === 0) $respHeaders = [];
            elseif ($t !== '' && strpos($t, ':') !== false) {
                [$k, $v] = explode(':', $t, 2);
                $respHeaders[] = [trim($k), trim($v)];
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$body, &$truncated) {
            if (strlen($body) >= HTTP_MAX_READ) { $truncated = true; return 0; }  // interrompe il download
            $body .= $chunk;
            return strlen($chunk);
        },
    ];
    if (defined('CURLOPT_PROTOCOLS')) $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    // Server di sviluppo con certificato non valido (es. https://127.0.0.1): solo se l'utente lo ha scelto
    if (!empty($r['options']['insecure'])) {
        $opts[CURLOPT_SSL_VERIFYPEER] = false;
        $opts[CURLOPT_SSL_VERIFYHOST] = 0;
    }

    $method = $r['method'];
    $sendBody = $method !== 'GET' && $r['body'] !== '';
    $t0 = microtime(true);
    $err = null; $status = 0; $final = $url;
    for ($hop = 0; $hop <= 5; $hop++) {
        $body = '';
        $ch = curl_init($url);
        // La chiave va solo all'host della richiesta, non a quello di un redirect verso un altro sito
        $sameHost = strtolower((string)parse_url($url, PHP_URL_HOST)) === $authHost;
        $o = $opts + [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $sameHost ? array_merge($headers, $authHeaders) : $headers];
        if ($sendBody) $o[CURLOPT_POSTFIELDS] = $r['body'];
        if ($chk['resolve'] !== null) $o[CURLOPT_RESOLVE] = [$chk['resolve']];
        curl_setopt_array($ch, $o);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $next = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $err = ($ok === false && !$truncated) ? curl_error($ch) : null;
        if (PHP_VERSION_ID < 80000) curl_close($ch); // da PHP 8 si chiude da sola (curl_close è deprecata in 8.5)
        if ($err !== null || $next === '' || !in_array($status, [301, 302, 303, 307, 308], true)) break;
        if ($hop === 5) { $err = t('http.too_many_redirects'); break; }
        if (!preg_match('#^https?://#i', $next)) { $err = t('http.url_scheme'); break; }
        $chk = httpCheckHost($next);
        if (isset($chk['error'])) { $err = $chk['error']; break; }
        // Come i browser: dopo 303 (e 301/302 di un POST) la richiesta successiva è un GET senza corpo
        if ($status === 303 || (in_array($status, [301, 302], true) && $method === 'POST')) { $method = 'GET'; $sendBody = false; }
        $url = $next;
    }
    $ms = (int)round((microtime(true) - $t0) * 1000);

    // Nella cronologia l'URL finale non deve contenere la chiave
    if ($auth['type'] === 'query' && $auth['value'] !== '') $final = str_replace(rawurlencode($auth['value']), '***', $final);

    $size = strlen($body);
    if ($size > HTTP_MAX_STORE) { $body = substr($body, 0, HTTP_MAX_STORE); $truncated = true; }
    $st = db()->prepare('INSERT OR REPLACE INTO http_responses (request_id, status, headers, body, size, truncated, duration_ms, final_url, error, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, datetime(\'now\',\'localtime\'))');
    $st->bindValue(1, (int)$r['id'], PDO::PARAM_INT);
    $st->bindValue(2, $status, PDO::PARAM_INT);
    $st->bindValue(3, json_encode($respHeaders, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    $st->bindValue(4, $body, PDO::PARAM_LOB);
    $st->bindValue(5, $size, PDO::PARAM_INT);
    $st->bindValue(6, $truncated ? 1 : 0, PDO::PARAM_INT);
    $st->bindValue(7, $ms, PDO::PARAM_INT);
    $st->bindValue(8, $final);
    $st->bindValue(9, $err);
    $st->execute();
    addLog($err ? 'error' : 'create', "API {$r['method']} " . httpSafeUrl($r) . ' → ' . ($err ?: "HTTP $status, " . humanSize($size) . ", {$ms} ms"));
    return httpGetResponse((int)$r['id']);
}

// ===================================================================
// STRUMENTI PER CLAUDE (tool use)
// Claude può leggere qualunque file della root, cercare nel codice, leggere le risposte delle
// richieste API salvate e PROPORRE modifiche. Nessuno strumento scrive, elimina o esegue comandi:
// le proposte vengono applicate solo dall'utente (azione claude_apply).
// ===================================================================

const ANTHROPIC_API_URL  = 'https://api.anthropic.com/v1/messages';
const CLAUDE_TOOL_MAX    = 150000;  // byte massimi restituiti da una singola lettura
const CLAUDE_MAX_STEPS   = 12;      // passaggi Claude ↔ strumenti per ogni messaggio

function claudeTools(): array {
    return [
        [
            'name' => 'elenca_file',
            'description' => 'Lists folders and files of the project (the root open in the Explorer), with sizes. Use it to get oriented before reading.',
            'input_schema' => ['type' => 'object', 'properties' => [
                'cartella' => ['type' => 'string', 'description' => 'Subfolder relative to the root; empty = the whole root.'],
            ], 'additionalProperties' => false],
        ],
        [
            'name' => 'leggi_file',
            'description' => 'Reads the content of a text file of the project. For long files you can ask for a range of lines only.',
            'input_schema' => ['type' => 'object', 'properties' => [
                'percorso' => ['type' => 'string', 'description' => 'Path relative to the root, e.g. "inc/config.php".'],
                'da_riga'  => ['type' => 'integer', 'description' => 'First line (from 1), optional.'],
                'a_riga'   => ['type' => 'integer', 'description' => 'Last line, optional.'],
            ], 'required' => ['percorso'], 'additionalProperties' => false],
        ],
        [
            'name' => 'cerca_nei_file',
            'description' => 'Searches a text (or a regular expression) in the content of the project files. Returns file, line number and matching line.',
            'input_schema' => ['type' => 'object', 'properties' => [
                'testo'    => ['type' => 'string', 'description' => 'Text to search for (case-insensitive).'],
                'regex'    => ['type' => 'boolean', 'description' => 'true if "testo" is a PCRE regular expression without delimiters.'],
                'cartella' => ['type' => 'string', 'description' => 'Limits the search to this subfolder.'],
            ], 'required' => ['testo'], 'additionalProperties' => false],
        ],
        [
            'name' => 'proponi_modifica',
            'description' => 'Proposes a change to an existing project file: replaces one piece of text with another. '
                . 'It does NOT write the file: the user sees the diff and decides whether to apply it. '
                . 'testo_da_sostituire must appear EXACTLY ONCE in the current file, copied exactly (spaces and line breaks included): '
                . 'include a few surrounding lines if needed to make it unique. For several changes make several proposals on different parts.',
            'input_schema' => ['type' => 'object', 'properties' => [
                'percorso'            => ['type' => 'string', 'description' => 'Path relative to the root.'],
                'testo_da_sostituire' => ['type' => 'string', 'description' => 'Exact text present in the file.'],
                'nuovo_testo'         => ['type' => 'string', 'description' => 'Text that takes its place (empty to delete it).'],
                'spiegazione'         => ['type' => 'string', 'description' => "One sentence on what changes and why, in the user's language."],
            ], 'required' => ['percorso', 'testo_da_sostituire', 'nuovo_testo'], 'additionalProperties' => false],
        ],
        [
            'name' => 'proponi_nuovo_file',
            'description' => 'Proposes creating a new file with its complete content. It does NOT create it: the user approves or discards. '
                . 'Only for files that do not exist yet (for existing ones use proponi_modifica).',
            'input_schema' => ['type' => 'object', 'properties' => [
                'percorso'    => ['type' => 'string', 'description' => 'Path relative to the root, e.g. "inc/helpers.php".'],
                'contenuto'   => ['type' => 'string', 'description' => 'Complete content of the file.'],
                'spiegazione' => ['type' => 'string', 'description' => "One sentence on what it is for, in the user's language."],
            ], 'required' => ['percorso', 'contenuto'], 'additionalProperties' => false],
        ],
        [
            'name' => 'elenca_richieste_api',
            'description' => "Lists the API requests saved by the user (name, method, URL without key, outcome and date of the last response).",
            'input_schema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
        ],
        [
            'name' => 'leggi_risposta_api',
            'description' => 'Reads the body of the last saved response of an API request (often JSON).',
            'input_schema' => ['type' => 'object', 'properties' => [
                'richiesta' => ['type' => 'string', 'description' => 'Name of the request or its number (id).'],
            ], 'required' => ['richiesta'], 'additionalProperties' => false],
        ],
    ];
}

/**
 * Controlla una proposta di modifica di Claude (o una sua applicazione) sul contenuto attuale del file.
 * Restituisce ['content' => nuovo contenuto completo, 'old_content' => …] oppure ['error' => …].
 */
function claudeCheckProposal(string $type, string $rel, string $old, string $new, array $overrides = []): array {
    global $rootDir;
    if ($rel === '') return ['error' => t('prop.err_path')];
    if (!isAllowedFile($rel)) return ['error' => t('prop.err_ext', ['path' => $rel, 'list' => implode(', ', array_slice(ALLOWED_EXT, 0, 8)) . '…'])];
    $abs = $rootDir . '/' . $rel;
    if ($type === 'new') {
        if (file_exists($abs)) return ['error' => t('prop.err_exists', ['path' => $rel])];
        if (strlen($new) > MAX_EDIT_SIZE) return ['error' => t('prop.err_too_big', ['path' => $rel])];
        return ['content' => $new, 'old_content' => ''];
    }
    if (!is_file($abs)) return ['error' => t('prop.err_not_found', ['path' => $rel])];
    if ((int)filesize($abs) > MAX_EDIT_SIZE) return ['error' => t('prop.err_too_big', ['path' => $rel])];
    if ($old === '') return ['error' => t('prop.err_empty_old')];
    // Se la scheda ha modifiche non salvate, la proposta si confronta con il testo dell'editor
    $current = isset($overrides[$rel]) && is_string($overrides[$rel]) ? $overrides[$rel] : (string)@file_get_contents($abs);
    $n = substr_count($current, $old);
    if ($n === 0) {
        // Spesso cambiano solo gli a capo (CRLF) o gli spazi finali: lo si dice a Claude
        return ['error' => t('prop.err_old_missing', ['path' => $rel])];
    }
    if ($n > 1) return ['error' => t('prop.err_old_many', ['n' => $n, 'path' => $rel])];
    $pos = strpos($current, $old);
    return ['content' => substr($current, 0, $pos) . $new . substr($current, $pos + strlen($old)), 'old_content' => $current,
            'line' => substr_count(substr($current, 0, $pos), "\n") + 1];
}

/**
 * Esegue uno strumento e restituisce [testo del risultato, è_errore, etichetta breve per l'interfaccia, proposta o null].
 * Gli strumenti "proponi_*" non scrivono nulla: la proposta torna al browser, dove l'utente la applica o la scarta.
 * $overrides = testo delle schede con modifiche non salvate (percorso → contenuto): conta quello, non il disco.
 */
function claudeRunTool(string $name, array $in, array $overrides = []): array {
    global $rootDir;
    switch ($name) {
        case 'proponi_modifica':
        case 'proponi_nuovo_file': {
            $type = $name === 'proponi_nuovo_file' ? 'new' : 'edit';
            $rel  = cleanRelPath($in['percorso'] ?? '');
            $old  = (string)($in['testo_da_sostituire'] ?? '');
            $new  = (string)($type === 'new' ? ($in['contenuto'] ?? '') : ($in['nuovo_testo'] ?? ''));
            $label = t($type === 'new' ? 'tool.new_file' : 'tool.edit', ['path' => $rel ?: (string)($in['percorso'] ?? '')]);
            $chk = claudeCheckProposal($type, $rel, $old, $new, $overrides);
            if (isset($chk['error'])) return [$chk['error'], true, $label, null];
            // Una proposta che rompe la sintassi PHP torna indietro a Claude perché la corregga
            if (isPhpFile($rel) && !empty(loadConfig()['php_lint']) && ($lint = phpLint($chk['content'], $rel))
                && ($type === 'new' || !phpLint($chk['old_content'], $rel))) {
                return ["With this change $rel would have a PHP syntax error on line {$lint['line']}: {$lint['message']}. Fix the proposal.", true, $label, null];
            }
            $proposal = ['type' => $type, 'path' => $rel, 'old' => $old, 'new' => $new,
                         'note' => mb_substr(trim((string)($in['spiegazione'] ?? '')), 0, 500), 'line' => $chk['line'] ?? 1];
            return ['Proposal recorded: the user sees the diff and decides whether to apply it. It is NOT applied yet: '
                  . 'do not assume the file has changed.', false, $label, $proposal];
        }
        case 'elenca_file': {
            $base = cleanRelPath($in['cartella'] ?? '');
            $tree = scanProjectTree($rootDir);
            $out = [];
            foreach ($tree['dirs'] as $d) {
                if ($d !== '' && ($base === '' || strpos($d . '/', $base . '/') === 0)) $out[] = "$d/";
            }
            foreach ($tree['files'] as $f) {
                if ($base === '' || strpos($f, $base . '/') === 0) $out[] = $f . ' (' . humanSize((int)@filesize($rootDir . '/' . $f)) . ')';
            }
            $label = t('tool.list', ['path' => $base ?: '/']);
            if (!$out) return ['No items' . ($base !== '' ? " in $base" : ''), false, $label];
            $more = count($out) > 3000 ? "\n… " . (count($out) - 3000) . ' more items' : '';
            return [implode("\n", array_slice($out, 0, 3000)) . $more, false, $label];
        }
        case 'leggi_file': {
            $rel = cleanRelPath($in['percorso'] ?? '');
            $abs = $rootDir . '/' . $rel;
            if ($rel === '' || !is_file($abs)) return ['File not found: ' . ($in['percorso'] ?? ''), true, t('tool.not_found')];
            if (!isAllowedFile($rel)) return ["$rel is not a readable text file (binary or extension not allowed).", true, $rel];
            $content = isset($overrides[$rel]) && is_string($overrides[$rel]) ? $overrides[$rel]
                : (string)@file_get_contents($abs, false, null, 0, 4 * 1048576);
            $from = max(1, (int)($in['da_riga'] ?? 1));
            $to   = (int)($in['a_riga'] ?? 0);
            if ($from > 1 || $to > 0) {
                $lines = explode("\n", $content);
                $content = implode("\n", array_slice($lines, $from - 1, $to > 0 ? max(0, $to - $from + 1) : null));
            }
            $cut = strlen($content) > CLAUDE_TOOL_MAX;
            if ($cut) $content = substr($content, 0, CLAUDE_TOOL_MAX);
            return [$content . ($cut ? "\n\n[… truncated: use da_riga/a_riga to read the rest]" : ''), false, $rel];
        }
        case 'cerca_nei_file': {
            $needle = (string)($in['testo'] ?? '');
            if ($needle === '') return ['Empty search text.', true, t('tool.search', ['q' => ''])];
            $base = cleanRelPath($in['cartella'] ?? '');
            $pattern = !empty($in['regex']) ? '~' . str_replace('~', '\~', $needle) . '~iu' : null;
            if ($pattern !== null && @preg_match($pattern, '') === false) return ['Invalid regular expression.', true, t('tool.search', ['q' => $needle])];
            $hits = [];
            foreach (scanProjectTree($rootDir)['files'] as $f) {
                if (!isAllowedFile($f) || ($base !== '' && strpos($f, $base . '/') !== 0)) continue;
                $abs = $rootDir . '/' . $f;
                if ((int)@filesize($abs) > MAX_EDIT_SIZE) continue;
                $text = isset($overrides[$f]) && is_string($overrides[$f]) ? $overrides[$f] : (string)@file_get_contents($abs);
                foreach (explode("\n", $text) as $i => $line) {
                    $found = $pattern !== null ? preg_match($pattern, $line) : stripos($line, $needle) !== false;
                    if ($found) {
                        $hits[] = "$f:" . ($i + 1) . ': ' . mb_substr(trim($line), 0, 240);
                        if (count($hits) >= 300) break 2;
                    }
                }
            }
            $label = t('tool.search', ['q' => mb_substr($needle, 0, 40)]);
            if (!$hits) return ["No results for \"$needle\".", false, $label];
            return [implode("\n", $hits) . (count($hits) >= 300 ? "\n[… first 300 results shown]" : ''), false, $label];
        }
        case 'elenca_richieste_api': {
            $rows = db()->query('SELECT id FROM http_requests ORDER BY name COLLATE NOCASE')->fetchAll();
            if (!$rows) return ['No saved API requests.', false, t('tool.requests')];
            $out = [];
            foreach ($rows as $row) {
                $q = httpGetRequest((int)$row['id']);
                $r = httpGetResponse((int)$row['id']);
                $out[] = "#{$q['id']} \"{$q['name']}\" {$q['method']} " . httpSafeUrl($q) . ' → '
                       . ($r ? ($r['error'] ? 'error: ' . $r['error'] : "HTTP {$r['status']}, " . humanSize((int)$r['size']) . ", {$r['created_at']}") : 'never run');
            }
            return [implode("\n", $out), false, t('tool.requests')];
        }
        case 'leggi_risposta_api': {
            $key = trim((string)($in['richiesta'] ?? ''));
            $id = ctype_digit(ltrim($key, '#')) ? (int)ltrim($key, '#') : 0;
            if (!$id) {
                $st = db()->prepare('SELECT id FROM http_requests WHERE name = ? COLLATE NOCASE LIMIT 1');
                $st->execute([$key]);
                $id = (int)$st->fetchColumn();
            }
            $q = $id ? httpGetRequest($id) : null;
            if (!$q) return ["API request \"$key\" not found. Use elenca_richieste_api.", true, t('tool.response')];
            $r = httpGetResponse($id);
            if (!$r) return ["The request \"{$q['name']}\" has never been run.", false, "API {$q['name']}"];
            if ($r['error']) return ["Last run failed: {$r['error']}", false, "API {$q['name']}"];
            $body = (string)$r['body'];
            $cut = strlen($body) > CLAUDE_TOOL_MAX;
            return ["{$q['method']} " . httpSafeUrl($q) . " → HTTP {$r['status']} ({$r['created_at']})\n\n" . ($cut ? substr($body, 0, CLAUDE_TOOL_MAX) . "\n[… truncated]" : $body), false, "API {$q['name']}"];
        }
    }
    return ["Unknown tool: $name", true, $name];
}

/**
 * Contenuto dell'assistente da rimettere nella cronologia: dopo un cambio di modello (blocco "fallback")
 * si tengono i testi precedenti e tutto ciò che viene dopo l'ultimo cambio.
 */
function claudeHistoryContent(array $content): array {
    $last = -1;
    foreach ($content as $i => $b) if (($b['type'] ?? '') === 'fallback') $last = $i;
    if ($last < 0) return $content;
    $out = [];
    foreach ($content as $i => $b) {
        if ($i > $last || ($b['type'] ?? '') === 'text') $out[] = $b;
    }
    return $out;
}

// ===================================================================
// CONTROLLO DELLA SINTASSI PHP
// Prima "php -l" (vede anche gli errori di compilazione), ma solo con un PHP da riga di comando
// della stessa versione che esegue Scaffolder Pro; altrimenti il tokenizer (solo errori di parsing).
// ===================================================================

/** PHP da riga di comando utilizzabile per "php -l": ['bin' => …, 'version' => …] oppure null. */
function phpCli(): ?array {
    static $cli = false;
    if ($cli !== false) return $cli;
    $cli = null;
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (!function_exists('exec') || in_array('exec', $disabled, true)) return null;

    // Risultato memorizzato: si ricerca solo se cambia la versione del server o sparisce il binario
    $cached = json_decode((string)getSetting('php_cli', ''), true);
    if (is_array($cached) && ($cached['server'] ?? '') === PHP_VERSION) {
        if (empty($cached['bin'])) return null;
        if (@is_executable($cached['bin'])) return $cli = ['bin' => $cached['bin'], 'version' => $cached['version']];
    }

    $want = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $candidates = [PHP_BINDIR . '/php', PHP_BINDIR . '/php' . $want, PHP_BINDIR . '/php' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION];
    if (preg_match('/^php[\d.]*(\.exe)?$/i', basename(PHP_BINARY))) array_unshift($candidates, PHP_BINARY);
    $candidates = array_merge($candidates, ['/usr/local/bin/php', '/usr/bin/php', '/opt/homebrew/bin/php']);
    foreach (array_unique($candidates) as $bin) {
        if (!@is_file($bin) || !@is_executable($bin)) continue;
        $out = [];
        @exec(escapeshellarg($bin) . ' -n -r ' . escapeshellarg('echo PHP_VERSION;') . ' 2>/dev/null', $out, $code);
        $ver = trim(implode('', $out));
        if ($code === 0 && preg_match('/^(\d+\.\d+)\.\d+/', $ver, $m) && $m[1] === $want) {
            $cli = ['bin' => $bin, 'version' => $ver];
            break;
        }
    }
    try {
        setSetting('php_cli', json_encode(['server' => PHP_VERSION, 'bin' => $cli['bin'] ?? '', 'version' => $cli['version'] ?? '']));
    } catch (Throwable $e) {
        // memorizzarlo è solo un'ottimizzazione
    }
    return $cli;
}

/**
 * Controlla la sintassi di un codice PHP senza eseguirlo.
 * Restituisce null se è corretto, altrimenti ['line' => n, 'message' => …, 'php' => versione, 'method' => …].
 */
function phpLint(string $code, string $name = 'file.php'): ?array {
    $cli = phpCli();
    if ($cli) {
        $tmp = DATA_DIR . '/lint_' . bin2hex(random_bytes(6)) . '.php';
        if (@file_put_contents($tmp, $code) !== false) {
            $out = [];
            @exec(escapeshellarg($cli['bin']) . ' -n -d display_errors=1 -d error_reporting=-1 -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $exit);
            @unlink($tmp);
            $text = implode("\n", $out);
            if ($exit === 0 && stripos($text, 'No syntax errors') !== false) return null;
            if (preg_match('/(?:PHP\s+)?((?:Parse|Fatal) error):\s*(.+?) in \S+ on line (\d+)/i', $text, $m)) {
                return ['line' => (int)$m[3], 'message' => ucfirst($m[1]) . ': ' . str_replace($tmp, $name, trim($m[2])),
                        'php' => $cli['version'], 'method' => 'php -l'];
            }
            // Uscita non riconosciuta: si passa al tokenizer
        }
    }
    if (!function_exists('token_get_all') || !defined('TOKEN_PARSE')) return null;
    try {
        token_get_all($code, TOKEN_PARSE);
    } catch (ParseError $e) {
        return ['line' => $e->getLine(), 'message' => 'Parse error: ' . $e->getMessage(), 'php' => PHP_VERSION, 'method' => 'tokenizer'];
    } catch (Throwable $e) {
        return null;
    }
    return null;
}

function isPhpFile(string $relPath): bool {
    return in_array(strtolower(pathinfo($relPath, PATHINFO_EXTENSION)), ['php', 'phtml'], true);
}

// ===================================================================
// CERCA E SOSTITUISCI NEI FILE
// ===================================================================

const SEARCH_MAX_RESULTS = 2000;

/**
 * Prepara l'espressione regolare della ricerca.
 * $o: q, case (bool), word (bool), regex (bool). Restituisce [pattern, errore].
 */
function searchPattern(array $o): array {
    $q = (string)($o['q'] ?? '');
    if ($q === '') return [null, t('sf.err_empty')];
    $body = !empty($o['regex']) ? str_replace('~', '\~', $q) : preg_quote($q, '~');
    if (!empty($o['word'])) $body = '\b(?:' . $body . ')\b';
    $pattern = '~' . $body . '~' . (empty($o['case']) ? 'i' : '');
    if (@preg_match($pattern, '') === false) return [null, t('sf.err_regex')];
    return [$pattern, null];
}

/** Elenco "*.php, src/, !vendor" → controlla se un percorso relativo corrisponde. */
function pathMatchesList(string $rel, string $list): bool {
    foreach (preg_split('/[,\n]+/', $list) as $p) {
        $p = trim(str_replace('\\', '/', $p));
        if ($p === '') continue;
        $p = ltrim($p, '/');
        if (strpbrk($p, '*?[') !== false) {
            // senza FNM_PATHNAME "*" attraversa anche le "/": "src/*.php" vale pure nelle sottocartelle di src
            if (fnmatch($p, $rel) || (strpos($p, '/') === false && fnmatch($p, basename($rel)))) return true;
        } else {
            $p = rtrim($p, '/');
            if ($rel === $p || strpos($rel, $p . '/') === 0) return true;
            // nome di cartella senza percorso: vale ovunque ("tests" esclude anche "app/tests/…")
            if (strpos($p, '/') === false && (strpos('/' . $rel, '/' . $p . '/') !== false)) return true;
        }
    }
    return false;
}

/** Il pattern con "u" se il testo è UTF-8 valido (così le colonne contano i caratteri). */
function utf8Pattern(string $pattern, string $text): string {
    return preg_match('//u', $text) ? $pattern . 'u' : $pattern;
}

/** Colonna in caratteri (come Ace) di un offset in byte. */
function charCol(string $line, int $byteOffset): int {
    return function_exists('mb_strlen') ? mb_strlen(substr($line, 0, $byteOffset), 'UTF-8') : $byteOffset;
}

/** Testo di sostituzione: con la regex valgono $1, $2…; senza è letterale. */
function replacementFor(array $o, string $replace): string {
    return !empty($o['regex']) ? $replace : str_replace(['\\', '$'], ['\\\\', '\\$'], $replace);
}

/**
 * Cerca nel contenuto dei file della root.
 * $o: q, case, word, regex, include, exclude, replace (facoltativo: anteprima della sostituzione).
 */
function searchFiles(array $o): array {
    global $rootDir;
    [$pattern, $err] = searchPattern($o);
    if ($err) return ['ok' => false, 'error' => $err];
    $hasReplace = array_key_exists('replace', $o) && $o['replace'] !== null;
    $repl = $hasReplace ? replacementFor($o, (string)$o['replace']) : '';

    $maxTime = (int)ini_get('max_execution_time');
    $budget  = $maxTime > 0 ? max(3, min(20, $maxTime - 5)) : 20;
    $t0 = microtime(true);
    $out = []; $total = 0; $truncated = false; $timeout = false; $skipped = 0; $scanned = 0;

    foreach (scanProjectTree($rootDir)['files'] as $f) {
        if (!isAllowedFile($f)) continue;
        if (!empty($o['include']) && !pathMatchesList($f, (string)$o['include'])) continue;
        if (!empty($o['exclude']) && pathMatchesList($f, (string)$o['exclude'])) continue;
        $abs = $rootDir . '/' . $f;
        if ((int)@filesize($abs) > MAX_EDIT_SIZE) { $skipped++; continue; }
        if (microtime(true) - $t0 > $budget) { $timeout = true; break; }
        $content = (string)@file_get_contents($abs);
        $scanned++;
        $pat = utf8Pattern($pattern, $content);
        if (!preg_match($pat, $content)) continue;

        $matches = [];
        foreach (explode("\n", $content) as $i => $line) {
            if (!preg_match_all($pat, $line, $mm, PREG_OFFSET_CAPTURE)) continue;
            foreach ($mm[0] as $m) {
                if ($m[0] === '') continue; // corrispondenze vuote (es. "^") non servono
                $col = charCol($line, $m[1]);
                $len = function_exists('mb_strlen') ? mb_strlen($m[0], 'UTF-8') : strlen($m[0]);
                // Anteprima: un po' di testo prima e dopo la corrispondenza
                $before = substr($line, max(0, $m[1] - 80), min(80, $m[1]));
                $after  = substr($line, $m[1] + strlen($m[0]), 160);
                $item = ['line' => $i + 1, 'col' => $col, 'len' => $len,
                         'before' => ltrim($before), 'match' => $m[0], 'after' => rtrim($after)];
                if ($hasReplace) $item['repl'] = (string)@preg_replace($pat, $repl, $m[0], 1);
                $matches[] = $item;
                if (++$total >= SEARCH_MAX_RESULTS) { $truncated = true; break; }
            }
            if ($truncated) break;
        }
        if ($matches) $out[] = ['path' => $f, 'matches' => $matches];
        if ($truncated) break;
    }
    return ['ok' => true, 'files' => $out, 'total' => $total, 'truncated' => $truncated, 'timeout' => $timeout,
            'skipped' => $skipped, 'scanned' => $scanned, 'ms' => (int)round((microtime(true) - $t0) * 1000)];
}

/**
 * Sostituisce in un file: tutte le occorrenze, oppure solo quelle indicate ([['line' => n, 'col' => c], …]).
 * Restituisce [nuovo contenuto, sostituzioni fatte].
 */
function replaceInContent(string $content, array $o, string $replace, ?array $only): array {
    [$pattern] = searchPattern($o);
    $pat  = utf8Pattern($pattern, $content);
    $repl = replacementFor($o, $replace);
    if ($only === null) {
        $count = 0;
        $new = preg_replace_callback($pat, function ($m) use ($pat, $repl, &$count) {
            if ($m[0] === '') return $m[0];
            $count++;
            return preg_replace($pat, $repl, $m[0], 1);
        }, $content);
        return [(string)$new, $count];
    }
    $want = [];
    foreach ($only as $w) $want[(int)($w['line'] ?? 0) . ':' . (int)($w['col'] ?? -1)] = true;
    $lines = explode("\n", $content);
    $count = 0;
    foreach ($lines as $i => &$line) {
        if (!preg_match_all($pat, $line, $mm, PREG_OFFSET_CAPTURE)) continue;
        // dall'ultima alla prima, così gli offset delle precedenti restano validi
        foreach (array_reverse($mm[0]) as $m) {
            if ($m[0] === '' || empty($want[($i + 1) . ':' . charCol($line, $m[1])])) continue;
            $line = substr($line, 0, $m[1]) . preg_replace($pat, $repl, $m[0], 1) . substr($line, $m[1] + strlen($m[0]));
            $count++;
        }
    }
    unset($line);
    return [implode("\n", $lines), $count];
}

// ===================================================================
// LOG DEGLI ERRORI PHP DEL SITO
// Si leggono solo file noti: quello di php.ini, gli error_log della root (e delle sottocartelle
// di primo livello) e quello scelto nelle impostazioni. Il browser li indica con un id.
// ===================================================================

const ERROR_LOG_NAMES = ['error_log', 'php_error.log', 'php_errors.log', 'php_error_log'];
const ERROR_LOG_TAIL  = 1048576; // si leggono al massimo gli ultimi 1 MB

function errorLogSources(): array {
    global $rootDir;
    $list = [];
    $add = function ($path, $label) use (&$list) {
        $real = $path !== '' ? realpath($path) : false;
        if ($real === false || !is_file($real) || !is_readable($real)) return;
        $id = substr(sha1($real), 0, 12);
        if (!isset($list[$id])) $list[$id] = ['id' => $id, 'path' => $real, 'label' => $label];
    };
    $ini = (string)ini_get('error_log');
    if ($ini !== '' && $ini !== 'syslog') $add($ini, 'php.ini');
    $custom = trim((string)loadConfig()['error_log_path']);
    if ($custom !== '') $add($custom, 'impostazioni');
    foreach (ERROR_LOG_NAMES as $n) $add($rootDir . '/' . $n, 'root');
    foreach (glob($rootDir . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        if (isExcludedName(basename($d))) continue;
        foreach (ERROR_LOG_NAMES as $n) $add($d . '/' . $n, basename($d) . '/');
    }
    foreach ($list as &$s) {
        $s['size'] = (int)@filesize($s['path']);
        $s['mtime'] = (int)@filemtime($s['path']);
        $s['in_root'] = strpos($s['path'] . '/', $rootDir . '/') === 0;
        $s['writable'] = is_writable($s['path']);
        $s['display'] = $s['in_root'] ? substr($s['path'], strlen($rootDir) + 1) : $s['path'];
    }
    unset($s);
    return array_values($list);
}

function errorLogSource(string $id): ?array {
    foreach (errorLogSources() as $s) if ($s['id'] === $id) return $s;
    return null;
}

/** Legge la fine del file (senza caricarlo tutto) e restituisce le righe complete. */
function tailLines(string $path, int $bytes): array {
    $size = (int)@filesize($path);
    $fh = @fopen($path, 'rb');
    if (!$fh) return [];
    $start = max(0, $size - $bytes);
    fseek($fh, $start);
    $data = (string)stream_get_contents($fh);
    fclose($fh);
    if ($start > 0 && ($p = strpos($data, "\n")) !== false) $data = substr($data, $p + 1); // prima riga a metà
    return explode("\n", rtrim(str_replace("\r\n", "\n", $data)));
}

/** Tipo normalizzato dell'errore: fatal, parse, warning, notice, deprecated, altro. */
function errorKind(string $msg): string {
    if (preg_match('/\bParse error\b/i', $msg)) return 'parse';
    if (preg_match('/\b(Fatal error|Uncaught|Recoverable fatal error|Catchable fatal error)\b/i', $msg)) return 'fatal';
    if (preg_match('/\bWarning\b/i', $msg)) return 'warning';
    if (preg_match('/\bDeprecated\b/i', $msg)) return 'deprecated';
    if (preg_match('/\b(Notice|Strict Standards)\b/i', $msg)) return 'notice';
    return 'other';
}

/**
 * Interpreta le righe di un log (formato PHP "[data] PHP Warning: … in file on line n" e formato
 * error_log di Apache "[data] [php:error] … PHP …"), unisce gli stack trace e raggruppa i ripetuti.
 */
function parseErrorLog(array $lines, int $limit): array {
    global $rootDir;
    $entries = [];
    $cur = null;
    $flush = function () use (&$cur, &$entries) { if ($cur !== null) $entries[] = $cur; $cur = null; };
    foreach ($lines as $line) {
        if ($line === '') continue;
        if (preg_match('/^\[([^\]]+)\]\s*(.*)$/', $line, $m) && !preg_match('/^PHP\s+(Stack trace|\d+\.)/', $m[2])) {
            $flush();
            $msg = $m[2];
            // Apache: "[php:error] [pid 1] [client …] PHP Fatal error: …, referer: …"
            $msg = preg_replace('/^(\[[^\]]*\]\s*)+/', '', $msg);
            $msg = preg_replace('/, referer: \S+$/', '', $msg);
            $cur = ['date' => $m[1], 'text' => preg_replace(['/^PHP\s+/', '/:\s{2,}/'], ['', ': '], $msg), 'trace' => []];
        } elseif ($cur !== null) {
            $cur['trace'][] = preg_replace('/^(\[[^\]]*\]\s*)?PHP\s+/', '', $line);
        } else {
            $cur = ['date' => '', 'text' => $line, 'trace' => []];
        }
    }
    $flush();

    $groups = [];
    $order  = []; // chiave del gruppo → posizione dell'ultima occorrenza (per mettere in alto i più recenti)
    foreach ($entries as $i => $e) {
        $text = $e['text'];
        $file = ''; $lineNo = 0;
        if (preg_match('/ in (\S+?) on line (\d+)/', $text, $m) || preg_match('/ in (\S+?):(\d+)(?:\s|$)/', $text, $m)) {
            $file = $m[1]; $lineNo = (int)$m[2];
        }
        $kind = errorKind($text);
        $key = sha1($kind . '|' . $text);
        if (!isset($groups[$key])) {
            $real = $file !== '' ? realpath($file) : false;
            $rel = ($real !== false && strpos($real, $rootDir . '/') === 0) ? substr($real, strlen($rootDir) + 1) : '';
            $groups[$key] = ['kind' => $kind, 'text' => $text, 'file' => $file, 'rel' => $rel, 'line' => $lineNo,
                             'count' => 0, 'first' => $e['date'], 'last' => $e['date'], 'trace' => array_slice($e['trace'], 0, 30)];
        }
        $groups[$key]['count']++;
        $groups[$key]['last'] = $e['date'];
        $order[$key] = $i;
    }
    arsort($order);
    $out = [];
    foreach (array_keys($order) as $key) {
        $out[] = $groups[$key];
        if (count($out) >= $limit) break;
    }
    return ['groups' => $out, 'entries' => count($entries)];
}

// Attivazione facoltativa del log degli errori del sito con un blocco in <root>/.user.ini
// (vale con PHP-FPM/CGI, es. Plesk; con mod_php, come XAMPP, .user.ini viene ignorato).
const SITE_LOG_BEGIN = '; >>> Scaffolder Pro: PHP error log (remove it from the PHP errors panel)';
const SITE_LOG_END   = '; <<< Scaffolder Pro';
// Stesso blocco scritto dalle versioni precedenti (con il nome TreeForge): riconosciuto e tolto come il nuovo
const SITE_LOG_LEGACY = ['; >>> TreeForge: PHP error log (remove it from the PHP errors panel)', '; <<< TreeForge'];

function siteLogStatus(): array {
    global $rootDir;
    $ini = $rootDir . '/.user.ini';
    $txt = is_file($ini) ? (string)@file_get_contents($ini) : '';
    return ['on' => strpos($txt, SITE_LOG_BEGIN) !== false || strpos($txt, SITE_LOG_LEGACY[0]) !== false, 'file' => 'php_error.log',
            'works' => PHP_SAPI !== 'apache2handler', 'ttl' => (int)ini_get('user_ini.cache_ttl')];
}

/** Aggiunge o toglie il blocco. Restituisce true oppure un messaggio d'errore. */
function setSiteLog(bool $on) {
    global $rootDir;
    $ini = $rootDir . '/.user.ini';
    $txt = is_file($ini) ? (string)@file_get_contents($ini) : '';
    $clean = $txt;
    foreach ([[SITE_LOG_BEGIN, SITE_LOG_END], SITE_LOG_LEGACY] as $m) {
        $clean = preg_replace('/\n?' . preg_quote($m[0], '/') . '.*?' . preg_quote($m[1], '/') . '\n?/s', "\n", $clean);
    }
    $clean = rtrim($clean);
    if ($on) {
        $log = $rootDir . '/php_error.log';
        $block = SITE_LOG_BEGIN . "\nlog_errors = On\nerror_log = \"" . $log . "\"\n" . SITE_LOG_END . "\n";
        $new = ($clean !== '' ? $clean . "\n\n" : '') . $block;
        if (!is_file($log) && @file_put_contents($log, '') !== false) @chmod($log, 0666);
    } else {
        $new = $clean !== '' ? $clean . "\n" : '';
    }
    if ($txt !== '') saveVersion('.user.ini', $txt, 'save');
    $ok = $new === '' ? @unlink($ini) : @file_put_contents($ini, $new) !== false;
    if (!$ok) return t('err.userini_permission');
    @chmod($ini, 0666);
    addLog($on ? 'create' : 'delete', t($on ? 'log.site_log_on' : 'log.site_log_off'));
    return true;
}

// ===================================================================
// UTILITÀ
// ===================================================================

/** URL pubblico della root se si trova sotto la DocumentRoot di Apache (per l'anteprima). */
function rootPublicUrl(): ?string {
    global $rootDir;
    $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    if (!$docRoot || ($rootDir !== $docRoot && strpos($rootDir . '/', $docRoot . '/') !== 0)) return null;
    $rel = substr($rootDir, strlen($docRoot));
    return implode('/', array_map('rawurlencode', explode('/', $rel))) . '/';
}

function humanSize(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1073741824, 2) . ' GB';
}
