<?php
require_once __DIR__ . '/i18n.php';

// ===================================================================
// ACCESSO (HTTP Basic) & PROTEZIONE CSRF
// La password è controllata qui in PHP leggendo .htpasswd nella stessa cartella:
// nessun percorso assoluto in .htaccess, quindi la cartella funziona uguale
// in locale (XAMPP) e online (Plesk, hosting, PHP-FPM).
// Se .htpasswd non contiene utenti la protezione è disattivata (primo avvio).
// Il token CSRF impedisce a siti esterni di inviare azioni sfruttando
// le credenziali già memorizzate dal browser.
// ===================================================================

/** Utente e password inviati dal browser (mod_php li espone direttamente, FastCGI solo come header). */
function basicAuthCredentials(): array {
    $user = $_SERVER['PHP_AUTH_USER'] ?? null;
    $pass = $_SERVER['PHP_AUTH_PW'] ?? null;
    if ($user === null) {
        $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (stripos($h, 'basic ') === 0) {
            $decoded = (string)base64_decode(substr($h, 6), true);
            if (strpos($decoded, ':') !== false) [$user, $pass] = explode(':', $decoded, 2);
        }
    }
    return [(string)$user, (string)$pass];
}

/** Utenti di .htpasswd (righe "utente:hash"; vuote e commenti con # ignorati). */
function htpasswdUsers(): array {
    $users = [];
    $lines = @file(__DIR__ . '/.htpasswd', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, ':') === false) continue;
        [$name, $hash] = explode(':', $line, 2);
        if ($name !== '' && $hash !== '') $users[$name] = $hash;
    }
    return $users;
}

/** Confronta con .htpasswd (hash bcrypt, generati con: htpasswd -B). */
function checkHtpasswd(string $user, string $pass): bool {
    if ($user === '' || $pass === '') return false;
    foreach (htpasswdUsers() as $name => $hash) {
        if (hash_equals($name, $user)) return password_verify($pass, $hash);
    }
    return false;
}

// Installazione appena copiata (.htpasswd vuoto o assente): nessuna password, così il primo avvio
// non dà errori. L'interfaccia mostra un avviso fisso finché non si aggiunge un utente (vedi README).
define('AUTH_ENABLED', htpasswdUsers() !== []);

[$authUser, $authPass] = basicAuthCredentials();
if (AUTH_ENABLED && !checkHtpasswd($authUser, $authPass)) {
    if ($authUser !== '') usleep(400000); // rallenta i tentativi di indovinare la password
    http_response_code(401);
    header('WWW-Authenticate: Basic realm="TreeForge", charset="UTF-8"');
    exit(t('auth.required'));
}

if (session_status() === PHP_SESSION_NONE) {
    session_name('TREEFORGESESSID');
    session_start();
}

function csrfToken(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function requireCsrf(): void {
    $sent = (string)($_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($sent === '' || !hash_equals(csrfToken(), $sent)) {
        http_response_code(403);
        if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false || isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            header('Content-Type: application/json; charset=utf-8');
            exit(json_encode(['ok' => false, 'error' => t('auth.session_expired')]));
        }
        exit(htmlspecialchars(t('auth.session_invalid')) . ' <a href="index.php">' . htmlspecialchars(t('auth.reload')) . '</a>');
    }
}
