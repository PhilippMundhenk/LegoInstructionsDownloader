#!/bin/bash
# Regression test: the three-dot popover must not be painted underneath the
# card in the next grid row.
#
# The bug: `.card:hover` applies a transform, which makes the hovered card a
# stacking context. The popover's z-index is then only meaningful inside that
# card, and any positioned box that comes later in the DOM (a sibling card's
# `.card-image`, positioned for the Sold badge) paints over the part of the
# popover that overhangs the next row. Static CSS greps can't catch this — it
# is a paint-order question — so we render index.php with php-cli, load it in
# headless Chrome and ask the browser via elementFromPoint() which element
# actually wins at a point that lies inside both the popover and the card
# below. Hover cannot be triggered in headless mode, so the script mimics the
# hover rule by setting the same transform inline on the card, for both the
# card that owns the menu and the card underneath.
#
# Requires: php (or PHP=/path/to/php) and Chrome/Chromium (auto-detected, or
# CHROME=/path/to/binary). Exits 2 if either is missing.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PHP="${PHP:-$(command -v php || true)}"

find_chrome() {
    if [[ -n "${CHROME:-}" ]]; then printf '%s' "$CHROME"; return; fi
    local c
    for c in google-chrome google-chrome-stable chromium chromium-browser chrome; do
        if command -v "$c" >/dev/null 2>&1; then command -v "$c"; return; fi
    done
    for c in "/c/Program Files/Google/Chrome/Application/chrome.exe" \
             "/c/Program Files (x86)/Google/Chrome/Application/chrome.exe" \
             "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"; do
        if [[ -x "$c" ]]; then printf '%s' "$c"; return; fi
    done
}
CHROME_BIN="$(find_chrome)"

if [[ -z "$PHP" ]]; then echo "FAIL: php not found (set PHP=)"; exit 2; fi
if [[ -z "$CHROME_BIN" ]]; then echo "FAIL: chrome not found (set CHROME=)"; exit 2; fi

PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); printf '  \033[32mok\033[0m   %s\n' "$*"; }
bad() { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$*"; }

TMP="$(mktemp -d -t lego-stack.XXXXXX)"
trap 'if [[ -z "${STACK_KEEP:-}" ]]; then rm -rf "$TMP"; else echo "kept $TMP"; fi' EXIT

# --- fixture: 8 v2 sets, enough for several rows at a 2-column viewport.
DL="$TMP/downloads"
for id in 10001 10002 10003 10004 10005 10006 10007 10008; do
    mkdir -p "$DL/$id"
    printf 'Set number %s\n' "$id" > "$DL/$id/name.txt"
    printf 'x' > "$DL/$id/${id}_Prod.png"
    printf 'x' > "$DL/$id/6${id}.pdf"
    printf 'x' > "$DL/$id/6${id}.png"
done

# --- render index.php to a static page in a docroot that also has main.css.
DOC="$TMP/www"
mkdir -p "$DOC"
cp "$ROOT/main.css" "$ROOT/favicon.svg" "$DOC/"
PHP_ARGS=()
# Portable/zip PHP builds (Windows CI-less dev boxes) ship mbstring as a
# separate extension; ask for it explicitly if an ext dir sits next to php.
if [[ -d "$(dirname "$PHP")/ext" ]]; then
    PHP_ARGS=(-d "extension_dir=$(dirname "$PHP")/ext" -d extension=mbstring)
fi
if ! (cd "$ROOT" && DOWNLOADS_DIR="$DL" "$PHP" "${PHP_ARGS[@]}" index.php > "$DOC/index.html" 2>"$TMP/php.err"); then
    echo "FAIL: php could not render index.php:"; cat "$TMP/php.err"; exit 2
fi
grep -q '<ul class="cards"' "$DOC/index.html" \
    && ok "index.php rendered the card grid" \
    || { bad "index.php did not render cards"; head -20 "$DOC/index.html"; }

# --- inject the probe. It runs after the page's own script, so the real menu
# click handler is what opens the popover.
PROBE="$TMP/probe.js"
cat > "$PROBE" <<'JS'
(function () {
    const res = {};
    try {
        const cards = Array.from(document.querySelectorAll('.card'));
        const first = cards[0];
        const r0 = first.getBoundingClientRect();
        const below = cards.find(c => c.getBoundingClientRect().top > r0.bottom - 1);
        res.rows = below ? 'multi' : 'single';
        if (!below) throw new Error('fixture rendered a single row; widen the fixture');

        first.querySelector('.card-menu-btn').click();
        const pop = first.querySelector('.card-menu-popover');
        res.open = !pop.hidden;
        res.menuOpenClass = first.classList.contains('menu-open');

        const pr = pop.getBoundingClientRect();
        const br = below.getBoundingClientRect();
        res.overlap = pr.bottom > br.top + 8;
        if (!res.overlap) throw new Error('popover does not overhang the next row; test is meaningless');

        // A point inside the popover AND inside the card below it.
        const x = pr.left + pr.width / 2;
        const y = br.top + 6;
        const hover = 'translateY(-2px)'; // same as .card:hover in main.css

        // Scenario A: the card that owns the menu is hovered (the common case:
        // the pointer is still on it right after clicking the dots).
        first.style.transform = hover;
        let el = document.elementFromPoint(x, y);
        res.hitOwnerHovered = pop.contains(el);
        res.hitOwnerHoveredEl = el ? (el.className || el.tagName) : null;

        // Scenario B: the pointer moved down onto the popover, which sits over
        // the next row, so the card below is the one being hovered.
        first.style.transform = '';
        below.style.transform = hover;
        el = document.elementFromPoint(x, y);
        res.hitBelowHovered = pop.contains(el);
        res.hitBelowHoveredEl = el ? (el.className || el.tagName) : null;

        // Scenario C: nothing hovered.
        below.style.transform = '';
        el = document.elementFromPoint(x, y);
        res.hitNoHover = pop.contains(el);
    } catch (e) {
        res.error = String(e);
    }
    const out = document.createElement('pre');
    out.id = 'stack-result';
    out.textContent = JSON.stringify(res);
    document.body.appendChild(out);
})();
JS
awk -v probe="$PROBE" '
    /<\/body>/ {
        print "<script>"
        while ((getline line < probe) > 0) print line
        print "</script>"
    }
    { print }
' "$DOC/index.html" > "$DOC/index.injected.html" && mv "$DOC/index.injected.html" "$DOC/index.html"
grep -q 'id = .stack-result.' "$DOC/index.html" \
    && ok "probe injected into rendered page" \
    || { bad "probe injection failed"; exit 1; }

# --- run headless Chrome. 760px wide gives two columns (cards are 320px min).
URL_PATH="$DOC/index.html"
if command -v cygpath >/dev/null 2>&1; then URL_PATH="$(cygpath -m "$URL_PATH")"; fi
case "$URL_PATH" in
    /*) URL="file://$URL_PATH" ;;
    *)  URL="file:///$URL_PATH" ;;
esac

DOM="$TMP/dom.html"
"$CHROME_BIN" --headless=new --disable-gpu --no-sandbox --hide-scrollbars \
    --window-size=760,1600 --virtual-time-budget=3000 \
    --user-data-dir="$TMP/profile" --no-first-run \
    --dump-dom "$URL" > "$DOM" 2>"$TMP/chrome.err"

RESULT="$(grep -o '<pre id="stack-result">[^<]*</pre>' "$DOM" | sed -e 's/<[^>]*>//g' -e 's/&quot;/"/g' | head -1)"
if [[ -z "$RESULT" ]]; then
    bad "probe produced no result (chrome stderr below)"
    tail -20 "$TMP/chrome.err"
    echo; echo "$PASS passed, $FAIL failed"; exit 1
fi
echo "  probe: $RESULT"

has() { printf '%s' "$RESULT" | grep -q "\"$1\":$2"; }

has error '"' && bad "probe error: $(printf '%s' "$RESULT" | sed 's/.*"error":"\([^"]*\)".*/\1/')"
has rows '"multi"'  && ok "fixture renders more than one row" || bad "fixture is a single row"
has open true       && ok "clicking the dots opens the popover" || bad "popover did not open"
has menuOpenClass true && ok "owner card gets .menu-open" || bad "owner card lacks .menu-open"
has overlap true    && ok "popover overhangs the next row (test is meaningful)" || bad "popover does not overhang the next row"
has hitOwnerHovered true \
    && ok "popover wins hit-test with its own card hovered" \
    || bad "popover LOSES to the next row with its own card hovered (hit: $(printf '%s' "$RESULT" | sed 's/.*"hitOwnerHoveredEl":"\([^"]*\)".*/\1/'))"
has hitBelowHovered true \
    && ok "popover wins hit-test with the card below hovered" \
    || bad "popover LOSES to the next row with the card below hovered (hit: $(printf '%s' "$RESULT" | sed 's/.*"hitBelowHoveredEl":"\([^"]*\)".*/\1/'))"
has hitNoHover true && ok "popover wins hit-test with nothing hovered" || bad "popover loses with nothing hovered"

echo
echo "$PASS passed, $FAIL failed"
exit $(( FAIL == 0 ? 0 : 1 ))
