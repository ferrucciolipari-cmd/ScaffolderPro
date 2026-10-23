<?php
// ===================================================================
// DATABASE SQLITE (data/scaffolder-pro.sqlite)
// ===================================================================

const DATA_DIR = __DIR__ . '/data';
const DB_FILE  = DATA_DIR . '/scaffolder-pro.sqlite';
const CONFIG_FILE = DATA_DIR . '/config.json';

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0777, true);
    }
    // Aggiornamento da una versione con il nome precedente: il database (versioni, registro, richieste API) si tiene
    if (!is_file(DB_FILE) && is_file(DATA_DIR . '/treeforge.sqlite') && is_writable(DATA_DIR)) {
        foreach (['', '-wal', '-shm'] as $sfx) {
            if (is_file(DATA_DIR . '/treeforge.sqlite' . $sfx)) @rename(DATA_DIR . '/treeforge.sqlite' . $sfx, DB_FILE . $sfx);
        }
    }
    $isNew = !is_file(DB_FILE);
    // Primo avvio dopo un clone/upload: senza permessi di scrittura su data/ si spiega cosa fare invece di dare Errore 500
    if (!extension_loaded('pdo_sqlite')) setupError(t('setup.no_sqlite'));
    if (!is_dir(DATA_DIR) || !is_writable(DATA_DIR) || (!$isNew && !is_writable(DB_FILE))) {
        $user = function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : '';
        setupError(t('setup.data_not_writable', ['user' => $user !== '' ? htmlspecialchars($user) : 'PHP', 'dir' => htmlspecialchars(DATA_DIR)]));
    }

    try {
        $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Throwable $e) {
        setupError(t('setup.db_error', ['error' => htmlspecialchars($e->getMessage())]));
    }
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 3000');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            key   TEXT PRIMARY KEY,
            value TEXT
        );
        CREATE TABLE IF NOT EXISTS logs (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            type       TEXT NOT NULL,
            text       TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS versions (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            root       TEXT NOT NULL,
            path       TEXT NOT NULL,
            content    BLOB NOT NULL,
            reason     TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );
        CREATE INDEX IF NOT EXISTS idx_versions_file ON versions(root, path, id);
        CREATE INDEX IF NOT EXISTS idx_versions_reason ON versions(root, reason, id);
        CREATE TABLE IF NOT EXISTS http_requests (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            name       TEXT NOT NULL,
            method     TEXT NOT NULL DEFAULT 'GET',
            url        TEXT NOT NULL DEFAULT '',
            headers    TEXT NOT NULL DEFAULT '[]',
            auth       TEXT NOT NULL DEFAULT '{}',
            body       TEXT NOT NULL DEFAULT '',
            updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );
        CREATE TABLE IF NOT EXISTS http_responses (
            request_id  INTEGER PRIMARY KEY,
            status      INTEGER,
            headers     TEXT,
            body        BLOB,
            size        INTEGER,
            truncated   INTEGER NOT NULL DEFAULT 0,
            duration_ms INTEGER,
            final_url   TEXT,
            error       TEXT,
            created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );
    ");

    // Colonne aggiunte dopo la prima versione: si creano anche sui database già esistenti
    $cols = array_column($pdo->query('PRAGMA table_info(http_requests)')->fetchAll(), 'name');
    if (!in_array('options', $cols, true)) {
        $pdo->exec("ALTER TABLE http_requests ADD COLUMN options TEXT NOT NULL DEFAULT '{}'");
    }

    if ($isNew) {
        @chmod(DB_FILE, 0666);
    }

    return $pdo;
}

/** Problema di installazione: pagina leggibile (o JSON per le chiamate dell'interfaccia) al posto di un Errore 500. */
function setupError(string $html): void {
    http_response_code(503);
    if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['ok' => false, 'error' => strip_tags($html)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    header('Content-Type: text/html; charset=utf-8');
    exit('<!DOCTYPE html><html lang="' . uiLang() . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Scaffolder Pro — ' . htmlspecialchars(t('setup.title')) . '</title><style>body{font:15px/1.6 -apple-system,Segoe UI,sans-serif;background:#1e1e1e;color:#ccc;margin:0;padding:40px 16px}'
        . 'main{max-width:680px;margin:auto;background:#252526;border:1px solid #c93c37;border-radius:8px;padding:20px 24px}h1{font-size:18px;color:#f48771;margin-top:0}'
        . 'code{background:#1e1e1e;border:1px solid #333;border-radius:3px;padding:1px 5px;color:#ce9178;word-break:break-all}</style></head>'
        . '<body><main><h1>' . htmlspecialchars(t('setup.heading')) . '</h1><p>' . $html . '</p></main></body></html>');
}

function getSetting(string $key, ?string $default = null): ?string {
    $st = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    $val = $st->fetchColumn();
    return $val === false ? $default : $val;
}

function setSetting(string $key, string $value): void {
    // INSERT OR REPLACE invece di "ON CONFLICT DO UPDATE": funziona anche con SQLite più vecchi di 3.24 (hosting datati)
    $st = db()->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)');
    $st->execute([$key, $value]);
}

/**
 * Importa una sola volta il vecchio registro JSON e i file .bak di /.backups/.
 * I backup vengono associati alla root predefinita (cartella padre).
 */
function importLegacyBackups(string $legacyRoot): void {
    if (getSetting('legacy_imported') === '1') return;

    $legacyDir = __DIR__ . '/.backups';
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $logFile = $legacyDir . '/.activity.log';
        if (is_file($logFile)) {
            $entries = json_decode((string)@file_get_contents($logFile), true);
            if (is_array($entries)) {
                $st = $pdo->prepare('INSERT INTO logs (type, text) VALUES (?, ?)');
                foreach (array_reverse($entries) as $e) {
                    $st->execute([(string)($e['type'] ?? 'save'), '[storico] ' . (string)($e['text'] ?? '')]);
                }
            }
        }

        $st = $pdo->prepare('INSERT INTO versions (root, path, content, reason, created_at) VALUES (?, ?, ?, ?, ?)');
        foreach (glob($legacyDir . '/*.bak') ?: [] as $bak) {
            // Formati: a__b.ext.20261020_205607.bak  oppure  a__b.ext.DELETED_20261020_205607.bak
            if (!preg_match('/^(.+)\.(DELETED_)?(\d{8})_(\d{6})\.bak$/', basename($bak), $m)) continue;
            $path   = str_replace('__', '/', $m[1]);
            $reason = $m[2] !== '' ? 'delete' : 'legacy';
            $when   = DateTime::createFromFormat('Ymd His', $m[3] . ' ' . $m[4]);
            $st->bindValue(1, $legacyRoot);
            $st->bindValue(2, $path);
            $st->bindValue(3, (string)@file_get_contents($bak), PDO::PARAM_LOB);
            $st->bindValue(4, $reason);
            $st->bindValue(5, $when ? $when->format('Y-m-d H:i:s') : date('Y-m-d H:i:s'));
            $st->execute();
        }

        setSetting('legacy_imported', '1');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
    }
}
