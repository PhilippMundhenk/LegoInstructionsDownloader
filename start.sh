#!/bin/bash
set -euo pipefail

# UID is a readonly bash builtin (always the process UID, i.e. 0 in-container),
# so we have to fish the docker-passed value out of the raw environ.
USER_UID="$(printenv UID 2>/dev/null || true)"
USER_GID="$(printenv GID 2>/dev/null || true)"
USER_UID="${USER_UID:-1000}"
USER_GID="${USER_GID:-1000}"

usermod  -o -u "${USER_UID}" www-data >/dev/null 2>&1 || true
groupmod -o -g "${USER_GID}" www-data >/dev/null 2>&1 || true

mkdir -p /downloads
chmod 777 /downloads
mkdir -p /var/log/lighttpd
chown -R "${USER_UID}:${USER_GID}" /var/log/lighttpd
mkdir -p /var/run/lighttpd
chown -R "${USER_UID}:${USER_GID}" /var/run/lighttpd
# lighttpd writes upload temp files here. The package-installed dir is owned by
# uid 33 (its default www-data); we just changed www-data to UID, so chown it.
mkdir -p /var/cache/lighttpd/uploads
chown -R "${USER_UID}:${USER_GID}" /var/cache/lighttpd
mkdir -p /var/www
chown -R "${USER_UID}:${USER_GID}" /var/www/

LEGO_LOG="${LEGO_LOG_FILE:-/var/log/lego.log}"
touch "$LEGO_LOG" /var/log/lighttpd/error.log /var/log/lighttpd/access.log
chown "${USER_UID}:${USER_GID}" "$LEGO_LOG" /var/log/lighttpd/error.log /var/log/lighttpd/access.log

echo "[start] running as www-data uid=${USER_UID} gid=${USER_GID}"

# Mount diagnostics — invaluable for SMB/NFS mounts where uid/perms surprise you.
DL_DIR="${DOWNLOADS_DIR:-/downloads}"
if [[ -d "$DL_DIR" ]]; then
    echo "[start] $DL_DIR exists: $(stat -c '%A %u:%g' "$DL_DIR" 2>/dev/null || echo '?')"
    echo "[start] $DL_DIR contains $(find "$DL_DIR" -mindepth 1 -maxdepth 1 -not -name '@eaDir' 2>/dev/null | wc -l) entries"
    # Probe a few children + their marker files so we know if old sets lack name.txt.
    find "$DL_DIR" -mindepth 1 -maxdepth 1 -type d -not -name '@eaDir' 2>/dev/null | head -3 | while read -r child; do
        echo "[start]   $(basename "$child"): $(ls "$child" 2>/dev/null | tr '\n' ' ' | head -c 200)"
    done
    # Confirm www-data can actually read it (the whole point of UID/GID env).
    if ! su -s /bin/sh www-data -c "test -r '$DL_DIR' && ls '$DL_DIR' >/dev/null 2>&1"; then
        echo "[start] WARNING: www-data (uid=${USER_UID}) cannot read $DL_DIR — list will appear empty"
    fi
else
    echo "[start] WARNING: $DL_DIR does not exist — volume not mounted?"
fi

# Migrate any pre-rewrite set dirs (with raw PDFs but no name.txt) to the
# current format. Idempotent: already-migrated sets are skipped. We tee to
# stdout so the per-set [migrate] lines land directly in `docker logs` —
# don't rely on the tail below (it starts with -n 0 and would skip them).
if [[ -x /var/www/html/migrate.sh ]]; then
    echo "[start] running migration"
    { su -s /bin/bash www-data -c "/var/www/html/migrate.sh '$DL_DIR'" 2>&1 || true; } \
        | tee -a "$LEGO_LOG"
fi

# Forward log files to docker stdout so `docker logs` shows fetch progress + errors.
tail -n 0 -F "$LEGO_LOG" /var/log/lighttpd/error.log /var/log/lighttpd/access.log &
TAIL_PID=$!

cleanup() {
    kill "$TAIL_PID" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

echo "[start] launching lighttpd; logs follow"
exec /usr/sbin/lighttpd -D -f /etc/lighttpd/lighttpd.conf
