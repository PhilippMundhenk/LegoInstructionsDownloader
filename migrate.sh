#!/bin/bash
# Idempotent one-shot: convert any legacy set dir to the current v2 format by
# writing a name.txt. Runs from start.sh on every container boot, so it picks
# up anything new the next pull. Already-migrated sets (have name.txt or
# data.json) are skipped cheaply.
#
# Legacy sets predate the rewritten fetch.sh and typically contain only the
# downloaded PDFs/images. Some still have the raw HTML index file (saved as
# the set id). When the HTML is there we parse the name out of it; otherwise
# we re-fetch the index from lego.com (one short request per legacy set).
set -uo pipefail

DIR="${1:-${DOWNLOADS_DIR:-/downloads}}"
LOCALE="${LEGO_LOCALE:-de-de}"

log() { printf '[%s] [migrate] %s\n' "$(date -u +%FT%TZ)" "$*"; }

if [[ ! -d "$DIR" ]]; then
    log "downloads dir '$DIR' missing — nothing to migrate"
    exit 0
fi

MIGRATED=0
SKIPPED=0
FAILED=0

extract_name_from_html() {
    local html="$1" id="$2" name=""
    name=$(grep -oP '"name":"[^"]+","setNumber":"'"$id"'"' "$html" 2>/dev/null \
        | head -n1 | sed 's/.*"name":"\([^"]*\)".*/\1/')
    if [[ -z "$name" ]]; then
        name=$(grep -oP '<meta property="og:title" content="[^"]+"' "$html" 2>/dev/null \
            | head -n1 | sed 's/.*content="\([^"]*\)".*/\1/')
    fi
    printf '%s' "$name"
}

shopt -s nullglob
for child in "$DIR"/*/; do
    child="${child%/}"
    id="$(basename "$child")"

    [[ "$id" == "@eaDir" ]] && continue
    [[ ! "$id" =~ ^[0-9]{1,8}$ ]] && continue

    if [[ -f "$child/name.txt" ]] || [[ -f "$child/data.json" ]]; then
        SKIPPED=$((SKIPPED+1))
        continue
    fi

    # Need at least one asset to consider this a real set, not a stray dir.
    has_asset=0
    for f in "$child"/*.pdf "$child"/*.png "$child"/*.jpg "$child"/*.jpeg; do
        [[ -e "$f" ]] && { has_asset=1; break; }
    done
    if [[ "$has_asset" -eq 0 ]]; then
        continue
    fi

    name=""
    if [[ -s "$child/$id" ]]; then
        name="$(extract_name_from_html "$child/$id" "$id")"
    fi

    if [[ -z "$name" ]]; then
        url="https://www.lego.com/$LOCALE/service/building-instructions/$id"
        tmp="$child/.$id.fetching"
        if wget --quiet --tries=2 --timeout=15 -O "$tmp" "$url" 2>/dev/null && [[ -s "$tmp" ]]; then
            mv "$tmp" "$child/$id"
            name="$(extract_name_from_html "$child/$id" "$id")"
        else
            rm -f "$tmp"
        fi
    fi

    if [[ -z "$name" ]]; then
        name="Set $id"
        log "$id: could not derive name, using fallback"
    fi

    if printf '%s\n' "$name" > "$child/name.txt" 2>/dev/null; then
        log "$id -> '$name'"
        MIGRATED=$((MIGRATED+1))
    else
        log "$id: ERROR could not write name.txt (permission denied on mount?)"
        FAILED=$((FAILED+1))
    fi
done

log "done — $MIGRATED migrated, $SKIPPED already current, $FAILED failed"
