#!/bin/bash
# Static invariants for start.sh.
#
# These two bugs slipped past us in production:
#
#   1. lighttpd's upload temp dir (/var/cache/lighttpd/uploads) was owned by
#      the package's default www-data uid (33), but we renumber www-data to
#      whatever UID env passes — so the new www-data couldn't write its own
#      uploads, and every POST got "opening temp-file failed: Permission
#      denied" in error.log.
#
#   2. The tail -F that forwards lego.log to docker stdout started AFTER
#      migrate.sh ran AND used -n 0 — so every [migrate] line was silently
#      dropped. From outside, the migration looked like it never ran.
#
# Both are checkable from the script alone. This guards them.
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
START="$ROOT/start.sh"

PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); printf '  \033[32mok\033[0m   %s\n' "$*"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$*"; }

echo "static checks on $START"

# Invariant 1: every dir lighttpd writes to as the renumbered www-data must
# be chowned in start.sh. /var/cache/lighttpd is the one we missed.
for dir in /var/log/lighttpd /var/run/lighttpd /var/cache/lighttpd /var/www; do
    if grep -qF "chown" "$START" && grep -qF "$dir" "$START" \
       && grep -B1 -A1 -F "$dir" "$START" | grep -q "chown"; then
        ok "chown covers $dir"
    else
        bad "chown does NOT cover $dir — www-data won't be able to write there after usermod"
    fi
done

# Invariant 2: migrate.sh output must reach docker stdout. Either (a) it tees
# to stdout (within ~3 lines of the migrate.sh invocation, to allow line
# continuations / multi-line blocks), or (b) the tail -F that forwards
# lego.log starts BEFORE migrate.sh runs.
migrate_line=$(grep -nE 'migrate\.sh' "$START" | grep -v '^[0-9]*:#' | head -1 | cut -d: -f1)
tail_line=$(grep -nE '^[^#]*tail .*-F' "$START" | head -1 | cut -d: -f1)
# Look for `tee` in the few lines around the migrate.sh invocation. awk gives
# us a multi-line context window, which catches `{ ... migrate.sh ...; } | tee`
# split across lines.
tee_near_migrate=0
if [[ -n "$migrate_line" ]]; then
    window_start=$((migrate_line > 2 ? migrate_line - 2 : 1))
    window_end=$((migrate_line + 3))
    if awk -v s="$window_start" -v e="$window_end" 'NR>=s && NR<=e' "$START" | grep -q '\btee\b'; then
        tee_near_migrate=1
    fi
fi

if [[ -z "$migrate_line" ]]; then
    bad "couldn't locate migrate.sh invocation in start.sh"
elif [[ "$tee_near_migrate" -eq 1 ]]; then
    ok "migrate.sh output tees to stdout (visible in docker logs)"
elif [[ -n "$tail_line" && "$tail_line" -lt "$migrate_line" ]]; then
    ok "tail -F starts before migrate.sh runs (line $tail_line < $migrate_line)"
else
    bad "migrate.sh output won't reach docker logs: no tee near migrate (line $migrate_line), and tail (line ${tail_line:-?}) doesn't precede it"
fi

# Invariant 3: tail must NOT use -n 0 if anything is written to those logs
# before tail starts. With current ordering this is fine, but if someone
# reorders, this catches it.
if grep -qE 'tail -n 0 -F' "$START"; then
    if [[ "$tee_near_migrate" -eq 1 ]] || { [[ -n "$tail_line" ]] && [[ -n "$migrate_line" ]] && [[ "$tail_line" -lt "$migrate_line" ]]; }; then
        ok "tail -n 0 is safe (no prior writes to its files are lost)"
    else
        bad "tail -n 0 will swallow migrate.sh output"
    fi
fi

# Invariant 4: UID/GID handling cannot use bash's readonly \${UID} builtin —
# must read via printenv (this bit us during the rewrite). Catch regressions.
if grep -qE '^\s*USER_UID=.*printenv UID' "$START"; then
    ok "UID env read via printenv (bash's \$UID is readonly to the process uid)"
else
    bad "UID env not read via printenv — \${UID} would resolve to the process uid (0), not the docker env"
fi

echo
echo "$PASS passed, $FAIL failed"
exit $(( FAIL == 0 ? 0 : 1 ))
