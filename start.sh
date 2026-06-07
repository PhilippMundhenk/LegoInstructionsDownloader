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
mkdir -p /var/www
chown -R "${USER_UID}:${USER_GID}" /var/www/

LEGO_LOG="${LEGO_LOG_FILE:-/var/log/lego.log}"
touch "$LEGO_LOG" /var/log/lighttpd/error.log /var/log/lighttpd/access.log
chown "${USER_UID}:${USER_GID}" "$LEGO_LOG" /var/log/lighttpd/error.log /var/log/lighttpd/access.log

echo "[start] running as www-data uid=${USER_UID} gid=${USER_GID}"

# Forward log files to docker stdout so `docker logs` shows fetch progress + errors.
tail -n 0 -F "$LEGO_LOG" /var/log/lighttpd/error.log /var/log/lighttpd/access.log &
TAIL_PID=$!

cleanup() {
    kill "$TAIL_PID" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

echo "[start] launching lighttpd; logs follow"
exec /usr/sbin/lighttpd -D -f /etc/lighttpd/lighttpd.conf
