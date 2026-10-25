<?php
/**
 * Database Manager di Scaffolder Pro: ingresso di Adminer (adminer.php, Jakub Vrána, Apache 2.0 / GPL 2).
 *
 * - L'accesso è quello di Scaffolder Pro (core.php → auth.php): senza login non si arriva ad Adminer.
 * - Il login a MySQL è automatico, con i dati salvati nella scheda Configurazioni (data/config.json):
 *   la password non passa dal browser.
 * - Il tema (adminer-scaffolder.css) e i pochi ritocchi passano da adminer_object(), il punto di
 *   estensione ufficiale: adminer.php resta quello originale, con la sua intestazione di licenza.
 */
ini_set('display_errors', '0');
chdir(__DIR__);
require_once dirname(__DIR__) . '/core.php';

$sp = loadConfig();
define('SP_DB_SERVER', dbServerString($sp));
define('SP_DB_USER', (string)$sp['db_user']);
define('SP_DB_PASS', (string)$sp['db_pass']);
define('SP_DB_NAME', (string)$sp['db_name']);
define('SP_THEME', in_array($sp['theme'], ['dark', 'light', 'auto'], true) ? $sp['theme'] : 'dark');

// Login automatico: alla prima apertura (nessun utente nell'indirizzo) si simula l'invio del modulo di accesso;
// Adminer salva la password nella sua sessione e porta subito sul database, senza schermata di login.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['username']) && !isset($_GET['file']) && !isset($_GET['script']) && !isset($_GET['sqlite'])) {
    $_POST = ['auth' => ['driver' => 'server', 'server' => SP_DB_SERVER, 'username' => SP_DB_USER, 'password' => SP_DB_PASS, 'db' => SP_DB_NAME]];
}

/** Variabili CSS di Scaffolder Pro per il tema chiaro (quello scuro è il predefinito di adminer-scaffolder.css). */
function sp_light_vars(): string {
    return '--bg:#f3f3f3;--panel:#f8f8f8;--panel-2:#ffffff;--border:#e1e1e1;--text:#3b3b3b;--muted:#6e6e6e;--accent:#007acc;--sel:#cce4f7;--sel-active:#e4e6f1;'
         . '--input-bg:#ffffff;--input-border:#d9d9d9;--input-text:#1a1a1a;--btn:#0e639c;--btn-hover:#1177bb;--danger:#c93c37;--row-alt:rgba(0,0,0,.025);--row-hover:#e4e6f1;--link:#0b5cad;--code-bg:#ececec;'
         . '--c-str:#a31515;--c-kw:#0451a5;--c-num:#098658;--c-id:#795e26;--c-attr:#0070c1;--c-date:#af00db;--c-enum:#267f99;--ok-text:#1a7f37;--err-text:#b3261e;color-scheme:light;';
}

function adminer_object() {
    class ScaffolderAdminer extends \Adminer\Adminer {
        function name() { return '<a href="index.php" id="h1">Database Manager</a>'; }

        // Sempre il server, l'utente e la password della scheda Configurazioni
        function credentials() { return [SP_DB_SERVER, $_GET['username'] ?? SP_DB_USER, \Adminer\get_password()]; }

        // Anche utenti MySQL senza password (XAMPP/MAMP): l'accesso è già protetto da Scaffolder Pro
        function login($login, $password) { return true; }

        function permanentLogin($create = false) { return ''; }

        function css() { return ['adminer-scaffolder.css?v=' . filemtime(__DIR__ . '/adminer-scaffolder.css') => '']; }

        // Colori del tema scelto in Scaffolder Pro (il predefinito scuro sta nel foglio di stile)
        function head($dark = null) {
            $v = sp_light_vars();
            if (SP_THEME === 'light') echo "<style>:root:root{{$v}}</style>\n";
            elseif (SP_THEME === 'auto') echo "<style>@media (prefers-color-scheme: light){:root:root{{$v}}}</style>\n";
            else echo "<style>:root{color-scheme:dark}</style>\n";
            return true;
        }

        // Adminer di norma vieta di essere mostrato in un frame: qui serve dentro la scheda di Scaffolder Pro (stessa origine)
        function headers() { header('X-Frame-Options: SAMEORIGIN'); return true; }

        function csp(array $csp) { $csp[0]['frame-ancestors'] = "'self'"; return $csp; }

        // Se la connessione fallisce (dati sbagliati, MySQL spento) non c'è un modulo da compilare: si rimanda alle Configurazioni
        function loginForm() {
            echo '<div class="sp-login"><h2>' . htmlspecialchars(t('dbm.cannot_connect')) . '</h2>'
               . '<p>' . htmlspecialchars(t('dbm.cannot_connect_hint', ['server' => SP_DB_SERVER, 'user' => SP_DB_USER])) . '</p>'
               . '<p><a class="sp-btn" href="index.php" target="_self">' . htmlspecialchars(t('dbm.retry')) . '</a> '
               . '<a class="sp-btn secondary" href="../index.php#config" target="_top">' . htmlspecialchars(t('dbm.open_config')) . '</a></p></div>';
        }
    }
    return new ScaffolderAdminer;
}

if (!is_file(__DIR__ . '/adminer.php')) {
    http_response_code(500);
    exit('adminer/adminer.php not found.');
}
require __DIR__ . '/adminer.php';
