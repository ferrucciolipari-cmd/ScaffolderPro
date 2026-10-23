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

// -------------------------------------------------------------------
// BLOCCO DEI TENTATIVI: 10 errori dallo stesso indirizzo in 15 minuti → accesso bloccato per 15 minuti
// (si usa REMOTE_ADDR: X-Forwarded-For lo può scrivere chiunque)
// -------------------------------------------------------------------
const LOGIN_MAX_FAILS = 10;
const LOGIN_WINDOW    = 900;
const LOGIN_LOCK      = 900;

function clientIp(): string {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
}

/** Voce nel Registro movimenti (addLog di core.php qui non c'è ancora: l'accesso si controlla prima). */
function authLog(string $type, string $text): void {
    try {
        db()->prepare('INSERT INTO logs (type, text) VALUES (?, ?)')->execute([$type, $text]);
    } catch (Throwable $e) {
        // il registro non deve mai bloccare l'accesso
    }
}

/** Secondi di blocco rimasti per questo indirizzo (0 = libero). */
function loginLockedFor(): int {
    $st = db()->prepare('SELECT locked_until FROM login_failures WHERE ip = ?');
    $st->execute([clientIp()]);
    return max(0, (int)$st->fetchColumn() - time());
}

function loginFailed(string $user): void {
    $ip = clientIp();
    $now = time();
    $st = db()->prepare('SELECT * FROM login_failures WHERE ip = ?');
    $st->execute([$ip]);
    $row = $st->fetch();
    $fails = $row && (int)$row['first_at'] > $now - LOGIN_WINDOW ? (int)$row['fails'] + 1 : 1;
    $first = $row && (int)$row['first_at'] > $now - LOGIN_WINDOW ? (int)$row['first_at'] : $now;
    $locked = $fails >= LOGIN_MAX_FAILS ? $now + LOGIN_LOCK : 0;
    db()->prepare('INSERT OR REPLACE INTO login_failures (ip, fails, first_at, locked_until) VALUES (?, ?, ?, ?)')
        ->execute([$ip, $locked ? 0 : $fails, $locked ? $now : $first, $locked]);
    if ($locked) authLog('error', t('log.login_locked', ['ip' => $ip, 'n' => $fails, 'min' => (int)(LOGIN_LOCK / 60), 'user' => $user]));
}

function loginSucceeded(string $user, string $how): void {
    db()->prepare('DELETE FROM login_failures WHERE ip = ?')->execute([clientIp()]);
    db()->exec('DELETE FROM login_failures WHERE locked_until < ' . time() . ' AND first_at < ' . (time() - LOGIN_WINDOW));
    authLog('create', t('log.signed_in', ['user' => $user, 'ip' => clientIp(), 'how' => $how]));
}

// -------------------------------------------------------------------
// VERIFICA IN DUE PASSAGGI (TOTP, RFC 6238: codice a 6 cifre ogni 30 s, SHA-1) — nessuna libreria
// Il segreto è legato all'impronta della password: cambiarla in .htpasswd disattiva la verifica
// (chi può scrivere .htpasswd ha già accesso al server: è il modo di rientrare senza telefono).
// -------------------------------------------------------------------
const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function base32Encode(string $bin): string {
    $bits = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= B32[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}

function base32Decode(string $s): string {
    $bits = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s))) as $c) $bits .= str_pad(decbin(strpos(B32, $c)), 5, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}

function totpCode(string $secretB32, int $step): string {
    $h = hash_hmac('sha1', pack('N2', 0, $step), base32Decode($secretB32), true);
    $o = ord($h[19]) & 0x0f;
    $v = ((ord($h[$o]) & 0x7f) << 24 | ord($h[$o + 1]) << 16 | ord($h[$o + 2]) << 8 | ord($h[$o + 3])) % 1000000;
    return str_pad((string)$v, 6, '0', STR_PAD_LEFT);
}

/** Riga della verifica in due passaggi dell'utente, se attiva e ancora valida per la password attuale. */
function totpRow(string $user): ?array {
    $st = db()->prepare('SELECT * FROM auth_totp WHERE user = ? AND enabled = 1');
    $st->execute([$user]);
    $row = $st->fetch();
    if (!$row) return null;
    $users = htpasswdUsers();
    if (!isset($users[$user]) || !hash_equals((string)$row['pass_sig'], passSig($users[$user]))) {
        db()->prepare('DELETE FROM auth_totp WHERE user = ?')->execute([$user]);
        authLog('delete', t('log.totp_reset_password', ['user' => $user]));
        return null;
    }
    return $row;
}

/**
 * Controlla un codice: a 6 cifre (finestra ±30 s, mai lo stesso due volte) oppure un codice di emergenza
 * (monouso, viene cancellato). $row = riga di auth_totp (anche non ancora attiva, per la conferma).
 */
function totpCheck(array $row, string $code): bool {
    $code = strtolower(preg_replace('/\s+/', '', $code));
    if (preg_match('/^\d{6}$/', $code)) {
        $now = (int)floor(time() / 30);
        for ($step = $now - 1; $step <= $now + 1; $step++) {
            if ($step > (int)$row['last_step'] && hash_equals(totpCode((string)$row['secret'], $step), $code)) {
                db()->prepare('UPDATE auth_totp SET last_step = ? WHERE user = ?')->execute([$step, $row['user']]);
                return true;
            }
        }
        return false;
    }
    if (!preg_match('/^[a-f0-9]{4}-?[a-f0-9]{4}$/', $code) || empty($row['enabled'])) return false;
    $hash = hash('sha256', str_replace('-', '', $code));
    $list = json_decode((string)$row['recovery'], true) ?: [];
    $i = array_search($hash, $list, true);
    if ($i === false) return false;
    array_splice($list, $i, 1);
    db()->prepare('UPDATE auth_totp SET recovery = ? WHERE user = ?')->execute([json_encode($list), $row['user']]);
    authLog('save', t('log.recovery_used', ['user' => $row['user'], 'n' => count($list)]));
    return true;
}

/** Passaggio intermedio del login (password giusta, manca il codice): firmato e valido 5 minuti. */
function pendingToken(string $user): string {
    $p = base64_encode($user) . '.' . (time() + 300);
    return $p . '.' . hash_hmac('sha256', 'pending|' . $p, appSecret());
}

function pendingUser(string $tok): ?string {
    $parts = explode('.', $tok);
    if (count($parts) !== 3 || (int)$parts[1] < time()) return null;
    if (!hash_equals(hash_hmac('sha256', 'pending|' . $parts[0] . '.' . $parts[1], appSecret()), $parts[2])) return null;
    $user = (string)base64_decode($parts[0], true);
    return isset(htpasswdUsers()[$user]) ? $user : null;
}

// -------------------------------------------------------------------
// LOGIN PERPETUO E DISPOSITIVI COLLEGATI
// -------------------------------------------------------------------

/** Selettore del login di questo browser (o ''). */
function currentSelector(): string {
    return preg_match('/^([a-f0-9]{24})\./', (string)($_COOKIE[AUTH_COOKIE] ?? ''), $m) ? $m[1] : '';
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
    // Ultimo uso (e ultimo indirizzo) aggiornati al massimo una volta al giorno o al cambio di indirizzo
    if (strtotime((string)$row['last_used']) < time() - 86400 || (string)$row['last_ip'] !== clientIp()) {
        db()->prepare("UPDATE auth_tokens SET last_used = datetime('now','localtime'), last_ip = ? WHERE selector = ?")->execute([clientIp(), $m[1]]);
    }
    return (string)$row['user'];
}

/** Login riuscito: nuovo token nel database e cookie che dura finché non si esce. */
function authLogin(string $user): void {
    $users = htpasswdUsers();
    if (!isset($users[$user])) return;
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO auth_tokens (selector, validator, user, pass_sig, agent, ip, last_ip) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$selector, hash('sha256', $validator), $user, passSig($users[$user]), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300), clientIp(), clientIp()]);
    setAppCookie(AUTH_COOKIE, $selector . '.' . $validator, COOKIE_LIFETIME);
}

/** Esci: il token viene cancellato dal server e il cookie dal browser. */
function authLogout(): void {
    if (($sel = currentSelector()) !== '') db()->prepare('DELETE FROM auth_tokens WHERE selector = ?')->execute([$sel]);
    setAppCookie(AUTH_COOKIE, '', 0);
}

/** Dispositivi collegati (tutti gli utenti), il più recente prima. */
function authDevices(): array {
    $cur = currentSelector();
    $out = [];
    foreach (db()->query('SELECT rowid AS id, selector, user, agent, ip, last_ip, created_at, last_used FROM auth_tokens ORDER BY last_used DESC, rowid DESC')->fetchAll() as $r) {
        $out[] = ['id' => (int)$r['id'], 'user' => $r['user'], 'agent' => $r['agent'], 'ip' => $r['ip'], 'last_ip' => $r['last_ip'],
                  'created_at' => $r['created_at'], 'last_used' => $r['last_used'], 'current' => $cur !== '' && hash_equals((string)$r['selector'], $cur)];
    }
    return $out;
}

/** Non autenticato: le chiamate di api.php ricevono 401 in JSON, il browser la pagina di login (con l'invio del modulo). */
function loginGate(): void {
    $error = '';
    $step2 = null;   // utente a cui chiedere il codice
    $locked = loginLockedFor();
    $post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if ($post && (isset($_POST['sp_login']) || isset($_POST['sp_login2']))) {
        if (!hash_equals(csrfToken(), (string)($_POST['csrf'] ?? ''))) {
            $error = t('auth.session_expired');
        } elseif ($locked > 0) {
            $error = t('auth.locked', ['min' => (int)ceil($locked / 60)]);
        } elseif (isset($_POST['sp_login2'])) {
            // Secondo passaggio: codice a 6 cifre o codice di emergenza
            $user = pendingUser((string)($_POST['pending'] ?? ''));
            $row = $user !== null ? totpRow($user) : null;
            if ($user === null || $row === null) {
                $error = t('auth.step_expired');
            } elseif (totpCheck($row, (string)($_POST['code'] ?? ''))) {
                authLogin($user);
                loginSucceeded($user, t('auth.how_totp'));
                header('Location: ' . appCookiePath(), true, 303);
                exit;
            } else {
                usleep(400000);
                loginFailed($user);
                $step2 = $user;
                $locked = loginLockedFor();
                $error = $locked > 0 ? t('auth.locked', ['min' => (int)ceil($locked / 60)]) : t('auth.code_wrong');
                if ($locked > 0) $step2 = null;
            }
        } else {
            $user = trim((string)($_POST['user'] ?? ''));
            if (checkHtpasswd($user, (string)($_POST['pass'] ?? ''))) {
                if (totpRow($user)) {
                    $step2 = $user;
                } else {
                    authLogin($user);
                    loginSucceeded($user, t('auth.how_password'));
                    header('Location: ' . appCookiePath(), true, 303);
                    exit;
                }
            } else {
                usleep(400000); // rallenta i tentativi di indovinare la password
                loginFailed($user);
                $locked = loginLockedFor();
                $error = $locked > 0 ? t('auth.locked', ['min' => (int)ceil($locked / 60)]) : t('auth.login_failed');
            }
        }
    } elseif ($locked > 0) {
        $error = t('auth.locked', ['min' => (int)ceil($locked / 60)]);
    }
    if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) !== 'index.php') {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['ok' => false, 'login' => true, 'error' => t('auth.required')], JSON_UNESCAPED_UNICODE));
    }
    $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
    $fields = $step2 !== null
        ? '<input type="hidden" name="sp_login2" value="1"><input type="hidden" name="pending" value="' . $h(pendingToken($step2)) . '">'
          . '<p class="step">' . $h(t('auth.code_intro', ['user' => $step2])) . '</p>'
          . '<label for="c">' . $h(t('auth.code')) . '</label><input id="c" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus maxlength="9" placeholder="123456">'
          . '<button type="submit">' . $h(t('auth.code_btn')) . '</button><p class="note">' . $h(t('auth.code_note')) . '</p>'
        : '<input type="hidden" name="sp_login" value="1">'
          . '<label for="u">' . $h(t('auth.user')) . '</label><input id="u" name="user" autocomplete="username" required autofocus value="' . $h($_POST['user'] ?? '') . '">'
          . '<label for="p">' . $h(t('auth.pass')) . '</label><input id="p" type="password" name="pass" autocomplete="current-password" required>'
          . '<button type="submit">' . $h(t('auth.login_btn')) . '</button><p class="note">' . $h(t('auth.login_note')) . '</p>';
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    exit('<!DOCTYPE html><html lang="' . $h(uiLang()) . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . $h(t('auth.login_title')) . ' — Scaffolder Pro</title><link rel="icon" type="image/svg+xml" href="favicon.svg">'
        . '<style>*{box-sizing:border-box}body{font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#181818;color:#ccc;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px}'
        . 'form{width:min(360px,100%);background:#252526;border:1px solid #3c3c3c;border-radius:10px;padding:26px 24px;box-shadow:0 20px 60px rgba(0,0,0,.5)}'
        . '.brand{display:flex;align-items:center;gap:8px;font-size:19px;font-weight:700;margin-bottom:18px}.brand svg{width:22px;height:22px}.brand b{color:rgb(0,122,204)}.brand span{color:#fff}'
        . 'label{display:block;font-size:12px;color:#bbb;margin:12px 0 4px}input{width:100%;font:inherit;font-size:16px;background:#3c3c3c;color:#fff;border:1px solid #4a4a4a;border-radius:5px;padding:8px 10px;outline:none}input:focus{border-color:#007acc}'
        . '#c{font-size:22px;letter-spacing:.3em;text-align:center;font-family:Menlo,monospace}'
        . 'button{width:100%;margin-top:18px;font:inherit;font-weight:600;background:#007acc;color:#fff;border:0;border-radius:5px;padding:9px;cursor:pointer}button:hover{background:#1a8ad4}'
        . '.err{background:rgba(244,71,71,.1);border:1px solid #c93c37;color:#f48771;border-radius:5px;padding:7px 10px;font-size:12.5px;margin-bottom:6px}.note{font-size:12px;color:#858585;margin:14px 0 0}.step{margin:0 0 4px;font-size:13px}</style></head><body>'
        . '<form method="post" autocomplete="on"><div class="brand"><svg viewBox="0 0 24 24"><path d="M13 2 4 14h8l-1 8 9-12h-8l1-8z" fill="rgba(0,122,204,.25)" stroke="rgb(0,122,204)" stroke-width="1.9" stroke-linejoin="round"/></svg><b>Scaffolder</b><span>Pro</span></div>'
        . ($error !== '' ? '<div class="err">' . $h($error) . '</div>' : '')
        . '<input type="hidden" name="csrf" value="' . $h(csrfToken()) . '">' . $fields . '</form></body></html>');
}

// Installazione appena copiata (.htpasswd vuoto o assente): nessuna password, così il primo avvio
// non dà errori. L'interfaccia mostra un avviso fisso finché non si aggiunge un utente (vedi README).
define('AUTH_ENABLED', htpasswdUsers() !== []);

$authUser = '';
if (AUTH_ENABLED) {
    $authUser = authFromCookie();
    if ($authUser === null) {
        // Accesso anche con HTTP Basic (protezione facoltativa di Apache, script), ma non per chi ha la verifica
        // in due passaggi e non da un indirizzo bloccato; le password sbagliate contano per il blocco
        [$bu, $bp] = basicAuthCredentials();
        if ($bu !== '' && loginLockedFor() === 0) {
            if (checkHtpasswd($bu, $bp)) $authUser = totpRow($bu) ? null : $bu;
            else loginFailed($bu);
        }
    }
    if ($authUser === null) loginGate();
}
