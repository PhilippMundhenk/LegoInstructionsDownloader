#!/bin/bash
# migrate.sh's loop must walk every set in /downloads, even if one fails.
# This is the bug class that hid every set after id 60369: a mid-loop wget
# error halted iteration, so later dirs never got name.txt and stayed
# invisible to parseSet.
#
# We can't easily reproduce the SMB permission-denied condition on Termux,
# but we can simulate "writing this name.txt failed" by making one of the
# target dirs read-only and asserting the loop still finishes the others.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMPDIR="$(mktemp -d -t lego-migrate.XXXXXX)"
trap 'chmod -R u+w "$TMPDIR" 2>/dev/null; rm -rf "$TMPDIR"' EXIT INT TERM

PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); printf '  \033[32mok\033[0m   %s\n' "$*"; }
bad() { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$*"; }

echo "migrate.sh resilience tests"

# --- Fixture: 4 legacy sets — A and C writable, B has a read-only dir so the
# name.txt write fails, D is already migrated (has name.txt) and must be left
# alone. Plus a non-numeric dir that must be ignored entirely.
mkdir -p "$TMPDIR/10000" "$TMPDIR/20000" "$TMPDIR/30000" "$TMPDIR/40000" "$TMPDIR/random"
printf 'fake-pdf' > "$TMPDIR/10000/some.pdf"
printf 'fake-pdf' > "$TMPDIR/20000/some.pdf"
printf 'fake-pdf' > "$TMPDIR/30000/some.pdf"
printf 'Already Named\n' > "$TMPDIR/40000/name.txt"
printf 'fake-pdf' > "$TMPDIR/40000/some.pdf"
printf 'fake-pdf' > "$TMPDIR/random/stray.pdf"

# Lock 20000 so the printf > name.txt fails for it.
chmod a-w "$TMPDIR/20000"

# Run migration.
LOG="$TMPDIR/migrate.log"
bash "$ROOT/migrate.sh" "$TMPDIR" > "$LOG" 2>&1 || true

# --- A: writable legacy set ends up with the fallback name.
if [[ -f "$TMPDIR/10000/name.txt" ]] && grep -q '^Set 10000$' "$TMPDIR/10000/name.txt"; then
    ok "writable set 10000 got fallback name.txt"
else
    bad "writable set 10000 missing name.txt or wrong content"
fi

# --- B: read-only dir didn't get name.txt (because the write failed) BUT the
# loop did not abort — we verify by checking that C (further along) was reached.
if [[ -f "$TMPDIR/30000/name.txt" ]]; then
    ok "loop continued past failure (30000 was reached)"
else
    bad "loop halted before 30000 — exactly the bug we're guarding"
fi

# --- B: the failure was logged so it's diagnosable, not silent.
if grep -qE '20000:.*ERROR.*name\.txt' "$LOG"; then
    ok "failure for 20000 was logged with ERROR"
else
    bad "failure for 20000 was NOT logged — would have been invisible"
    cat "$LOG"
fi

# --- D: already-migrated set was not touched.
content=$(cat "$TMPDIR/40000/name.txt")
if [[ "$content" == "Already Named" ]]; then
    ok "already-migrated set 40000 was left untouched"
else
    bad "already-migrated 40000 was rewritten: '$content'"
fi

# --- random_*: non-numeric dir was ignored (never gets name.txt).
if [[ ! -f "$TMPDIR/random/name.txt" ]]; then
    ok "non-numeric dir 'random' was ignored"
else
    bad "non-numeric dir got name.txt — should be ignored"
fi

# --- Summary line reflects mixed outcome (1 migrated for 10000, 1 for 30000,
# 1 skipped for 40000, 1 failed for 20000).
if grep -qE 'done — 2 migrated, 1 already current, 1 failed' "$LOG"; then
    ok "summary line counts correctly"
else
    bad "summary line wrong: $(grep 'done' "$LOG")"
fi

# --- HTML-based name extraction (when raw index is present locally) — covers
# the "first 'name' hit was 'Neu'" bug that fetch.sh also has. migrate.sh
# uses the same regex.
mkdir -p "$TMPDIR/31099"
cat > "$TMPDIR/31099/31099" <<'HTML'
{"name":"Neu","__typename":"Badge"},
{"name":"Propellerflugzeug","setNumber":"31099","__typename":"Product"}
HTML
printf 'fake-pdf' > "$TMPDIR/31099/foo.pdf"
bash "$ROOT/migrate.sh" "$TMPDIR" >> "$LOG" 2>&1 || true
if grep -q '^Propellerflugzeug$' "$TMPDIR/31099/name.txt"; then
    ok "name extraction from raw HTML anchors on setNumber, not 'Neu'"
else
    bad "name extraction wrong: $(cat "$TMPDIR/31099/name.txt")"
fi

echo
echo "$PASS passed, $FAIL failed"
exit $(( FAIL == 0 ? 0 : 1 ))
