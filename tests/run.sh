#!/bin/bash
# =====================================================================
# Prove automatiche di Scaffolder Pro — un solo comando:
#
#   tests/run.sh                 controlli + prove di api.php su tutte le versioni di PHP trovate
#   tests/run.sh --big           in più: caricamento a pezzi di un file da 1 GB (--big=2048 per 2 GB)
#   tests/run.sh --php "8.4"     solo le versioni indicate (es. "7.4 8.5")
#   PHP_BINS="/usr/bin/php /opt/php82/bin/php" tests/run.sh     binari PHP scelti a mano
#
# Non tocca l'installazione vera: per ogni versione copia il progetto in una cartella temporanea,
# crea un sito di prova e lo serve con `php -S` (limiti bassi: upload 2 MB, POST 3 MB, 64 MB di memoria),
# con un finto server dell'API di Anthropic al posto di quello vero e un finto Golem Lite (tests/fake_golem.php).
# Serve: bash, python3, PHP da riga di comando (Homebrew su macOS: php@7.4, php@8.4, php@8.5…); node è facoltativo.
# =====================================================================
set -u
cd "$(dirname "$0")/.." || exit 1
ROOT=$(pwd)
BIG=0
ONLY=""
for a in "$@"; do
    case "$a" in
        --big) BIG=1024 ;;
        --big=*) BIG="${a#--big=}" ;;
        --php) ONLY="__next__" ;;
        -h|--help) sed -n '2,15p' "$0"; exit 0 ;;
        *) if [ "$ONLY" = "__next__" ]; then ONLY="$a"; fi ;;
    esac
done

red() { printf '\033[31m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }
bold() { printf '\033[1m%s\033[0m\n' "$*"; }

# --- Binari PHP: PHP_BINS, oppure le versioni di Homebrew, oppure "php" nel PATH
BINS=()
if [ -n "${PHP_BINS:-}" ]; then
    for b in $PHP_BINS; do BINS+=("$b"); done
else
    for v in 7.2 7.3 7.4 8.0 8.1 8.2 8.3 8.4 8.5; do
        for b in "/opt/homebrew/opt/php@$v/bin/php" "/usr/local/opt/php@$v/bin/php" "/usr/bin/php$v"; do
            if [ -x "$b" ]; then BINS+=("$b"); break; fi
        done
    done
    [ ${#BINS[@]} -eq 0 ] && command -v php >/dev/null && BINS+=("$(command -v php)")
fi
if [ -n "$ONLY" ] && [ "$ONLY" != "__next__" ]; then
    SEL=()
    for b in "${BINS[@]}"; do
        v=$("$b" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
        for o in $ONLY; do [ "$v" = "$o" ] && SEL+=("$b"); done
    done
    BINS=("${SEL[@]+"${SEL[@]}"}")
fi
[ ${#BINS[@]} -eq 0 ] && { red "Nessun PHP trovato (usa PHP_BINS=…)"; exit 1; }
command -v python3 >/dev/null || { red "Serve python3"; exit 1; }

FAILED=0
free_port() { python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()'; }

# --- 1. Controlli statici
bold "1. Sintassi e traduzioni"
for b in "${BINS[@]}"; do
    v=$("$b" -r 'echo PHP_VERSION;')
    errs=$(for f in *.php; do "$b" -l "$f" 2>&1 | grep -v '^No syntax errors'; done)
    if [ -z "$errs" ]; then green "  ok   PHP $v: sintassi di tutti i file .php"; else red "  FAIL PHP $v:"; echo "$errs"; FAILED=1; fi
done
if command -v node >/dev/null; then
    if node --check app.js 2>/dev/null; then green "  ok   app.js (node --check)"; else red "  FAIL app.js"; node --check app.js; FAILED=1; fi
else
    echo "  --   node non trovato: controllo di app.js saltato"
fi
python3 -I - "$ROOT" <<'PY' || FAILED=1
import json, glob, re, sys, os
root = sys.argv[1]
files = sorted(glob.glob(os.path.join(root, 'lang', '*.json')))
en = json.load(open(os.path.join(root, 'lang', 'en.json')))
bad = []
for f in files:
    d = json.load(open(f))
    if set(d) != set(en):
        bad.append('%s: chiavi diverse da en.json (%s)' % (os.path.basename(f), ', '.join(sorted(set(en) ^ set(d))[:5])))
    for k, v in d.items():
        if k in en and sorted(re.findall(r'\{\w+\}', v)) != sorted(re.findall(r'\{\w+\}', en[k])):
            bad.append('%s: segnaposto diversi in %s' % (os.path.basename(f), k))
used = set()
for f in ['app.js', 'index.php', 'api.php', 'core.php', 'auth.php', 'db.php', 'golem.php']:
    used |= set(re.findall(r"\b(?:L|t|tx|th)\(\s*'([a-z_]+\.[a-z0-9_]+)'", open(os.path.join(root, f)).read()))
bad += ['chiave usata ma assente in en.json: ' + k for k in sorted(used - set(en))]
if bad:
    print('\033[31m  FAIL traduzioni:\033[0m'); [print('       ' + b) for b in bad[:20]]; sys.exit(1)
print('\033[32m  ok   %d lingue, %d chiavi uguali, segnaposto coerenti\033[0m' % (len(files), len(en)))
PY

# --- 2. Prove di api.php per ogni versione di PHP
bold "2. Prove di api.php"
for b in "${BINS[@]}"; do
    v=$("$b" -r 'echo PHP_VERSION;')
    T=$(mktemp -d "${TMPDIR:-/tmp}/scaffolder-test.XXXXXX")
    W="$T/www"
    mkdir -p "$W/scaffolder-pro" "$W/site/inc"
    rsync -a --exclude .git --exclude 'data/*' --exclude .htpasswd --exclude tests --exclude CLAUDE.md "$ROOT/" "$W/scaffolder-pro/"
    cp "$ROOT/data/.htaccess" "$W/scaffolder-pro/data/" 2>/dev/null
    printf '# vuoto\n' > "$W/scaffolder-pro/.htpasswd"
    printf '<?php\necho "hello";\n' > "$W/site/index.php"
    printf '<?php\n$a = 1;\n' > "$W/site/inc/config.php"
    printf '<?php http_response_code(302); header("Location: http://169.254.169.254/latest/meta-data/");\n' > "$W/site/redir.php"
    printf '<?php echo json_encode(["ok"=>1]);\n' > "$W/site/api.php"
    FP=$(free_port); AP=$(free_port); SP=$(free_port); GP=$(free_port)
    mkdir -p "$T/fake" && cp "$ROOT/tests/fake_anthropic.php" "$T/fake/index.php"
    # Finto Golem Lite (blueprint, sitemap, contenuti) sullo stesso server del finto Claude
    cp "$ROOT/tests/fake_golem.php" "$T/fake/golem.php" && cp "$ROOT/tests/golem_blueprint.json" "$T/fake/"
    mkdir -p "$T/fakegh" && cp "$ROOT/tests/fake_github.php" "$T/fakegh/index.php"
    sed -i.bak "s#const ANTHROPIC_API_URL  = 'https://api.anthropic.com/v1/messages';#const ANTHROPIC_API_URL  = 'http://127.0.0.1:$FP/index.php';#" "$W/scaffolder-pro/core.php"
    sed -i.bak "s#const GITHUB_API_URL = 'https://api.github.com';#const GITHUB_API_URL = 'http://127.0.0.1:$GP';#" "$W/scaffolder-pro/core.php"
    "$b" -S "127.0.0.1:$FP" -t "$T/fake" >/dev/null 2>&1 &
    FPID=$!
    "$b" -S "127.0.0.1:$GP" "$T/fakegh/index.php" >/dev/null 2>&1 &
    GPID=$!
    PHP_CLI_SERVER_WORKERS=4 "$b" -d upload_max_filesize=2M -d post_max_size=3M -d max_execution_time=10 -d memory_limit=64M \
        -d log_errors=1 -d display_errors=0 -d error_log="$T/php_errors.log" -S "127.0.0.1:$AP" -t "$W" >/dev/null 2>&1 &
    APID=$!
    # Il sito di prova (destinazione delle richieste API) su un server a parte: php -S non risponde a sé stesso
    "$b" -S "127.0.0.1:$SP" -t "$W/site" >/dev/null 2>&1 &
    SPID=$!
    sleep 1
    echo; bold "== PHP $v ($b)"
    # Prima il file grande: la batteria finisce con la password attiva
    if [ "$BIG" != "0" ]; then
        python3 -I "$ROOT/tests/big_upload_test.py" "http://127.0.0.1:$AP/scaffolder-pro/" "$W" "$BIG" || FAILED=1
        BIG=0   # una volta basta
    fi
    python3 -I "$ROOT/tests/api_test.py" "http://127.0.0.1:$AP/scaffolder-pro/" "$W" "$b" "http://127.0.0.1:$SP/" "http://127.0.0.1:$FP/golem.php" "http://127.0.0.1:$GP/" || FAILED=1
    kill $APID $FPID $SPID $GPID 2>/dev/null; wait $APID $FPID $SPID $GPID 2>/dev/null
    if [ -s "$T/php_errors.log" ]; then red "  FAIL avvisi o errori nel log di PHP:"; head -20 "$T/php_errors.log"; FAILED=1
    else green "  ok   nessun avviso nel log di PHP"; fi
    rm -rf "$T"
done

echo
if [ $FAILED -eq 0 ]; then green "TUTTO OK"; else red "CI SONO ERRORI (vedi sopra)"; fi
exit $FAILED
