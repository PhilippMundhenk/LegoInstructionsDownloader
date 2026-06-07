#!/bin/bash
# End-to-end test: boot lighttpd against the real app + a fixture downloads dir,
# curl the endpoints a browser would hit, assert HTTP codes and body content.
#
# Reproduces what's inside the docker container as closely as possible without
# requiring docker (which doesn't run on Termux/Android).
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TMPDIR="$(mktemp -d -t lego-e2e.XXXXXX)"
PORT="${E2E_PORT:-8770}"
PHPCGI="${PHPCGI:-$(command -v php-cgi)}"
LIGHTTPD="${LIGHTTPD:-$(command -v lighttpd)}"

if [[ -z "$PHPCGI" ]]; then echo "FAIL: php-cgi not found"; exit 2; fi
if [[ -z "$LIGHTTPD" ]]; then echo "FAIL: lighttpd not found"; exit 2; fi

PASS=0; FAIL=0
note() { printf '  %s\n' "$*"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mok\033[0m   %s\n' "$*"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$*"; }

cleanup() {
    if [[ -f "$TMPDIR/lighttpd.pid" ]]; then
        kill "$(cat "$TMPDIR/lighttpd.pid")" 2>/dev/null || true
        sleep 0.2
    fi
    pkill -f "lighttpd .* $TMPDIR/lighttpd.conf" 2>/dev/null || true
    pkill -f "$TMPDIR/php.sock" 2>/dev/null || true
    if [[ -z "${E2E_KEEP:-}" ]]; then rm -rf "$TMPDIR"; else echo "kept $TMPDIR"; fi
}
trap cleanup EXIT INT TERM

# --- build docroot (mirrors `COPY ... /var/www/html/` + the /downloads symlink)
DOCROOT="$TMPDIR/www"
DOWNLOADS="$TMPDIR/downloads"
mkdir -p "$DOCROOT" "$DOWNLOADS" "$TMPDIR/upload"

for f in index.php list.php log.php download.php main.css lib.php fetch.sh migrate.sh; do
    cp "$ROOT/$f" "$DOCROOT/"
done
chmod +x "$DOCROOT/fetch.sh" "$DOCROOT/migrate.sh"
ln -s "$DOWNLOADS" "$DOCROOT/downloads"

# --- fixtures: one v1 (data.json) and one v2 (name.txt) set
mkdir -p "$DOWNLOADS/31099" "$DOWNLOADS/30640"
cat > "$DOWNLOADS/31099/data.json" <<'JSON'
{"hits":{"hits":[{"_source":{"locale":{"de-de":{"display_title":"Propellerflugzeug"}}}}]}}
JSON
printf 'fake-jpg-bytes' > "$DOWNLOADS/31099/31099_Prod.jpg"
printf 'fake-pdf' > "$DOWNLOADS/31099/6308552.pdf"
printf 'fake-png' > "$DOWNLOADS/31099/6308552.png"

printf 'Cute Pug\n' > "$DOWNLOADS/30640/name.txt"
printf 'fake-png' > "$DOWNLOADS/30640/30640_Prod.png"
printf 'fake-pdf' > "$DOWNLOADS/30640/6447079.pdf"
printf 'fake-png' > "$DOWNLOADS/30640/6447079.png"

# --- legacy set: numeric dir + PDFs but no name.txt/data.json, with raw HTML index
# present. migrate.sh should write name.txt from the embedded "setNumber" anchor.
mkdir -p "$DOWNLOADS/7696"
cat > "$DOWNLOADS/7696/7696" <<'HTML'
<html><head><meta property="og:title" content="Legacy Set"></head>
<body>{"name":"Train Station","setNumber":"7696","other":"junk"}</body></html>
HTML
printf 'fake-pdf' > "$DOWNLOADS/7696/6056567.pdf"
printf 'fake-png' > "$DOWNLOADS/7696/6056567.png"

# --- legacy set with NO raw HTML — migration falls back to "Set <id>".
mkdir -p "$DOWNLOADS/99001"
printf 'fake-pdf' > "$DOWNLOADS/99001/some.pdf"

# --- render the lighttpd config from the template
sed \
    -e "s|@DOCROOT@|$DOCROOT|g" \
    -e "s|@TMPDIR@|$TMPDIR|g" \
    -e "s|@PORT@|$PORT|g" \
    -e "s|@PHPCGI@|$PHPCGI|g" \
    "$ROOT/tests/e2e/lighttpd.conf.tpl" > "$TMPDIR/lighttpd.conf"

# --- boot lighttpd
export DOWNLOADS_DIR="$DOWNLOADS"
export LEGO_LOG_FILE="$TMPDIR/lego.log"
touch "$LEGO_LOG_FILE"

# --- run migration (mirrors what start.sh does in the container) before booting.
bash "$DOCROOT/migrate.sh" "$DOWNLOADS" >> "$LEGO_LOG_FILE" 2>&1 || true

"$LIGHTTPD" -f "$TMPDIR/lighttpd.conf" -D >"$TMPDIR/lighttpd.stdout" 2>"$TMPDIR/lighttpd.stderr" &

# wait for port
for _ in $(seq 1 30); do
    if curl -sf "http://127.0.0.1:$PORT/" >/dev/null 2>&1; then break; fi
    sleep 0.1
done
if ! curl -sf -o /dev/null "http://127.0.0.1:$PORT/"; then
    echo "FAIL: lighttpd did not come up; stderr:"
    cat "$TMPDIR/lighttpd.stderr"
    exit 2
fi

BASE="http://127.0.0.1:$PORT"
HOME_BODY="$TMPDIR/home.html"
curl -s -o "$HOME_BODY" -w "%{http_code}" "$BASE/" > "$TMPDIR/home.code"

echo "running e2e tests against $BASE"

# 1. index serves with 200
code=$(cat "$TMPDIR/home.code")
[[ "$code" == "200" ]] && ok "GET /  -> 200" || bad "GET /  -> $code (expected 200)"

# 2. index actually renders cards, not "No sets yet"
grep -q '<ul class="cards"' "$HOME_BODY" \
    && ok "GET /  -> cards container present" \
    || bad "GET /  -> cards container missing — list looks empty"
grep -q 'Propellerflugzeug' "$HOME_BODY" \
    && ok "GET /  -> v1 set title rendered" \
    || bad "GET /  -> v1 set title missing"
grep -q 'Cute Pug' "$HOME_BODY" \
    && ok "GET /  -> v2 set title rendered" \
    || bad "GET /  -> v2 set title missing"
grep -q '#30640' "$HOME_BODY" \
    && ok "GET /  -> set id badge rendered" \
    || bad "GET /  -> set id badge missing"

# 3. main.css served as CSS
hdr=$(curl -s -D - -o /dev/null "$BASE/main.css")
echo "$hdr" | head -1 | grep -q '200 OK' \
    && ok "GET /main.css -> 200" \
    || bad "GET /main.css -> $(echo "$hdr" | head -1)"
echo "$hdr" | tr -d '\r' | grep -qi '^content-type: text/css' \
    && ok "GET /main.css -> Content-Type text/css" \
    || bad "GET /main.css -> wrong Content-Type ($(echo "$hdr" | tr -d '\r' | grep -i content-type))"
curl -sf "$BASE/main.css" | grep -q ':root' \
    && ok "GET /main.css -> body contains :root vars" \
    || bad "GET /main.css -> CSS body missing :root"

# 4. main.css linked from HTML
grep -qE 'href="main\.css(\?[^"]*)?"' "$HOME_BODY" \
    && ok "GET /  -> links main.css" \
    || bad "GET /  -> no <link> to main.css"

# 5. /downloads/... images are served from the symlink target
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/downloads/31099/31099_Prod.jpg")
[[ "$code" == "200" ]] && ok "GET /downloads/31099/31099_Prod.jpg -> 200" \
                       || bad "GET /downloads/31099/31099_Prod.jpg -> $code"
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/downloads/30640/6447079.pdf")
[[ "$code" == "200" ]] && ok "GET /downloads/30640/6447079.pdf -> 200" \
                       || bad "GET /downloads/30640/6447079.pdf -> $code"

# 6. download.php JSON endpoint behaves on input
resp=$(curl -s -X POST -d 'set_id=' "$BASE/download.php")
echo "$resp" | grep -q '"ok":false' && echo "$resp" | grep -q 'No set ID' \
    && ok "POST /download.php (empty) -> ok:false + 'No set ID'" \
    || bad "POST /download.php (empty) -> unexpected: $resp"
resp=$(curl -s -X POST -d 'set_id=abc' "$BASE/download.php")
echo "$resp" | grep -q '"ok":false' \
    && ok "POST /download.php (invalid id) -> ok:false" \
    || bad "POST /download.php (invalid id) -> unexpected: $resp"
code=$(curl -s -o /dev/null -w '%{http_code}' -X GET "$BASE/download.php")
[[ "$code" == "405" ]] && ok "GET /download.php -> 405" \
                      || bad "GET /download.php -> $code (expected 405)"

# 7. log.php reads the log file
echo 'test-log-line' >> "$LEGO_LOG_FILE"
curl -sf "$BASE/log.php" | grep -q 'test-log-line' \
    && ok "GET /log.php -> shows lego.log content" \
    || bad "GET /log.php -> does not show lego.log content"

# 8. list.php redirects to /
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/list.php")
[[ "$code" == "302" ]] && ok "GET /list.php -> 302 (redirect)" \
                      || bad "GET /list.php -> $code (expected 302)"

# 9. counter shows correct count (2 v1/v2 sets + 2 migrated legacy sets = 4)
grep -q '4 sets' "$HOME_BODY" \
    && ok "GET /  -> counter shows '4 sets'" \
    || bad "GET /  -> counter wrong (have: $(grep -oE '[0-9]+ sets?' "$HOME_BODY" | head -1))"

# 9b. migration ran and wrote name.txt for the legacy sets
[[ -f "$DOWNLOADS/7696/name.txt" ]] \
    && ok "migrate.sh -> wrote name.txt for legacy set 7696" \
    || bad "migrate.sh -> no name.txt for 7696"
grep -q 'Train Station' "$DOWNLOADS/7696/name.txt" 2>/dev/null \
    && ok "migrate.sh -> extracted 'Train Station' from raw HTML" \
    || bad "migrate.sh -> wrong name for 7696: $(cat "$DOWNLOADS/7696/name.txt" 2>/dev/null)"
[[ -f "$DOWNLOADS/99001/name.txt" ]] \
    && ok "migrate.sh -> wrote name.txt for 99001 (no HTML)" \
    || bad "migrate.sh -> no name.txt for 99001"
grep -q '^Set 99001$' "$DOWNLOADS/99001/name.txt" 2>/dev/null \
    && ok "migrate.sh -> fell back to 'Set 99001' when nothing else worked" \
    || bad "migrate.sh -> wrong fallback for 99001: $(cat "$DOWNLOADS/99001/name.txt" 2>/dev/null)"

# 9c. migrated legacy sets render on the index
grep -q 'Train Station' "$HOME_BODY" \
    && ok "GET /  -> migrated legacy set title rendered" \
    || bad "GET /  -> migrated legacy set title missing"
grep -q '#7696' "$HOME_BODY" \
    && ok "GET /  -> legacy set id badge rendered" \
    || bad "GET /  -> legacy set id badge missing"

# 9d. migrate.sh is idempotent — content of name.txt does not change on rerun
sum_before=$(cat "$DOWNLOADS"/*/name.txt 2>/dev/null | sha1sum | awk '{print $1}')
bash "$DOCROOT/migrate.sh" "$DOWNLOADS" >>"$LEGO_LOG_FILE" 2>&1 || true
sum_after=$(cat "$DOWNLOADS"/*/name.txt 2>/dev/null | sha1sum | awk '{print $1}')
[[ "$sum_before" == "$sum_after" ]] \
    && ok "migrate.sh idempotent (name.txt content unchanged on rerun)" \
    || bad "migrate.sh rewrote name.txt on rerun"

# 10. no PHP warnings / errors leaked into HTML
if grep -qE 'Warning:|Fatal error|Notice:|Parse error' "$HOME_BODY"; then
    bad "GET /  -> PHP warnings/errors leaked into HTML body"
    grep -E 'Warning:|Fatal error|Notice:|Parse error' "$HOME_BODY" | head -5
else
    ok "GET /  -> no PHP warnings in output"
fi

# 11. lighttpd error.log must be clean of permission/temp-file errors.
# This guards the exact symptom of the /var/cache/lighttpd/uploads bug:
# every POST triggered "chunk.c.778 opening temp-file failed: Permission denied".
if grep -qE 'opening temp-file failed|Permission denied' "$TMPDIR/error.log" 2>/dev/null; then
    bad "lighttpd error.log has permission/temp-file errors:"
    grep -E 'opening temp-file failed|Permission denied' "$TMPDIR/error.log" | head -5
else
    ok "lighttpd error.log clean of permission/temp-file errors"
fi

echo
echo "$PASS passed, $FAIL failed"
exit $(( FAIL == 0 ? 0 : 1 ))
