#!/bin/bash
# Usage: fetch.sh <set_id> <downloads_dir>
# Logs to stdout; the PHP caller redirects this to the shared log file.
set -uo pipefail

ID="${1:-}"
DIR="${2:-/downloads}"
LOCALE="${LEGO_LOCALE:-de-de}"

log() {
    printf '[%s] [%s] %s\n' "$(date -u +%FT%TZ)" "${ID:-?}" "$*"
}

if [[ -z "$ID" ]]; then
    log "ERROR: no set id provided"
    exit 2
fi
if ! [[ "$ID" =~ ^[0-9]{1,8}$ ]]; then
    log "ERROR: invalid set id '$ID'"
    exit 2
fi
if [[ ! -d "$DIR" ]]; then
    log "ERROR: downloads dir '$DIR' does not exist"
    exit 2
fi

TARGET="$DIR/$ID"
log "preparing $TARGET"
mkdir -p "$TARGET"
cd "$TARGET" || { log "ERROR: cannot cd into $TARGET"; exit 2; }

INDEX_URL="https://www.lego.com/$LOCALE/service/building-instructions/$ID"
log "fetching index $INDEX_URL"
if ! wget --quiet --tries=3 --timeout=30 -O "$ID" "$INDEX_URL"; then
    log "ERROR: failed to fetch instruction index"
    exit 3
fi

if [[ ! -s "$ID" ]]; then
    log "ERROR: empty response for index"
    exit 3
fi

FILES_LIST="$(mktemp)"
RESULTS_DIR="$(mktemp -d)"
trap 'rm -f "$FILES_LIST"; rm -rf "$RESULTS_DIR"' EXIT

grep -oP "https://www.lego.com/cdn/product-assets/product.bi.core.\w{3}/\d{7}.\w{3}" "$ID" | sort -u >  "$FILES_LIST"
grep -oP "https://www.lego.com/cdn/product-assets/product.img.pri.*?\"" "$ID" | sed 's/.$//' | sort -u >> "$FILES_LIST"
grep -oP '{"name":".*?"' "$ID" | head -n1 | sed 's/{"name":"//' | sed 's/"//' > name.txt
# id.txt is kept for compatibility with older listings that consumed it
cp "$ID" id.txt

COUNT=$(wc -l < "$FILES_LIST" | tr -d ' ')
log "discovered $COUNT asset URLs"

if [[ "$COUNT" -eq 0 ]]; then
    log "WARN: no asset URLs found — Lego page format may have changed"
fi

FAILED=0
DOWNLOADED=0
PIDS=()

i=0
while IFS= read -r url; do
    [[ -z "$url" ]] && continue
    (
        if curl --connect-timeout 30 --retry 5 --retry-delay 5 --retry-max-time 60 -sS -O "$url"; then
            echo ok > "$RESULTS_DIR/$i"
        else
            echo "fail $url" > "$RESULTS_DIR/$i"
        fi
    ) &
    PIDS+=($!)
    i=$((i+1))
done < "$FILES_LIST"

for pid in "${PIDS[@]}"; do wait "$pid"; done

for f in "$RESULTS_DIR"/*; do
    [[ -e "$f" ]] || continue
    if grep -q '^ok' "$f"; then
        DOWNLOADED=$((DOWNLOADED+1))
    else
        FAILED=$((FAILED+1))
        log "ERROR: $(cat "$f")"
    fi
done

NAME="$(cat name.txt 2>/dev/null || echo "")"
log "completed — $DOWNLOADED ok, $FAILED failed — '$NAME'"

if [[ "$FAILED" -gt 0 ]]; then
    exit 4
fi
exit 0
