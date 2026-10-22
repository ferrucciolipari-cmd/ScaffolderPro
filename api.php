<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/core.php';

// La sessione serve solo in lettura (token CSRF): rilasciarla subito evita che una richiesta lunga
// (chiamata a Claude, upload, ZIP) blocchi tutte le altre dello stesso browser
session_write_close();

$action = $_POST['action'] ?? ($_GET['action'] ?? '');

function jsonOut(array $data): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function fail(string $error): void {
    jsonOut(['ok' => false, 'error' => $error]);
}

/** Legge un parametro POST che contiene un array JSON di stringhe. */
function postList(string $key): array {
    $list = json_decode((string)($_POST[$key] ?? '[]'), true);
    return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
}

function treePayload(): array {
    global $rootDir;
    $tree = scanProjectTree($rootDir);
    // Cartelle con un'installazione di TreeForge/Scaffolder: nella tendina Root non si possono scegliere
    $toolDirs = [];
    foreach ($tree['dirs'] as $d) {
        if ($d !== '' && isToolDir($rootDir . '/' . $d)) $toolDirs[] = $d;
    }
    return ['ok' => true, 'root' => $rootDir, 'dirs' => $tree['dirs'], 'files' => $tree['files'], 'writable' => is_writable($rootDir), 'tool_dirs' => $toolDirs];
}

function filePayload(string $rel): array {
    global $rootDir;
    $abs  = $rootDir . '/' . $rel;
    $size = (int)filesize($abs);
    $out  = [
        'ok'       => true,
        'path'     => $rel,
        'size'     => $size,
        'writable' => is_writable($abs),
        'image'    => isImageFile($rel),
        'binary'   => !isAllowedFile($rel),
        'too_big'  => isAllowedFile($rel) && $size > MAX_EDIT_SIZE,
        'mode'     => aceModeFor($rel),
        'content'  => '',
        'mtime'    => filemtime($abs),
    ];
    if (!$out['binary'] && !$out['too_big']) $out['content'] = (string)@file_get_contents($abs);
    return $out;
}

/** Percorso di un file esistente e visibile, oppure ''. */
function existingFile(string $raw): string {
    global $rootDir;
    $rel = cleanRelPath($raw);
    return ($rel !== '' && is_file($rootDir . '/' . $rel) && isListedFile($rel)) ? $rel : '';
}

// ===================================================================
// LETTURA (GET, nessun token necessario)
// ===================================================================

switch ($action) {
    case 'get_tree':
        jsonOut(treePayload());

    case 'get_file': {
        $rel = existingFile($_GET['file'] ?? '');
        if ($rel === '') fail(t('api.file_not_found'));
        jsonOut(filePayload($rel));
    }

    case 'get_versions': {
        $rel = cleanRelPath($_GET['file'] ?? '');
        jsonOut(['ok' => true, 'versions' => $rel === '' ? [] : getVersions($rel)]);
    }

    case 'get_version': {
        $v = getVersion((int)($_GET['id'] ?? 0));
        jsonOut($v ? ['ok' => true, 'content' => $v['content'], 'path' => $v['path'], 'created_at' => $v['created_at']]
                   : ['ok' => false, 'error' => t('api.version_not_found')]);
    }

    case 'http_list': {
        $rows = db()->query('SELECT q.id, q.name, q.method, q.url, r.status, r.error, r.created_at AS ran_at
                             FROM http_requests q LEFT JOIN http_responses r ON r.request_id = q.id
                             ORDER BY q.name COLLATE NOCASE')->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['status'] = $row['status'] === null ? null : (int)$row['status'];
        }
        unset($row);
        jsonOut(['ok' => true, 'requests' => $rows]);
    }

    case 'http_get': {
        $r = httpGetRequest((int)($_GET['id'] ?? 0));
        if (!$r) fail(t('api.request_not_found'));
        jsonOut(['ok' => true, 'request' => httpPublicRequest($r), 'response' => httpGetResponse((int)$r['id'])]);
    }

    // Selettore della root: cartelle dello spazio web con permessi e stato
    case 'get_roots': {
        @set_time_limit(60);
        $scan = scanWorkspaceDirs();
        jsonOut(['ok' => true, 'current' => $rootDir, 'base' => $defaultRoot, 'items' => $scan['items'], 'truncated' => $scan['truncated']]);
    }

    case 'get_side':
        jsonOut(['ok' => true, 'logs' => getLogs(max(1, min(MAX_LOGS, (int)($_GET['limit'] ?? 80)))), 'trash' => getTrash()]);

    // Cerca nel contenuto dei file (con anteprima della sostituzione se arriva "replace")
    case 'search': {
        $o = [
            'q' => (string)($_GET['q'] ?? ''), 'case' => ($_GET['case'] ?? '') === '1', 'word' => ($_GET['word'] ?? '') === '1',
            'regex' => ($_GET['regex'] ?? '') === '1', 'include' => (string)($_GET['include'] ?? ''), 'exclude' => (string)($_GET['exclude'] ?? ''),
            'replace' => isset($_GET['replace']) ? (string)$_GET['replace'] : null,
        ];
        jsonOut(searchFiles($o));
    }

    // Log degli errori PHP: sorgenti disponibili + errori (raggruppati) della sorgente scelta
    case 'error_logs': {
        $sources = errorLogSources();
        $src = null;
        foreach ($sources as $s) if ($s['id'] === ($_GET['source'] ?? '')) $src = $s;
        if (!$src && $sources) $src = $sources[0];
        $data = $src ? parseErrorLog(tailLines($src['path'], ERROR_LOG_TAIL), max(10, min(500, (int)($_GET['limit'] ?? 200))))
                     : ['groups' => [], 'entries' => 0];
        jsonOut(['ok' => true, 'sources' => $sources, 'source' => $src ? $src['id'] : null, 'groups' => $data['groups'],
                 'entries' => $data['entries'], 'site_log' => siteLogStatus(), 'sapi' => PHP_SAPI]);
    }

    // Anteprima immagini: il file viene servito con il suo MIME, gli SVG in sandbox (niente script)
    case 'raw': {
        $rel = existingFile($_GET['file'] ?? '');
        if ($rel === '' || !isImageFile($rel)) { http_response_code(404); exit; }
        $abs = $rootDir . '/' . $rel;
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        $mime = $ext === 'svg' ? 'image/svg+xml' : ((string)@mime_content_type($abs) ?: 'application/octet-stream');
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
        header('Cache-Control: no-store');
        header('Content-Length: ' . filesize($abs));
        readfile($abs);
        exit;
    }

    // Download di un file, oppure di una cartella come ZIP
    case 'download': {
        $rel = cleanRelPath($_GET['path'] ?? '');
        $abs = $rootDir . '/' . $rel;
        // Niente buffer di output: un file da 1 GB deve passare a flusso, non in memoria
        @set_time_limit(0);
        while (ob_get_level()) ob_end_clean();
        if ($rel !== '' && is_file($abs) && isListedFile($rel)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . str_replace('"', '', basename($rel)) . '"');
            header('Content-Length: ' . filesize($abs));
            header('Cache-Control: no-store');
            readfile($abs);
            exit;
        }
        if (is_dir($abs) && class_exists('ZipArchive')) {
            @set_time_limit(300);
            $tmp = DATA_DIR . '/tmp_' . bin2hex(random_bytes(6)) . '.zip';
            $n = zipDirectory($abs, $tmp, ['node_modules', '.git']);
            if ($n < 0) { http_response_code(500); exit(t('api.zip_failed')); }
            $name = ($rel === '' ? basename($rootDir) : basename($rel)) . '.zip';
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
            header('Content-Length: ' . filesize($tmp));
            header('Cache-Control: no-store');
            readfile($tmp);
            @unlink($tmp);
            exit;
        }
        http_response_code(404);
        exit(t('api.item_not_found'));
    }
}

// ===================================================================
// SCRITTURA (POST + token CSRF)
// ===================================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}
requireCsrf();

switch ($action) {

    // A. SPOSTA UNO O PIÙ ELEMENTI IN UNA CARTELLA
    case 'move_items': {
        $targetDir = cleanRelPath($_POST['target_dir'] ?? '');
        if ($targetDir !== '' && !is_dir($rootDir . '/' . $targetDir)) fail(t('api.target_invalid'));

        $moved = []; $errors = [];
        foreach (postList('sources') as $raw) {
            $sourceRel = cleanRelPath($raw);
            $sourceAbs = $rootDir . '/' . $sourceRel;
            if ($sourceRel === '' || !file_exists($sourceAbs)) { $errors[] = "$raw: " . t('api.not_found'); continue; }

            $baseName = basename($sourceRel);
            $newRel   = ltrim($targetDir . '/' . $baseName, '/');
            if ($newRel === $sourceRel) continue;
            if (is_dir($sourceAbs) && ($targetDir === $sourceRel || strpos($targetDir . '/', $sourceRel . '/') === 0)) {
                $errors[] = "$sourceRel: " . t('api.move_into_itself');
                continue;
            }
            if (file_exists($rootDir . '/' . $newRel)) { $errors[] = t('api.exists_in_target', ['name' => $baseName]); continue; }
            if (@rename($sourceAbs, $rootDir . '/' . $newRel)) {
                addLog('move', "$sourceRel ➔ " . ($targetDir === '' ? '/ (Root)' : $newRel));
                $moved[] = ['old_path' => $sourceRel, 'new_path' => $newRel, 'is_dir' => is_dir($rootDir . '/' . $newRel)];
            } else {
                $errors[] = "$sourceRel: " . t('err.no_permission');
            }
        }
        if ($errors) addLog('error', t('log.move_errors') . "\n" . implode("\n", $errors));
        jsonOut(['ok' => !$errors || $moved, 'moved' => $moved, 'errors' => $errors, 'error' => implode("\n", $errors)]);
    }

    // B. RINOMINA FILE O CARTELLA
    case 'rename_item': {
        $oldRel  = cleanRelPath($_POST['old_path'] ?? '');
        $newName = trim((string)($_POST['new_name'] ?? ''));
        $oldAbs  = $rootDir . '/' . $oldRel;

        if ($oldRel === '' || $newName === '' || $newName !== basename($newName) || $newName === '.' || $newName === '..') {
            fail(t('api.name_invalid_slash'));
        }
        if (!file_exists($oldAbs)) fail(t('api.item_not_found'));
        // Se per un file si scrive solo il nome, si mantiene l'estensione originale ("pagina" → "pagina.php")
        $oldExt = pathinfo($oldRel, PATHINFO_EXTENSION);
        if (is_file($oldAbs) && $oldExt !== '' && basename($oldRel)[0] !== '.' && $newName[0] !== '.' && pathinfo($newName, PATHINFO_EXTENSION) === '') {
            $newName = rtrim($newName, '.') . '.' . $oldExt;
        }

        $parentDir = dirname($oldRel);
        $parentDir = ($parentDir === '.' || $parentDir === '/') ? '' : $parentDir;
        $newRel    = cleanRelPath(ltrim($parentDir . '/' . $newName, '/'));
        $newAbs    = $rootDir . '/' . $newRel;

        if ($newRel === '') fail(t('api.name_invalid'));
        if ($newRel === $oldRel) jsonOut(['ok' => true, 'old_path' => $oldRel, 'new_path' => $newRel, 'unchanged' => true]);
        if (is_file($oldAbs) && !isListedFile($newRel)) {
            addLog('error', t('api.ext_not_allowed') . ": $newName");
            fail(t('api.ext_not_allowed'));
        }
        // Su macOS il filesystem ignora maiuscole/minuscole: "a.php" → "A.php" è una rinomina valida
        if (file_exists($newAbs) && strcasecmp($newRel, $oldRel) !== 0) {
            addLog('error', t('log.rename_exists', ['path' => $newRel]));
            fail(t('api.name_exists', ['name' => $newName]));
        }
        if (@rename($oldAbs, $newAbs)) {
            addLog('move', t('log.renamed') . ": $oldRel ➔ $newRel");
            jsonOut(['ok' => true, 'old_path' => $oldRel, 'new_path' => $newRel, 'is_dir' => is_dir($newAbs)]);
        }
        addLog('error', t('log.rename_permission', ['path' => $oldRel]));
        fail(t('api.rename_failed'));
    }

    // B2. DUPLICA FILE O CARTELLE accanto all'originale ("pagina copy.php", "pagina copy 2.php", "cartella copy")
    case 'duplicate_items': {
        $done = []; $errors = [];
        foreach (postList('paths') as $raw) {
            $rel = cleanRelPath($raw);
            $abs = $rootDir . '/' . $rel;
            if ($rel === '' || !file_exists($abs)) { $errors[] = "$raw: " . t('api.not_found'); continue; }
            $dir = dirname($rel) === '.' ? '' : dirname($rel);
            $base = basename($rel);
            $ext = is_file($abs) && $base[0] !== '.' ? pathinfo($base, PATHINFO_EXTENSION) : '';
            $stem = $ext !== '' ? substr($base, 0, -strlen($ext) - 1) : $base;
            $newRel = uniqueName($dir, $stem . ' ' . t('dup.suffix'), $ext !== '' ? '.' . $ext : '');
            if (!copyRecursive($abs, $rootDir . '/' . $newRel)) {
                $errors[] = "$rel: " . t('err.no_permission');
                if (is_file($rootDir . '/' . $newRel) || is_dir($rootDir . '/' . $newRel)) continue;
            }
            addLog('create', t('log.duplicated', ['from' => $rel, 'to' => $newRel]));
            $done[] = ['path' => $newRel, 'is_dir' => is_dir($rootDir . '/' . $newRel)];
        }
        if ($errors) addLog('error', t('log.duplicate_errors') . "\n" . implode("\n", $errors));
        jsonOut(['ok' => (bool)$done, 'items' => $done, 'errors' => $errors, 'error' => implode("\n", $errors)]);
    }

    // C. CREA CARTELLA
    case 'create_folder': {
        $parent    = cleanRelPath($_POST['parent_dir'] ?? '');
        $targetRel = cleanRelPath(ltrim($parent . '/' . ($_POST['folder_name'] ?? ''), '/'));
        if ($targetRel === '' || $targetRel === $parent) fail(t('api.folder_name_invalid'));
        if (file_exists($rootDir . '/' . $targetRel)) fail(t('api.exists', ['path' => "/$targetRel"]));
        if (!@mkdir($rootDir . '/' . $targetRel, 0777, true)) {
            addLog('error', t('api.cannot_create', ['path' => "/$targetRel"]));
            fail(t('api.cannot_create', ['path' => "/$targetRel"]));
        }
        addLog('create', t('log.folder') . " /$targetRel");
        jsonOut(['ok' => true, 'path' => $targetRel]);
    }

    // D. CREA FILE
    case 'create_file': {
        $targetDir = cleanRelPath($_POST['target_dir'] ?? '');
        $relFile   = cleanRelPath(ltrim($targetDir . '/' . ($_POST['file_name'] ?? ''), '/'));
        if ($relFile === '' || $relFile === $targetDir) fail(t('api.file_name_invalid'));
        $res = createFileWithTemplate($relFile);
        if ($res === 'exists') fail(t('api.exists', ['path' => $relFile]));
        if ($res !== 'created') {
            addLog('error', "File $relFile: $res");
            fail(t('api.cannot_create_reason', ['path' => $relFile, 'reason' => $res]));
        }
        addLog('create', t('log.file') . " $relFile");
        jsonOut(['ok' => true, 'path' => $relFile]);
    }

    // E. SALVA FILE
    case 'save_file': {
        $fileToSave = cleanRelPath($_POST['filepath'] ?? '');
        $fullPath   = $rootDir . '/' . $fileToSave;
        if ($fileToSave === '' || !is_file($fullPath) || !isAllowedFile($fileToSave)) fail(t('api.file_invalid'));
        $old = (string)@file_get_contents($fullPath);
        $new = (string)($_POST['code'] ?? '');
        clearstatcache(true, $fullPath);
        $diskMtime = (int)@filemtime($fullPath);
        if ($old === $new) jsonOut(['ok' => true, 'unchanged' => true, 'mtime' => $diskMtime]);
        // Il file è cambiato sul disco dopo l'apertura (FTP, altro utente, altro programma)? Data diversa E contenuto
        // diverso da quello che l'editor aveva caricato (crc32 del testo): si sovrascrive solo se l'utente conferma
        $clientMtime = (int)($_POST['mtime'] ?? 0);
        $baseCrc     = (string)($_POST['base_crc'] ?? '');
        if (($_POST['force_mtime'] ?? '0') !== '1' && $clientMtime > 0 && $diskMtime !== $clientMtime
            && ($baseCrc === '' || $baseCrc !== sprintf('%u', crc32($old)))) {
            jsonOut(['ok' => false, 'conflict' => true, 'mtime' => $diskMtime, 'disk_time' => date('Y-m-d H:i:s', $diskMtime),
                     'error' => t('api.save_conflict', ['time' => date('H:i:s', $diskMtime)])]);
        }
        // Un .php con errori di sintassi manda il sito in errore 500: si salva solo se l'utente conferma (force=1)
        if (isPhpFile($fileToSave) && !empty(loadConfig()['php_lint']) && ($_POST['force'] ?? '0') !== '1'
            && ($lint = phpLint($new, $fileToSave))) {
            jsonOut(['ok' => false, 'error' => t('api.syntax_error_line', ['line' => $lint['line']]), 'lint' => $lint, 'server_php' => PHP_VERSION]);
        }
        if (@file_put_contents($fullPath, $new) === false) {
            addLog('error', t('api.cannot_write', ['path' => $fileToSave]));
            fail(t('api.cannot_write', ['path' => $fileToSave]));
        }
        // "claude": il salvataggio contiene modifiche proposte da Claude e applicate nell'editor
        $reason = ($_POST['reason'] ?? '') === 'claude' ? 'claude' : 'save';
        saveVersion($fileToSave, $old, $reason);
        addLog('save', t($reason === 'claude' ? 'log.saved_claude' : 'log.saved', ['path' => $fileToSave])
            . (($_POST['force'] ?? '0') === '1' && isPhpFile($fileToSave) ? ' — ' . t('log.saved_despite_error') : '')
            . (($_POST['force_mtime'] ?? '0') === '1' ? ' — ' . t('log.saved_over_disk') : ''));
        clearstatcache(true, $fullPath);
        jsonOut(['ok' => true, 'saved_at' => date('H:i:s'), 'mtime' => (int)@filemtime($fullPath)]);
    }

    // E2. CONTROLLO DELLA SINTASSI PHP (mentre si scrive: solo annotazioni nell'editor)
    case 'php_lint': {
        $path = cleanRelPath($_POST['path'] ?? '') ?: 'file.php';
        jsonOut(['ok' => true, 'lint' => phpLint((string)($_POST['code'] ?? ''), $path)]);
    }

    // E3. SOSTITUISCI NEI FILE: tutte le occorrenze dei file indicati, o solo alcune (riga + colonna)
    case 'replace': {
        $o = json_decode((string)($_POST['opts'] ?? '{}'), true);
        if (!is_array($o)) fail(t('api.invalid_data'));
        [$pattern, $err] = searchPattern($o);
        if ($err) fail($err);
        $replace = (string)($_POST['replace'] ?? '');
        $targets = json_decode((string)($_POST['targets'] ?? '[]'), true);
        if (!is_array($targets) || !$targets) fail(t('api.nothing_to_replace'));
        @set_time_limit(120);
        $lintOn = !empty(loadConfig()['php_lint']) && ($_POST['force'] ?? '0') !== '1';
        $done = []; $errors = []; $total = 0;
        foreach ($targets as $t) {
            $rel = existingFile((string)($t['path'] ?? ''));
            if ($rel === '' || !isAllowedFile($rel)) { $errors[] = ($t['path'] ?? '?') . ': ' . t('api.file_invalid'); continue; }
            $abs = $rootDir . '/' . $rel;
            if ((int)filesize($abs) > MAX_EDIT_SIZE) { $errors[] = "$rel: " . t('api.too_big'); continue; }
            $content = (string)@file_get_contents($abs);
            [$new, $n] = replaceInContent($content, $o, $replace, isset($t['only']) && is_array($t['only']) ? $t['only'] : null);
            if ($n === 0 || $new === $content) { $errors[] = "$rel: " . t('api.occurrence_missing'); continue; }
            if ($lintOn && isPhpFile($rel) && ($lint = phpLint($new, $rel)) && !phpLint($content, $rel)) {
                $errors[] = "$rel: " . t('api.replace_syntax', ['line' => $lint['line']]);
                continue;
            }
            if (@file_put_contents($abs, $new) === false) { $errors[] = "$rel: " . t('err.no_permission'); continue; }
            saveVersion($rel, $content, 'replace');
            $done[] = ['path' => $rel, 'count' => $n];
            $total += $n;
        }
        $q = mb_substr((string)($o['q'] ?? ''), 0, 60);
        if ($done) addLog('save', t('log.replaced', ['q' => $q, 'r' => mb_substr($replace, 0, 60), 'n' => $total, 'files' => count($done)]) . "\n"
            . implode("\n", array_map(function ($d) { return "{$d['path']} ({$d['count']})"; }, array_slice($done, 0, 20))));
        if ($errors) addLog('error', t('log.replace_errors') . "\n" . implode("\n", array_slice($errors, 0, 15)));
        jsonOut(['ok' => (bool)$done || !$errors, 'files' => $done, 'total' => $total, 'errors' => $errors, 'error' => implode("\n", $errors)]);
    }

    // E4. APPLICA UNA PROPOSTA DI CLAUDE (modifica o nuovo file). save=1 scrive subito,
    //     save=0 la lascia nell'editor da salvare (qui si crea solo l'eventuale nuovo file, vuoto).
    case 'claude_apply': {
        $type = ($_POST['type'] ?? '') === 'new' ? 'new' : 'edit';
        $rel  = cleanRelPath($_POST['path'] ?? '');
        $old  = (string)($_POST['old'] ?? '');
        $new  = (string)($_POST['new'] ?? '');
        if (($_POST['save'] ?? '0') !== '1') {
            if ($type === 'new') {
                $chk = claudeCheckProposal('new', $rel, '', $new);
                if (isset($chk['error'])) fail($chk['error']);
                $abs = $rootDir . '/' . $rel;
                if ((!is_dir(dirname($abs)) && !@mkdir(dirname($abs), 0777, true)) || @file_put_contents($abs, '') === false) fail(t('api.cannot_create', ['path' => $rel]));
                @chmod($abs, 0666);
                addLog('create', t('log.claude_new_editor', ['path' => $rel]));
            } else {
                if ($rel === '' || !isAllowedFile($rel)) fail(t('api.file_invalid'));
                addLog('save', t('log.claude_edit_editor', ['path' => $rel]));
            }
            jsonOut(['ok' => true, 'saved' => false, 'path' => $rel]);
        }
        $chk = claudeCheckProposal($type, $rel, $old, $new);
        if (isset($chk['error'])) fail($chk['error']);
        if (isPhpFile($rel) && !empty(loadConfig()['php_lint']) && ($_POST['force'] ?? '0') !== '1'
            && ($lint = phpLint($chk['content'], $rel)) && ($type === 'new' || !phpLint($chk['old_content'], $rel))) {
            jsonOut(['ok' => false, 'error' => t('api.syntax_error_line', ['line' => $lint['line']]), 'lint' => $lint, 'server_php' => PHP_VERSION]);
        }
        $abs = $rootDir . '/' . $rel;
        if ($type === 'edit') saveVersion($rel, $chk['old_content'], 'claude');
        if (!is_dir(dirname($abs)) && !@mkdir(dirname($abs), 0777, true)) fail(t('api.cannot_create', ['path' => dirname($rel)]));
        if (@file_put_contents($abs, $chk['content']) === false) {
            addLog('error', 'Claude: ' . t('api.cannot_write', ['path' => $rel]));
            fail(t('api.cannot_write', ['path' => $rel]));
        }
        @chmod($abs, 0666);
        addLog($type === 'new' ? 'create' : 'save', t($type === 'new' ? 'log.claude_created' : 'log.claude_changed', ['path' => $rel]));
        jsonOut(['ok' => true, 'saved' => true, 'path' => $rel, 'content' => $chk['content']]);
    }

    // E5. LOG DEGLI ERRORI PHP: svuota (solo file dentro la root) / attiva il log del sito con .user.ini
    case 'error_log_clear': {
        $src = errorLogSource((string)($_POST['source'] ?? ''));
        if (!$src) fail(t('api.log_not_found'));
        if (!$src['in_root']) fail(t('api.log_outside_site'));
        if (@file_put_contents($src['path'], '') === false) fail(t('api.log_clear_permission'));
        addLog('delete', t('log.error_log_cleared') . ': ' . $src['display']);
        jsonOut(['ok' => true]);
    }

    case 'error_log_site': {
        $res = setSiteLog(($_POST['on'] ?? '0') === '1');
        if ($res !== true) fail($res);
        jsonOut(['ok' => true, 'site_log' => siteLogStatus()]);
    }

    // F. ELIMINA FILE E CARTELLE (i file di testo finiscono nel cestino)
    case 'delete_items': {
        $deleted = []; $errors = [];
        foreach (postList('paths') as $raw) {
            $rel = cleanRelPath($raw);
            $abs = $rootDir . '/' . $rel;
            if ($rel === '' || !file_exists($abs)) continue;

            if (is_dir($abs)) {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    $fRel = substr($f->getPathname(), strlen($rootDir) + 1);
                    if ($f->isFile() && isListedFile($fRel) && $f->getSize() < MAX_EDIT_SIZE) {
                        saveVersion($fRel, (string)@file_get_contents($f->getPathname()), 'delete');
                    }
                }
                if (deleteDirRecursive($abs)) {
                    addLog('delete', t('log.deleted_folder', ['path' => "/$rel"]));
                    $deleted[] = ['path' => $rel, 'is_dir' => true];
                } else {
                    $errors[] = "/$rel: " . t('api.cannot_delete');
                }
            } else {
                if (filesize($abs) < MAX_EDIT_SIZE) saveVersion($rel, (string)@file_get_contents($abs), 'delete');
                if (@unlink($abs)) {
                    addLog('delete', t('log.deleted_file', ['path' => $rel]));
                    $deleted[] = ['path' => $rel, 'is_dir' => false];
                } else {
                    $errors[] = "$rel: " . t('err.no_permission');
                }
            }
        }
        if ($errors) addLog('error', t('log.delete_errors') . "\n" . implode("\n", $errors));
        jsonOut(['ok' => !$errors, 'deleted' => $deleted, 'error' => implode("\n", $errors)]);
    }

    // G. RIPRISTINA UNA VERSIONE (anche dal cestino)
    case 'restore_version': {
        $v = getVersion((int)($_POST['id'] ?? 0));
        $target = $v ? cleanRelPath($v['path']) : '';
        if (!$v || $target === '' || !isListedFile($target)) fail(t('api.version_invalid'));
        $fullPath = $rootDir . '/' . $target;
        if (is_file($fullPath)) {
            saveVersion($target, (string)@file_get_contents($fullPath), 'restore');
        } elseif (!is_dir(dirname($fullPath))) {
            @mkdir(dirname($fullPath), 0777, true);
        }
        if (@file_put_contents($fullPath, $v['content']) === false) {
            addLog('error', t('api.cannot_restore', ['path' => $target]));
            fail(t('api.cannot_restore', ['path' => $target]));
        }
        addLog('restore', t('log.restored', ['path' => $target, 'date' => $v['created_at']]));
        jsonOut(['ok' => true, 'path' => $target]);
    }

    case 'clear_logs': {
        db()->exec('DELETE FROM logs');
        jsonOut(['ok' => true]);
    }

    case 'empty_trash': {
        foreach (getTrash() as $t) {
            db()->prepare('DELETE FROM versions WHERE root = ? AND path = ?')->execute([$rootDir, $t['path']]);
        }
        addLog('delete', t('log.trash_emptied'));
        jsonOut(['ok' => true]);
    }

    // H. IMPOSTA LA ROOT DI LAVORO
    case 'set_root': {
        $want = realpath(trim((string)($_POST['root_folder'] ?? '')));
        if ($want !== false && insideToolDir($want)) fail(t('api.root_tool'));
        $real = validateRoot((string)($_POST['root_folder'] ?? ''));
        if ($real === null) {
            addLog('error', t('log.invalid_root') . ': ' . (string)($_POST['root_folder'] ?? ''));
            fail(t('api.root_invalid'));
        }
        setRootDir($real);
        addLog('create', t('log.root') . ": $real");
        jsonOut(['ok' => true, 'root' => $real]);
    }

    // J. SCARICA TUTTO (ZIP) DELLA ROOT — menu della riga root (form classico: risponde con il file)
    case 'backup_zip': {
        if (!class_exists('ZipArchive')) exit(t('api.no_zip'));
        @set_time_limit(300);
        $skip   = array_filter(array_map('trim', explode(',', (string)loadConfig()['backup_skip'])));
        // La cartella temporanea di sistema spesso non è scrivibile da Apache: si usa data/
        $tmpZip = DATA_DIR . '/tmp_' . bin2hex(random_bytes(6)) . '.zip';
        $count  = zipDirectory($rootDir, $tmpZip, $skip);
        if ($count < 0) exit("Impossibile creare l'archivio ZIP.");
        addLog('save', t('log.download_all', ['n' => $count]));
        while (ob_get_level()) ob_end_clean();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($rootDir) . '_' . date('Y-m-d_H-i') . '.zip"');
        header('Content-Length: ' . filesize($tmpZip));
        header('Cache-Control: no-store');
        readfile($tmpZip);
        @unlink($tmpZip);
        exit;
    }

    // K. CARICA FILE E CARTELLE (gli ZIP possono essere scompattati). Risponde con l'esito di ogni file.
    //    paths[] (JSON) = percorso relativo di ogni file trascinato, cartelle comprese; dirs[] = cartelle vuote
    case 'upload': {
        @set_time_limit(300);
        $targetDir = cleanRelPath($_POST['target_dir'] ?? '');
        $extract   = ($_POST['extract'] ?? '1') === '1';
        $overwrite = ($_POST['overwrite'] ?? '0') === '1';
        if ($targetDir !== '' && !is_dir($rootDir . '/' . $targetDir)) fail(t('api.target_invalid'));

        $results = [];
        foreach (postList('dirs') as $d) {
            $rel = cleanRelPath(ltrim($targetDir . '/' . $d, '/'));
            if ($rel === '') { $results[] = ['name' => $d, 'status' => 'error', 'msg' => t('zip.invalid_path')]; continue; }
            if (!is_dir($rootDir . '/' . $rel) && !@mkdir($rootDir . '/' . $rel, 0777, true)) {
                $results[] = ['name' => $d, 'status' => 'error', 'msg' => t('up.no_perm_folder')];
            }
        }

        if (empty($_FILES['files'])) {
            if ($results || postList('dirs')) jsonOut(['ok' => true, 'results' => $results]);
            fail(t('up.nothing_received', ['max' => ini_get('post_max_size')]));
        }

        $f     = $_FILES['files'];
        $names = postList('paths');
        $allowOverwrite = postList('overwrite_paths'); // file per cui l'utente ha confermato la sovrascrittura
        $errMsg = [
            UPLOAD_ERR_INI_SIZE   => t('up.exceeds', ['name' => 'upload_max_filesize', 'max' => ini_get('upload_max_filesize')]),
            UPLOAD_ERR_FORM_SIZE  => t('api.too_big'),
            UPLOAD_ERR_PARTIAL    => t('up.partial'),
            UPLOAD_ERR_NO_TMP_DIR => t('up.no_tmp'),
            UPLOAD_ERR_CANT_WRITE => t('up.cant_write'),
        ];
        for ($i = 0, $n = count($f['name']); $i < $n; $i++) {
            $name = str_replace('\\', '/', (string)($names[$i] ?? $f['name'][$i]));
            if ($f['error'][$i] !== UPLOAD_ERR_OK) {
                $results[] = ['name' => $name, 'status' => 'error', 'msg' => $errMsg[$f['error'][$i]] ?? ('error ' . $f['error'][$i])];
                continue;
            }
            $rel = cleanRelPath(ltrim($targetDir . '/' . $name, '/'));
            if ($rel === '') { $results[] = ['name' => $name, 'status' => 'error', 'msg' => t('up.invalid_name')]; continue; }

            if ($extract && strtolower(pathinfo($rel, PATHINFO_EXTENSION)) === 'zip') {
                $into = dirname($rel) === '.' ? '' : dirname($rel);
                $r = extractZipSafe($f['tmp_name'][$i], $into, $overwrite);
                addLog('create', t('log.zip_extracted', ['name' => $name, 'path' => "/$into", 'files' => $r['files'], 'dirs' => $r['dirs']]));
                if ($r['skipped']) addLog('error', t('log.zip_skipped', ['name' => $name]) . "\n" . implode("\n", array_slice($r['skipped'], 0, 15)));
                $results[] = [
                    'name' => $name,
                    'status' => $r['skipped'] ? ($r['files'] ? 'warning' : 'error') : 'ok',
                    'msg' => t('up.extracted', ['files' => $r['files'], 'dirs' => $r['dirs']]) . ($r['overwritten'] ? ', ' . t('up.overwritten_n', ['n' => $r['overwritten']]) : ''),
                    'details' => array_slice($r['skipped'], 0, 50),
                ];
                continue;
            }

            $abs = $rootDir . '/' . $rel;
            if (is_dir($abs)) { $results[] = ['name' => $name, 'status' => 'error', 'msg' => t('zip.folder_exists')]; continue; }
            $replaced = false;
            if (file_exists($abs)) {
                if (!$overwrite && !in_array($name, $allowOverwrite, true)) { $results[] = ['name' => $name, 'status' => 'error', 'msg' => t('up.exists_kept'), 'exists' => true]; continue; }
                if (isAllowedFile($rel) && filesize($abs) < MAX_EDIT_SIZE) saveVersion($rel, (string)@file_get_contents($abs), 'save');
                $replaced = true;
            }
            if (!is_dir(dirname($abs)) && !@mkdir(dirname($abs), 0777, true)) {
                $results[] = ['name' => $name, 'status' => 'error', 'msg' => t('up.no_perm_folder')];
                continue;
            }
            if (!@move_uploaded_file($f['tmp_name'][$i], $abs)) {
                $results[] = ['name' => $name, 'status' => 'error', 'msg' => t('up.no_perm_file')];
                continue;
            }
            @chmod($abs, 0666);
            $results[] = ['name' => $name, 'status' => 'ok', 'msg' => $replaced ? t('up.overwritten') : t('up.uploaded'), 'replaced' => $replaced];
        }

        $ok  = count(array_filter($results, function ($r) { return $r['status'] === 'ok'; }));
        $bad = array_filter($results, function ($r) { return $r['status'] !== 'ok'; });
        if ($ok) addLog('create', t('log.uploaded', ['n' => $ok, 'path' => "/$targetDir"]));
        if ($bad) addLog('error', t('log.upload_errors', ['path' => "/$targetDir"]) . "\n" . implode("\n", array_map(function ($r) { return "{$r['name']}: {$r['msg']}"; }, array_slice($bad, 0, 15))));
        jsonOut(['ok' => true, 'results' => $results]);
    }

    // K1. CARICAMENTO A PEZZI — 1) inizio (o ripresa di un caricamento interrotto dello stesso file)
    case 'upload_start': {
        jobCleanup();
        $targetDir = cleanRelPath($_POST['target_dir'] ?? '');
        if ($targetDir !== '' && !is_dir($rootDir . '/' . $targetDir)) fail(t('api.target_invalid'));
        $name = str_replace('\\', '/', (string)($_POST['rel'] ?? ''));
        $rel  = cleanRelPath(ltrim($targetDir . '/' . $name, '/'));
        if ($rel === '' || $rel === $targetDir) fail(t('up.invalid_name'));
        $size    = (int)($_POST['size'] ?? -1);
        $extract = ($_POST['extract'] ?? '0') === '1' && strtolower(pathinfo($rel, PATHINFO_EXTENSION)) === 'zip';
        $overwrite = ($_POST['overwrite'] ?? '0') === '1';
        if ($size < 0) fail(t('api.invalid_data'));
        if ($size > uploadMaxBytes()) fail(t('up.too_big_max', ['max' => humanSize(uploadMaxBytes())]));
        if ($extract && !class_exists('ZipArchive')) fail(t('api.no_zip'));
        $abs = $rootDir . '/' . $rel;
        if (!$extract && is_dir($abs)) fail(t('zip.folder_exists'));
        if (!$extract && file_exists($abs) && !$overwrite) jsonOut(['ok' => false, 'exists' => true, 'error' => t('up.exists_kept')]);
        if ($err = diskSpaceError($size, $extract)) fail($err);

        // Stesso file (nome, dimensione, data di modifica) già in parte caricato: si riprende da dove era arrivato
        $fp = sha1(jobOwner() . '|' . $rootDir . '|' . $rel . '|' . $size . '|' . (string)($_POST['mtime'] ?? ''));
        foreach (glob(DATA_DIR . '/up_*.json') ?: [] as $f) {
            $id = substr(basename($f, '.json'), 3);
            $meta = jobLoad('up', $id);
            if (!$meta || ($meta['fp'] ?? '') !== $fp || !is_file(jobFile('up', $id, 'part'))) continue;
            $meta['overwrite'] = $overwrite;
            $meta['extract'] = $extract;
            jobSave('up', $id, $meta);
            clearstatcache();
            jsonOut(['ok' => true, 'id' => $id, 'received' => (int)filesize(jobFile('up', $id, 'part')), 'resumed' => true]);
        }
        $id = bin2hex(random_bytes(16));
        if (@file_put_contents(jobFile('up', $id, 'part'), '') === false) fail(t('up.cant_write'));
        @chmod(jobFile('up', $id, 'part'), 0666);
        $meta = ['owner' => jobOwner(), 'fp' => $fp, 'root' => $rootDir, 'rel' => $rel, 'name' => $name, 'size' => $size,
                 'extract' => $extract, 'overwrite' => $overwrite, 'created' => time()];
        if (!jobSave('up', $id, $meta)) { @unlink(jobFile('up', $id, 'part')); fail(t('up.cant_write')); }
        jsonOut(['ok' => true, 'id' => $id, 'received' => 0]);
    }

    // K1. 2) un pezzo: si accetta solo se parte esattamente da dove è arrivato il file (niente buchi né doppioni)
    case 'upload_chunk': {
        @set_time_limit(120);
        $id = (string)($_POST['id'] ?? '');
        $meta = jobLoad('up', $id);
        $part = jobFile('up', $id, 'part');
        if (!$meta || !is_file($part)) jsonOut(['ok' => false, 'gone' => true, 'error' => t('up.job_gone')]);
        $c = $_FILES['chunk'] ?? null;
        if (!$c || $c['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($c['tmp_name'])) {
            fail(empty($c) ? t('up.nothing_received', ['max' => ini_get('post_max_size')]) : t('up.partial'));
        }
        $offset = (int)($_POST['offset'] ?? -1);
        $fh = @fopen($part, 'ab');
        if (!$fh) fail(t('up.cant_write'));
        flock($fh, LOCK_EX);
        clearstatcache(true, $part);
        $received = (int)fstat($fh)['size'];
        if ($offset !== $received) {
            flock($fh, LOCK_UN); fclose($fh);
            jsonOut(['ok' => false, 'resync' => true, 'received' => $received]);
        }
        if ($received + (int)$c['size'] > (int)$meta['size']) {
            flock($fh, LOCK_UN); fclose($fh);
            fail(t('up.chunk_too_big'));
        }
        $in = @fopen($c['tmp_name'], 'rb');
        $copied = $in ? stream_copy_to_stream($in, $fh) : false;
        if ($in) fclose($in);
        fflush($fh);
        if ($copied === false || $copied !== (int)$c['size']) {
            ftruncate($fh, $received);   // pezzo scritto solo in parte (disco pieno?): si torna indietro
            flock($fh, LOCK_UN); fclose($fh);
            fail(t('up.cant_write'));
        }
        flock($fh, LOCK_UN);
        fclose($fh);
        @touch(jobFile('up', $id, 'json'));   // attività recente: non è un caricamento abbandonato
        jsonOut(['ok' => true, 'received' => $received + $copied]);
    }

    // K1. 3) a che punto è un caricamento (ripresa dopo un errore di rete)
    case 'upload_status': {
        $id = (string)($_POST['id'] ?? '');
        $meta = jobLoad('up', $id);
        if (!$meta || !is_file(jobFile('up', $id, 'part'))) jsonOut(['ok' => false, 'gone' => true, 'error' => t('up.job_gone')]);
        clearstatcache();
        jsonOut(['ok' => true, 'received' => (int)filesize(jobFile('up', $id, 'part')), 'size' => (int)$meta['size']]);
    }

    // K1. 4) fine: il file completo va al suo posto (oppure, se è uno ZIP da scompattare, parte lo scompattamento a passi)
    case 'upload_finish': {
        @set_time_limit(300);
        $id = (string)($_POST['id'] ?? '');
        $meta = jobLoad('up', $id);
        $part = jobFile('up', $id, 'part');
        if (!$meta || !is_file($part)) fail(t('up.job_gone'));
        clearstatcache();
        if ((int)filesize($part) !== (int)$meta['size']) fail(t('up.incomplete'));
        if ($meta['root'] !== $rootDir) fail(t('up.root_changed'));
        $rel = cleanRelPath($meta['rel']);
        if ($rel === '') fail(t('up.invalid_name'));
        $abs = $rootDir . '/' . $rel;
        $into = dirname($rel) === '.' ? '' : dirname($rel);
        if (!empty($meta['extract'])) {
            if ($into !== '' && !is_dir($rootDir . '/' . $into) && !@mkdir($rootDir . '/' . $into, 0777, true)) fail(t('up.no_perm_folder'));
            $job = unzipJobCreate($part, $into, !empty($meta['overwrite']), true, (string)$meta['name']);
            if (!$job) { @unlink($part); @unlink(jobFile('up', $id, 'json')); fail(t('zip.invalid')); }
            @unlink(jobFile('up', $id, 'json'));
            jsonOut(['ok' => true, 'path' => $rel] + $job);
        }
        if (is_dir($abs)) fail(t('zip.folder_exists'));
        $replaced = file_exists($abs);
        if ($replaced) {
            if (empty($meta['overwrite'])) fail(t('up.exists_kept'));
            if (isAllowedFile($rel) && filesize($abs) < MAX_EDIT_SIZE) saveVersion($rel, (string)@file_get_contents($abs), 'save');
        }
        if (!is_dir(dirname($abs)) && !@mkdir(dirname($abs), 0777, true)) fail(t('up.no_perm_folder'));
        // Stesso disco: rename istantaneo; altrimenti copia e cancella
        if (!@rename($part, $abs)) {
            if (!@copy($part, $abs)) fail(t('up.no_perm_file'));
            @unlink($part);
        }
        @chmod($abs, 0666);
        @unlink(jobFile('up', $id, 'json'));
        addLog('create', t('log.uploaded_big', ['path' => $rel, 'size' => humanSize((int)$meta['size'])]) . ($replaced ? ' — ' . t('up.overwritten') : ''));
        jsonOut(['ok' => true, 'path' => $rel, 'replaced' => $replaced, 'msg' => $replaced ? t('up.overwritten') : t('up.uploaded')]);
    }

    // K1. 5) annulla: niente pezzi lasciati in data/
    case 'upload_cancel': {
        $id = (string)($_POST['id'] ?? '');
        if (jobLoad('up', $id)) {
            @unlink(jobFile('up', $id, 'part'));
            @unlink(jobFile('up', $id, 'json'));
        }
        jsonOut(['ok' => true]);
    }

    // K1b. SCOMPATTAMENTO A PASSI: estrae le voci per una decina di secondi e dice a che punto è
    case 'unzip_step': {
        $id = (string)($_POST['job'] ?? '');
        $job = jobLoad('unz', $id);
        if (!$job) fail(t('up.job_gone'));
        if ($job['root'] !== $rootDir) fail(t('up.root_changed'));
        if (($_POST['cancel'] ?? '0') === '1') {
            if (!empty($job['delete_zip'])) @unlink($job['zip']);
            @unlink(jobFile('unz', $id, 'json'));
            jsonOut(['ok' => true, 'done' => true, 'cancelled' => true]);
        }
        $max = (int)ini_get('max_execution_time');
        $budget = $max > 0 ? max(2, min(10, $max / 3)) : 10;
        @set_time_limit($max > 0 ? max($max, 60) : 0);
        $zip = new ZipArchive();
        if ($zip->open($job['zip']) !== true) fail(t('zip.invalid'));
        $res = $job['res'];
        $job['next'] = extractZipEntries($zip, (string)$job['target'], !empty($job['overwrite']), (int)$job['next'], microtime(true) + $budget, $res);
        $zip->close();
        $res['skipped'] = array_slice($res['skipped'], 0, 500);
        $job['res'] = $res;
        $done = $job['next'] >= $job['total'];
        $out = ['ok' => true, 'done' => $done, 'next' => $job['next'], 'total' => $job['total'], 'files' => $res['files'],
                'dirs' => $res['dirs'], 'overwritten' => $res['overwritten'], 'skipped_n' => count($res['skipped'])];
        if (!$done) {
            jobSave('unz', $id, $job);
            jsonOut($out);
        }
        addLog('create', t('log.zip_extracted', ['name' => $job['name'], 'path' => '/' . $job['target'], 'files' => $res['files'], 'dirs' => $res['dirs']]));
        if ($res['skipped']) addLog('error', t('log.zip_skipped', ['name' => $job['name']]) . "\n" . implode("\n", array_slice($res['skipped'], 0, 15)));
        if (!empty($job['delete_zip'])) @unlink($job['zip']);
        @unlink(jobFile('unz', $id, 'json'));
        jsonOut($out + ['skipped' => array_slice($res['skipped'], 0, 50), 'path' => $job['target']]);
    }

    // K2. COMPRIMI FILE/CARTELLE IN UNO ZIP (nella cartella del primo elemento)
    case 'zip_items': {
        if (!class_exists('ZipArchive')) fail(t('api.no_zip'));
        @set_time_limit(300);
        $rels = [];
        foreach (postList('paths') as $raw) {
            $rel = cleanRelPath($raw);
            if ($rel !== '' && file_exists($rootDir . '/' . $rel)) $rels[] = $rel;
        }
        if (!$rels) fail(t('api.nothing_to_zip'));
        $dir  = dirname($rels[0]) === '.' ? '' : dirname($rels[0]);
        $stem = count($rels) === 1 ? (is_dir($rootDir . '/' . $rels[0]) ? basename($rels[0]) : pathinfo($rels[0], PATHINFO_FILENAME)) : 'Archivio';
        $zipRel = uniqueName($dir, $stem, '.zip');
        // Si scrive prima in data/ e poi si sposta: così lo ZIP non include sé stesso
        $tmp = DATA_DIR . '/tmp_' . bin2hex(random_bytes(6)) . '.zip';
        $n = zipPaths($rels, $tmp);
        if ($n < 0) fail(t('api.zip_failed'));
        if (!@rename($tmp, $rootDir . '/' . $zipRel) && !(@copy($tmp, $rootDir . '/' . $zipRel) && @unlink($tmp))) {
            @unlink($tmp);
            addLog('error', 'ZIP: ' . t('api.cannot_create', ['path' => $zipRel]));
            fail(t('api.cannot_create', ['path' => $zipRel]));
        }
        @chmod($rootDir . '/' . $zipRel, 0666);
        addLog('create', t('log.zipped', ['path' => $zipRel, 'n' => $n]) . ': ' . implode(', ', $rels));
        jsonOut(['ok' => true, 'path' => $zipRel, 'files' => $n]);
    }

    // K3. SCOMPATTA UNO ZIP GIÀ PRESENTE NELL'EXPLORER (in una cartella con lo stesso nome)
    case 'unzip_item': {
        $rel = existingFile($_POST['path'] ?? '');
        if ($rel === '' || strtolower(pathinfo($rel, PATHINFO_EXTENSION)) !== 'zip') fail(t('api.select_zip'));
        @set_time_limit(300);
        $dir = dirname($rel) === '.' ? '' : dirname($rel);
        $target = uniqueName($dir, pathinfo($rel, PATHINFO_FILENAME), '');
        if (!@mkdir($rootDir . '/' . $target, 0777, true)) fail(t('api.cannot_create', ['path' => "/$target"]));
        // ZIP grandi: a passi (unzip_step), così nessuna richiesta supera max_execution_time
        if ((int)filesize($rootDir . '/' . $rel) > UNZIP_STEP_ABOVE) {
            $job = unzipJobCreate($rootDir . '/' . $rel, $target, false, false, $rel);
            if (!$job) fail(t('zip.invalid'));
            jsonOut(['ok' => true, 'path' => $target] + $job);
        }
        $r = extractZipSafe($rootDir . '/' . $rel, $target, false);
        addLog('create', t('log.zip_extracted', ['name' => $rel, 'path' => "/$target", 'files' => $r['files'], 'dirs' => $r['dirs']]));
        if ($r['skipped']) addLog('error', t('log.zip_skipped', ['name' => $rel]) . "\n" . implode("\n", array_slice($r['skipped'], 0, 15)));
        jsonOut(['ok' => true, 'path' => $target] + $r);
    }

    // K4. RICHIESTE API: salva (la chiave si cambia solo se ne arriva una nuova), elimina, esegui
    case 'http_save': {
        $in = json_decode((string)($_POST['request'] ?? '{}'), true);
        if (!is_array($in)) fail(t('api.invalid_data'));
        $id  = (int)($in['id'] ?? 0);
        $old = $id ? httpGetRequest($id) : null;
        if ($id && !$old) fail(t('api.request_not_found'));

        $method = strtoupper((string)($in['method'] ?? 'GET'));
        if (!in_array($method, HTTP_METHODS, true)) $method = 'GET';
        $headers = [];
        foreach ((array)($in['headers'] ?? []) as $h) {
            if (!is_array($h)) continue;
            $headers[] = ['name' => mb_substr(trim((string)($h['name'] ?? '')), 0, 200), 'value' => mb_substr((string)($h['value'] ?? ''), 0, 4000), 'on' => !empty($h['on'])];
        }
        $a = (array)($in['auth'] ?? []);
        $type = in_array($a['type'] ?? '', ['none', 'header', 'query', 'bearer'], true) ? $a['type'] : 'none';
        $value = (string)($a['value'] ?? '');
        if ($value === '' && $old && empty($a['clear'])) $value = (string)$old['auth']['value'];
        $auth = ['type' => $type, 'name' => mb_substr(trim((string)($a['name'] ?? '')), 0, 200), 'value' => $value];

        $data = [
            mb_substr(trim((string)($in['name'] ?? '')) ?: 'Nuova richiesta', 0, 200),
            $method,
            mb_substr(trim((string)($in['url'] ?? '')), 0, 4000),
            json_encode($headers, JSON_UNESCAPED_UNICODE),
            json_encode($auth, JSON_UNESCAPED_UNICODE),
            (string)($in['body'] ?? ''),
            json_encode(['insecure' => !empty($in['options']['insecure'])]),
        ];
        if ($id) {
            db()->prepare("UPDATE http_requests SET name = ?, method = ?, url = ?, headers = ?, auth = ?, body = ?, options = ?, updated_at = datetime('now','localtime') WHERE id = ?")
                ->execute(array_merge($data, [$id]));
        } else {
            db()->prepare('INSERT INTO http_requests (name, method, url, headers, auth, body, options) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute($data);
            $id = (int)db()->lastInsertId();
        }
        jsonOut(['ok' => true, 'request' => httpPublicRequest(httpGetRequest($id))]);
    }

    case 'http_delete': {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM http_responses WHERE request_id = ?')->execute([$id]);
        db()->prepare('DELETE FROM http_requests WHERE id = ?')->execute([$id]);
        jsonOut(['ok' => true]);
    }

    case 'http_run': {
        if (!function_exists('curl_init')) fail(t('api.no_curl'));
        $r = httpGetRequest((int)($_POST['id'] ?? 0));
        if (!$r) fail(t('api.request_not_found'));
        @set_time_limit(60);
        $resp = httpRun($r);
        if (isset($resp['error']) && !isset($resp['request_id'])) fail($resp['error']);
        jsonOut(['ok' => true, 'response' => $resp]);
    }

    // L. IMPOSTAZIONI GENERALI
    case 'save_settings': {
        $cfg = loadConfig();
        $in  = json_decode((string)($_POST['settings'] ?? '{}'), true);
        if (!is_array($in)) fail(t('api.invalid_data'));
        foreach (['dev_name', 'environment', 'backup_skip'] as $k) {
            if (isset($in[$k])) $cfg[$k] = mb_substr(trim((string)$in[$k]), 0, 200);
        }
        if (isset($in['default_root'])) {
            $dr = trim((string)$in['default_root']);
            if ($dr !== '' && validateRoot($dr) === null) fail(t('api.default_root_invalid'));
            $cfg['default_root'] = $dr === '' ? '' : validateRoot($dr);
        }
        if (isset($in['claude_model']) && isset(CLAUDE_MODELS[$in['claude_model']])) $cfg['claude_model'] = $in['claude_model'];
        if (isset($in['claude_effort']) && in_array($in['claude_effort'], ['low', 'medium', 'high', 'xhigh', 'max'], true)) $cfg['claude_effort'] = $in['claude_effort'];
        if (isset($in['editor_font'])) $cfg['editor_font'] = max(10, min(32, (int)$in['editor_font']));
        if (isset($in['upload_max_gb'])) $cfg['upload_max_gb'] = max(0.1, min(1024, round((float)$in['upload_max_gb'], 1)));
        if (isset($in['editor_wrap'])) $cfg['editor_wrap'] = (bool)$in['editor_wrap'];
        if (isset($in['show_right']))  $cfg['show_right']  = (bool)$in['show_right'];
        if (isset($in['language']) && isset(LANGUAGES[$in['language']])) $cfg['language'] = $in['language'];
        foreach (['php_lint', 'php_lint_live', 'claude_apply_save'] as $k) {
            if (isset($in[$k])) $cfg[$k] = (bool)$in[$k];
        }
        if (isset($in['error_log_path'])) {
            $p = trim((string)$in['error_log_path']);
            if ($p !== '' && ($p[0] !== '/' && !preg_match('#^[A-Za-z]:[\\/]#', $p))) fail(t('api.log_path_absolute'));
            if ($p !== '' && !is_readable($p)) fail(t('api.log_path_unreadable', ['path' => $p]));
            $cfg['error_log_path'] = mb_substr($p, 0, 500);
        }
        // La chiave si cambia solo se ne arriva una nuova; "remove_api_key" la cancella
        if (!empty($in['remove_api_key'])) $cfg['api_key'] = '';
        if (isset($in['api_key']) && trim((string)$in['api_key']) !== '') $cfg['api_key'] = trim((string)$in['api_key']);
        if (!saveConfig($cfg)) fail(t('api.config_write'));
        jsonOut(['ok' => true, 'config' => publicConfig(), 'limits' => serverLimits()]);
    }

    // M. RESETTA TUTTO: impostazioni, database (versioni, cestino, registro, richieste API, root) e file
    //    temporanei, come appena installato. Restano i file dei progetti e .htpasswd.
    case 'reset_all': {
        $paths = array_merge([CONFIG_FILE, DB_FILE, DB_FILE . '-wal', DB_FILE . '-shm', DB_FILE . '-journal'],
            glob(DATA_DIR . '/tmp_*') ?: [], glob(DATA_DIR . '/lint_*') ?: [], glob(DATA_DIR . '/up_*') ?: [], glob(DATA_DIR . '/unz_*') ?: []);
        // Database delle versioni precedenti (nome usato prima del cambio in TreeForge)
        foreach (glob(DATA_DIR . '/*.sqlite*') ?: [] as $p) $paths[] = $p;
        $errors = [];
        foreach (array_unique($paths) as $p) {
            if (file_exists($p) && !@unlink($p)) $errors[] = basename($p);
        }
        if ($errors) fail(t('api.reset_failed', ['files' => implode(', ', $errors)]));
        jsonOut(['ok' => true]);
    }

    // M2. PRIMO AVVIO: crea il primo utente in .htpasswd dall'avviso rosso (solo finché non esiste nessun utente)
    case 'setup_auth': {
        if (AUTH_ENABLED) fail(t('auth.already_on'));
        $user = trim((string)($_POST['user'] ?? ''));
        $pass = (string)($_POST['pass'] ?? '');
        if ($user === '' || !preg_match('/^[\p{L}\p{N}._@-]{1,64}$/u', $user)) fail(t('auth.user_invalid'));
        if (strlen($pass) < 8) fail(t('auth.pass_short', ['n' => 8]));
        $file = __DIR__ . '/.htpasswd';
        $text = "# TreeForge — " . t('auth.htpasswd_note') . "\n" . $user . ':' . password_hash($pass, PASSWORD_BCRYPT) . "\n";
        if (@file_put_contents($file, $text, LOCK_EX) === false) fail(t('auth.cannot_write', ['dir' => __DIR__]));
        @chmod($file, 0644);
        addLog('create', t('log.auth_enabled', ['user' => $user]));
        jsonOut(['ok' => true]);
    }

    // N. CHAT CON CLAUDE (la chiamata parte dal server: la API key non arriva mai al browser)
    case 'claude_chat': {
        $cfg = loadConfig();
        if ($cfg['api_key'] === '') fail(t('api.no_api_key'));
        if (!function_exists('curl_init')) fail(t('api.no_curl'));
        @set_time_limit(620);

        $model  = isset(CLAUDE_MODELS[$_POST['model'] ?? '']) ? $_POST['model'] : $cfg['claude_model'];
        $effort = in_array($_POST['effort'] ?? '', ['low', 'medium', 'high', 'xhigh', 'max'], true) ? $_POST['effort'] : $cfg['claude_effort'];
        $messages = json_decode((string)($_POST['messages'] ?? '[]'), true);
        if (!is_array($messages) || !$messages) fail(t('api.no_messages'));

        // Contesto: mappa del progetto + file scelti (le schede non salvate arrivano dal browser)
        $overrides = [];
        foreach ((array)(json_decode((string)($_POST['overrides'] ?? '{}'), true) ?: []) as $k => $v) {
            $rel = existingFile((string)$k);
            if ($rel !== '' && is_string($v) && isAllowedFile($rel)) $overrides[$rel] = $v;
        }
        $paths     = postList('context');
        if (($_POST['include_docs'] ?? '0') === '1') {
            foreach (scanProjectTree($rootDir)['files'] as $f) {
                if (preg_match('#^docs/.+\.md$#i', $f)) $paths[] = $f;
            }
        }
        $tree = scanProjectTree($rootDir);
        $ctx  = "Project root: $rootDir\n\n## Structure (folders and files)\n";
        foreach ($tree['dirs'] as $d) if ($d !== '') $ctx .= "$d/\n";
        foreach ($tree['files'] as $f) $ctx .= "$f\n";
        $budget = 400000;
        foreach (array_unique($paths) as $raw) {
            $rel = existingFile($raw);
            if ($rel === '' || !isAllowedFile($rel)) continue;
            $code = isset($overrides[$rel]) && is_string($overrides[$rel]) ? $overrides[$rel] : (string)@file_get_contents($rootDir . '/' . $rel);
            if (strlen($code) > $budget) { $ctx .= "\n(File $rel omitted: context too large)\n"; continue; }
            $budget -= strlen($code);
            $ctx .= "\n## File: $rel\n```" . pathinfo($rel, PATHINFO_EXTENSION) . "\n$code\n```\n";
        }
        // Ultime risposte delle richieste API scelte (senza header e senza chiave)
        foreach (array_unique(array_map('intval', postList('context_requests'))) as $rid) {
            $q = httpGetRequest($rid);
            $resp = $q ? httpGetResponse($rid) : null;
            if (!$q || !$resp) continue;
            $txt = (string)$resp['body'];
            $cut = strlen($txt) > HTTP_CLAUDE_MAX;
            if ($cut) $txt = substr($txt, 0, HTTP_CLAUDE_MAX);
            $ctx .= "\n## API response \"{$q['name']}\": {$q['method']} " . httpSafeUrl($q)
                  . " → HTTP {$resp['status']} ({$resp['created_at']}, " . humanSize((int)$resp['size']) . ($cut || $resp['truncated'] ? ', truncated' : '') . ")\n"
                  . ($resp['error'] ? "Error: {$resp['error']}\n" : "```\n$txt\n```\n");
        }
        $active = cleanRelPath($_POST['active'] ?? '');
        $who    = $cfg['dev_name'] !== '' ? "The developer's name is {$cfg['dev_name']}. " : '';
        $system = "You are the programming assistant built into TreeForge, a VS Code-style web editor for PHP sites on Apache. {$who}"
                . ($cfg['environment'] !== '' ? "Environment: {$cfg['environment']}. " : '')
                . 'Always reply in ' . LANGUAGES[uiLang()][1] . ' (the language of the user interface). '
                . "You have tools to list, read and search any file of the project and to read the responses of the API requests "
                . "saved by the user: use them whenever you need code or data that is not already in the context, instead of guessing. "
                . "To change code use proponi_modifica (replacement of an exact piece of text) or proponi_nuovo_file: the user sees the diff "
                . "and approves or discards each proposal, so do not repeat in your reply the code you already proposed with the tools; "
                . "briefly explain what you proposed. Before proposing a change read the relevant part of the file, so the text to replace "
                . "is identical. You cannot delete files or run commands. "
                . ($active !== '' ? "The file currently open in the foreground is $active." : '');

        $body = [
            'model'         => $model,
            'max_tokens'    => 16000,
            'system'        => [
                ['type' => 'text', 'text' => $system],
                ['type' => 'text', 'text' => "# CONTESTO DEL PROGETTO\n" . $ctx, 'cache_control' => ['type' => 'ephemeral']],
            ],
            'tools'         => claudeTools(),
            'messages'      => $messages,
            'output_config' => ['effort' => $effort],
        ];
        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $cfg['api_key'],
            'anthropic-version: 2023-06-01',
        ];
        // Se un classificatore di sicurezza rifiuta, il server riprova su un modello di riserva
        if (in_array($model, ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-fable-5-1'], true)) {
            $body['fallbacks'] = 'default';
            $headers[] = 'anthropic-beta: server-side-fallback-2026-07-01';
        }

        // Ciclo: Claude chiede di usare uno strumento → il server lo esegue → si rimanda il risultato,
        // finché Claude non dà la risposta finale. Tutti i messaggi nuovi tornano al browser per la cronologia.
        $added = [];
        $steps = [];
        $proposals = [];
        $text  = '';
        $usageIn = 0; $usageOut = 0;
        $resp = null;
        for ($step = 0; $step < CLAUDE_MAX_STEPS; $step++) {
            $ch = curl_init(ANTHROPIC_API_URL);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT        => 600,
            ]);
            $raw  = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cerr = curl_error($ch);
            if (PHP_VERSION_ID < 80000) curl_close($ch); // da PHP 8 si chiude da sola (curl_close è deprecata in 8.5)

            if ($raw === false) fail(t('api.network_anthropic') . ': ' . $cerr);
            $resp = json_decode((string)$raw, true);
            if ($code !== 200 || !is_array($resp)) {
                $msg = $resp['error']['message'] ?? ('HTTP ' . $code);
                addLog('error', "Claude: $msg");
                fail(t('api.api_error', ['code' => $code]) . ": $msg");
            }
            $usageIn  += (int)($resp['usage']['input_tokens'] ?? 0);
            $usageOut += (int)($resp['usage']['output_tokens'] ?? 0);
            $content = claudeHistoryContent($resp['content'] ?? []);
            $assistant = ['role' => 'assistant', 'content' => $content];
            $added[] = $assistant;
            $body['messages'][] = $assistant;

            $results = [];
            foreach ($content as $block) {
                if (($block['type'] ?? '') === 'text') $text .= $block['text'];
                if (($block['type'] ?? '') !== 'tool_use') continue;
                $run = claudeRunTool((string)$block['name'], is_array($block['input'] ?? null) ? $block['input'] : [], $overrides);
                [$out, $isErr, $label] = $run;
                $steps[] = ['tool' => $block['name'], 'label' => $label, 'error' => $isErr];
                if (!empty($run[3])) $proposals[] = ['id' => (string)$block['id']] + $run[3];
                $results[] = ['type' => 'tool_result', 'tool_use_id' => $block['id'], 'content' => $out, 'is_error' => $isErr];
            }
            if (($resp['stop_reason'] ?? '') !== 'tool_use' || !$results) break;
            $userTurn = ['role' => 'user', 'content' => $results];
            $added[] = $userTurn;
            $body['messages'][] = $userTurn;
            if ($text !== '') $text .= "\n\n";
        }
        if (($resp['stop_reason'] ?? '') === 'tool_use') {
            $text .= "\n\n_(" . t('claude.stopped_steps', ['n' => CLAUDE_MAX_STEPS]) . ")_";
        } elseif (($resp['stop_reason'] ?? '') === 'refusal') {
            $text .= "\n\n_(" . t('claude.refused') . (isset($resp['stop_details']['category']) ? ': ' . $resp['stop_details']['category'] : '') . ")_";
        } elseif (($resp['stop_reason'] ?? '') === 'max_tokens') {
            $text .= "\n\n_(" . t('claude.max_tokens') . ")_";
        }
        addLog('create', 'Claude (' . ($resp['model'] ?? $model) . "): $usageIn token in, $usageOut out" . ($steps ? ', ' . t('log.claude_tools', ['n' => count($steps)]) : '') . ($proposals ? ', ' . t('log.claude_proposals', ['n' => count($proposals)]) : ''));
        // I messaggi aggiunti (blocchi di ragionamento e strumenti inclusi) vanno rimandati tali e quali nei turni successivi
        jsonOut(['ok' => true, 'text' => trim($text), 'messages' => $added, 'steps' => $steps, 'proposals' => $proposals, 'model' => $resp['model'] ?? $model,
                 'usage' => ['input_tokens' => $usageIn, 'output_tokens' => $usageOut]]);
    }
}

fail(t('api.unknown_action'));
