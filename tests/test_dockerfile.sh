#!/bin/bash
# Cheap static checks on the Dockerfile. The class of bug guarded here is
# "I added a feature that uses extension X but forgot to install it" — the
# container builds fine, runs, then 500s on the first request.
#
# Most recent case: index.php uses mb_strtolower for the search-data lowercasing
# but php-mbstring wasn't in the apt list, so the index threw a fatal at runtime.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DF="$ROOT/Dockerfile"

PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); printf '  \033[32mok\033[0m   %s\n' "$*"; }
bad() { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$*"; }

echo "static checks on $DF"

# Required apt packages. Dockerfile uses backslash continuations so each pkg
# sits on its own line — match the word, not a one-line install command.
for pkg in lighttpd php-cgi php-curl php-mbstring curl wget tzdata; do
    if grep -qw -- "$pkg" "$DF"; then
        ok "Dockerfile installs $pkg"
    else
        bad "Dockerfile does NOT install $pkg"
    fi
done

# Every file the runtime needs must be COPY'd/ADD'd into the image.
# Forgetting one means a 404 (for static files) or a "not found" log line
# (for scripts) at runtime.
for f in index.php list.php log.php download.php main.css lib.php fetch.sh migrate.sh start.sh; do
    if grep -qE "(ADD|COPY)\s+$f\b" "$DF"; then
        ok "Dockerfile copies $f"
    else
        bad "Dockerfile does NOT copy $f"
    fi
done

# The /downloads symlink — without this the docroot can't serve assets.
if grep -qE 'ln -s /downloads' "$DF"; then
    ok "Dockerfile creates /downloads symlink under docroot"
else
    bad "Dockerfile does NOT create /downloads symlink"
fi

# start.sh must be executable AND must be the container entrypoint.
if grep -qE 'chmod[^#]+start\.sh' "$DF"; then
    ok "start.sh is made executable"
else
    bad "start.sh is not chmod'd in Dockerfile"
fi
if grep -qE '(CMD|ENTRYPOINT)[^#]*start\.sh' "$DF"; then
    ok "start.sh is the container entrypoint"
else
    bad "start.sh is not the container entrypoint"
fi

# fetch.sh / migrate.sh likewise need exec bits — they're su'd to from start.sh.
for f in fetch.sh migrate.sh; do
    if grep -qE "chmod[^#]+$f" "$DF"; then
        ok "$f is made executable"
    else
        bad "$f is not chmod'd"
    fi
done

# Required env vars used by lib.php / start.sh. The ENV block spans multiple
# lines via backslash continuation — match on the assignment, not the ENV
# keyword on the same line.
for var in LEGO_LOG_FILE DOWNLOADS_DIR; do
    if grep -qE "^\s*$var=" "$DF"; then
        ok "ENV $var is set"
    else
        bad "ENV $var is not set"
    fi
done

# Healthcheck guards against silent boot failures in compose.
if grep -q HEALTHCHECK "$DF"; then
    ok "HEALTHCHECK is defined"
else
    bad "HEALTHCHECK is missing"
fi

echo
echo "$PASS passed, $FAIL failed"
exit $(( FAIL == 0 ? 0 : 1 ))
