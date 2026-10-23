<?php
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/db.php';

// ===================================================================
// ACCESSO (pagina di login + sessione perpetua) & PROTEZIONE CSRF
// Utenti e password stanno in .htpasswd nella stessa cartella (hash bcrypt): nessun percorso
// assoluto in .htaccess, quindi la cartella funziona uguale in locale (XAMPP) e online (Plesk, PHP-FPM).
// Dopo il login il browser riceve un cookie che vale finché non si esce (pulsante Esci):
// il token è nella tabella auth_tokens, così l'uscita lo invalida anche sul server.
// Se .htpasswd non contiene utenti la protezione è disattivata (primo avvio).
// Il token CSRF è derivato da un cookie casuale del browser: non scade con la sessione PHP.
// ===================================================================

const AUTH_COOKIE     = 'SPAUTH';     // "selector.validator" del login
const CLIENT_COOKIE   = 'SPCLIENT';   // id casuale del browser (base del token CSRF)
const COOKIE_LIFETIME = 315360000;    // 10 anni: in pratica non scade finché non si esce

/** Utente e password inviati con HTTP Basic (doppia protezione di Apache, script): mod_php li espone direttamente, FastCGI solo come header. */
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

/** Chiave segreta dell'installazione (firma del token CSRF e dei login), creata al primo uso. */
function appSecret(): string {
    static $secret = null;
    if ($secret !== null) return $secret;
    $secret = (string)getSetting('secret', '');
    if (!preg_match('/^[a-f0-9]{64}$/', $secret)) {
        $secret = bin2hex(random_bytes(32));
        setSetting('secret', $secret);
    }
    return $secret;
}

function isHttps(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/** Cartella dell'app nell'URL ("/scaffolder-pro/"): i cookie valgono solo lì. */
function appCookiePath(): string {
    $dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
    return rtrim($dir, '/') . '/';
}

/** Cookie HttpOnly + SameSite=Lax (scritto a mano: setcookie() con le opzioni in array richiede PHP 7.3). */
function setAppCookie(string $name, string $value, int $lifetime): void {
    $exp = $lifetime > 0 ? time() + $lifetime : 1;
    header('Set-Cookie: ' . $name . '=' . rawurlencode($value) . '; Expires=' . gmdate('D, d M Y H:i:s', $exp) . ' GMT; Max-Age=' . max(0, $lifetime)
        . '; Path=' . appCookiePath() . '; HttpOnly; SameSite=Lax' . (isHttps() ? '; Secure' : ''), false);
    if ($lifetime > 0) $_COOKIE[$name] = $value; else unset($_COOKIE[$name]);
}

/** Id casuale di questo browser (cookie da 10 anni). */
function clientId(): string {
    static $id = null;
    if ($id !== null) return $id;
    $id = (string)($_COOKIE[CLIENT_COOKIE] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
        $id = bin2hex(random_bytes(16));
        setAppCookie(CLIENT_COOKIE, $id, COOKIE_LIFETIME);
    }
    return $id;
}

function csrfToken(): string {
    return hash_hmac('sha256', 'csrf|' . clientId(), appSecret());
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

/** Impronta della password attuale dell'utente: se la password cambia, i login già fatti non valgono più. */
function passSig(string $hash): string {
    return substr(hash_hmac('sha256', 'pass|' . $hash, appSecret()), 0, 32);
}

/** Utente del cookie di login, se valido (token esistente, utente ancora in .htpasswd con la stessa password). */
function authFromCookie(): ?string {
    if (!preg_match('/^([a-f0-9]{24})\.([a-f0-9]{64})$/', (string)($_COOKIE[AUTH_COOKIE] ?? ''), $m)) return null;
    $st = db()->prepare('SELECT * FROM auth_tokens WHERE selector = ?');
    $st->execute([$m[1]]);
    $row = $st->fetch();
    if (!$row || !hash_equals((string)$row['validator'], hash('sha256', $m[2]))) return null;
    $users = htpasswdUsers();
    if (!isset($users[$row['user']]) || !hash_equals((string)$row['pass_sig'], passSig($users[$row['user']]))) {
        db()->prepare('DELETE FROM auth_tokens WHERE selector = ?')->execute([$m[1]]);
        return null;
    }
    // Ultimo uso aggiornato al massimo una volta al giorno (niente scritture a ogni richiesta)
    if (strtotime((string)$row['last_used']) < time() - 86400) {
        db()->prepare("UPDATE auth_tokens SET last_used = datetime('now','localtime') WHERE selector = ?")->execute([$m[1]]);
    }
    return (string)$row['user'];
}

/** Login riuscito: nuovo token nel database e cookie che dura finché non si esce. */
function authLogin(string $user): void {
    $users = htpasswdUsers();
    if (!isset($users[$user])) return;
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO auth_tokens (selector, validator, user, pass_sig, agent) VALUES (?, ?, ?, ?, ?)')
        ->execute([$selector, hash('sha256', $validator), $user, passSig($users[$user]), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)]);
    setAppCookie(AUTH_COOKIE, $selector . '.' . $validator, COOKIE_LIFETIME);
}

/** Esci: il token viene cancellato dal server e il cookie dal browser. */
function authLogout(): void {
    if (preg_match('/^([a-f0-9]{24})\./', (string)($_COOKIE[AUTH_COOKIE] ?? ''), $m)) {
        db()->prepare('DELETE FROM auth_tokens WHERE selector = ?')->execute([$m[1]]);
    }
    setAppCookie(AUTH_COOKIE, '', 0);
}

/** Non autenticato: le chiamate di api.php ricevono 401 in JSON, il browser la pagina di login (con l'invio del modulo). */
function loginGate(): void {
    $error = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['sp_login'])) {
        $user = trim((string)($_POST['user'] ?? ''));
        if (!hash_equals(csrfToken(), (string)($_POST['csrf'] ?? ''))) {
            $error = t('auth.session_expired');
        } elseif (checkHtpasswd($user, (string)($_POST['pass'] ?? ''))) {
            authLogin($user);
            header('Location: ' . appCookiePath(), true, 303);
            exit;
        } else {
            usleep(400000); // rallenta i tentativi di indovinare la password
            $error = t('auth.login_failed');
        }
    }
    if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) !== 'index.php') {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['ok' => false, 'login' => true, 'error' => t('auth.required')], JSON_UNESCAPED_UNICODE));
    }
    $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    exit('<!DOCTYPE html><html lang="' . $h(uiLang()) . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . $h(t('auth.login_title')) . ' — Scaffolder Pro</title><link rel="icon" type="image/svg+xml" href="favicon.svg">'
        . '<style>*{box-sizing:border-box}body{font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#181818;color:#ccc;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px}'
        . 'form{width:min(360px,100%);background:#252526;border:1px solid #3c3c3c;border-radius:10px;padding:26px 24px;box-shadow:0 20px 60px rgba(0,0,0,.5)}'
        . '.brand{display:flex;align-items:center;gap:8px;font-size:19px;font-weight:700;margin-bottom:18px}.brand svg{width:22px;height:22px}.brand b{color:rgb(0,122,204)}.brand span{color:#fff}'
        . 'label{display:block;font-size:12px;color:#bbb;margin:12px 0 4px}input{width:100%;font:inherit;font-size:16px;background:#3c3c3c;color:#fff;border:1px solid #4a4a4a;border-radius:5px;padding:8px 10px;outline:none}input:focus{border-color:#007acc}'
        . 'button{width:100%;margin-top:18px;font:inherit;font-weight:600;background:#007acc;color:#fff;border:0;border-radius:5px;padding:9px;cursor:pointer}button:hover{background:#1a8ad4}'
        . '.err{background:rgba(244,71,71,.1);border:1px solid #c93c37;color:#f48771;border-radius:5px;padding:7px 10px;font-size:12.5px;margin-bottom:6px}.note{font-size:12px;color:#858585;margin:14px 0 0}</style></head><body>'
        . '<form method="post" autocomplete="on"><div class="brand"><svg viewBox="0 0 24 24"><path d="M13 2 4 14h8l-1 8 9-12h-8l1-8z" fill="rgba(0,122,204,.25)" stroke="rgb(0,122,204)" stroke-width="1.9" stroke-linejoin="round"/></svg><b>Scaffolder</b><span>Pro</span></div>'
        . ($error !== '' ? '<div class="err">' . $h($error) . '</div>' : '')
        . '<input type="hidden" name="sp_login" value="1"><input type="hidden" name="csrf" value="' . $h(csrfToken()) . '">'
        . '<label for="u">' . $h(t('auth.user')) . '</label><input id="u" name="user" autocomplete="username" required autofocus value="' . $h($_POST['user'] ?? '') . '">'
        . '<label for="p">' . $h(t('auth.pass')) . '</label><input id="p" type="password" name="pass" autocomplete="current-password" required>'
        . '<button type="submit">' . $h(t('auth.login_btn')) . '</button><p class="note">' . $h(t('auth.login_note')) . '</p></form></body></html>');
}

// Installazione appena copiata (.htpasswd vuoto o assente): nessuna password, così il primo avvio
// non dà errori. L'interfaccia mostra un avviso fisso finché non si aggiunge un utente (vedi README).
define('AUTH_ENABLED', htpasswdUsers() !== []);

$authUser = '';
if (AUTH_ENABLED) {
    $authUser = authFromCookie();
    if ($authUser === null) {
        // Accesso anche con HTTP Basic (protezione facoltativa di Apache in .htaccess, script)
        [$bu, $bp] = basicAuthCredentials();
        $authUser = $bu !== '' && checkHtpasswd($bu, $bp) ? $bu : null;
    }
    if ($authUser === null) loginGate();
}
