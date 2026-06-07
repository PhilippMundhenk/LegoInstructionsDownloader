#!/bin/bash
# fetch.sh scrapes lego.com HTML with three handwritten grep patterns. Each
# one has bitten us in production at some point — they're fragile by nature.
# These fixture-based tests pin the current behavior so the next page-format
# change shows up here, not as a half-broken set in the user's library.
set -uo pipefail

PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); printf '  \033[32mok\033[0m   %s\n' "$*"; }
bad() { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$*"; }

assert_eq() {
    local label="$1" want="$2" got="$3"
    if [[ "$want" == "$got" ]]; then
        ok "$label"
    else
        bad "$label: want='$want' got='$got'"
    fi
}

echo "fetch.sh scraping regex tests"

# --- 1. Building instruction URLs: stable pattern. Just sanity-check.
fixture=$(mktemp)
cat > "$fixture" <<'HTML'
<a href="https://www.lego.com/cdn/product-assets/product.bi.core.pdf/6308552.pdf">PDF1</a>
<a href="https://www.lego.com/cdn/product-assets/product.bi.core.pdf/6308553.pdf">PDF2</a>
<img src="https://www.lego.com/cdn/product-assets/product.bi.core.png/6308552.png">
<a href="https://example.com/not-a-lego-asset.pdf">unrelated</a>
HTML
count=$(grep -oP "https://www.lego.com/cdn/product-assets/product.bi.core.\w{3}/\d{7}.\w{3}" "$fixture" | sort -u | wc -l)
assert_eq "bi.core grep finds 3 unique BI assets, ignores unrelated" "3" "$count"
rm "$fixture"

# --- 2. Product image URLs: the bug was a lazy "(.*?)" capturing the WHOLE
# srcset string (3 URLs glued by " 1x, " " 2x, "). Curl then errored
# "Failed to extract a filename from the URL" and no image landed.
# The fix is the strict [^"?\s,&]+ class.
fixture=$(mktemp)
cat > "$fixture" <<'HTML'
<img srcset="https://www.lego.com/cdn/product-assets/product.img.pri/31099_Prod.jpg?q=80 1x, https://www.lego.com/cdn/product-assets/product.img.pri/31099_Prod_2x.jpg?q=80 2x, https://www.lego.com/cdn/product-assets/product.img.pri/31099_Prod_3x.jpg?q=80 3x" />
<img src="https://www.lego.com/cdn/product-assets/product.img.pri/31099_Box.jpg?q=90&w=400">
HTML
mapfile -t urls < <(grep -oP 'https://www\.lego\.com/cdn/product-assets/product\.img\.pri/[^"?\s,&]+' "$fixture" | sort -u)
assert_eq "img.pri grep splits srcset into 4 distinct URLs" "4" "${#urls[@]}"
# none of them should contain spaces, commas, quotes, query-marks, or ampersands
for u in "${urls[@]}"; do
    if [[ "$u" =~ [\ ,\"?\&] ]]; then
        bad "extracted URL still has separators: '$u'"
        break
    fi
done
ok "img.pri URLs are clean (no separators, no glued duplicates)"
rm "$fixture"

# --- 3. Set name extraction: the bug was picking up the FIRST '"name":' which
# on modern lego.com is the badge "Neu" or "SMART Play", not the set title.
# The fix anchors the regex on '"setNumber":"<ID>"' which immediately follows
# the right name.
fixture=$(mktemp)
ID="31099"
cat > "$fixture" <<HTML
{"name":"Neu","__typename":"Badge"},
{"name":"SMART Play","__typename":"Category"},
{"name":"Propellerflugzeug","setNumber":"$ID","__typename":"Product","price":12.99},
<meta property="og:title" content="LEGO Creator 31099 — Propellerflugzeug">
HTML
NAME=$(grep -oP '"name":"[^"]+","setNumber":"'"$ID"'"' "$fixture" | head -n1 | sed 's/.*"name":"\([^"]*\)".*/\1/')
assert_eq "name extraction anchors on setNumber, not first 'name' hit" "Propellerflugzeug" "$NAME"
rm "$fixture"

# --- 4. og:title fallback (used when setNumber-anchored regex misses)
fixture=$(mktemp)
cat > "$fixture" <<'HTML'
<html>
<head>
  <meta property="og:title" content="Cute Pug">
  <title>Lego 30640</title>
</head>
</html>
HTML
NAME=$(grep -oP '<meta property="og:title" content="[^"]+"' "$fixture" | head -n1 | sed 's/.*content="\([^"]*\)".*/\1/')
assert_eq "og:title fallback extracts the title attribute value" "Cute Pug" "$NAME"
rm "$fixture"

# --- 5. Retired-set cleanup: a real fetch against a discontinued set returns
# a generic lego.com landing page with zero asset URLs. fetch.sh detects this
# (COUNT==0) and removes the half-empty dir so it doesn't leave a ghost card.
fixture=$(mktemp -d)
ID="10848"
printf '<html>set retired, no assets here</html>' > "$fixture/$ID"
printf 'placeholder\n' > "$fixture/name.txt"
printf 'placeholder\n' > "$fixture/id.txt"
# Simulate fetch.sh's cleanup branch (lines 71-76 of fetch.sh): remove the dir
# if the only files left are the raw HTML, id.txt, and name.txt.
leftover=$(find "$fixture" -mindepth 1 -maxdepth 1 \
    ! -name "$ID" ! -name id.txt ! -name name.txt | head -n1)
if [[ -z "$leftover" ]]; then
    ok "retired-set cleanup detects empty asset state"
else
    bad "retired-set cleanup wrongly sees content: $leftover"
fi
rm -rf "$fixture"

echo
echo "$PASS passed, $FAIL failed"
exit $(( FAIL == 0 ? 0 : 1 ))
