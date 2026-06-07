#!/bin/bash
set -euo pipefail

: "${UID:=1000}"
: "${GID:=1000}"

usermod -u "${UID}" www-data >/dev/null 2>&1 || true
groupmod -g "${GID}" www-data >/dev/null 2>&1 || true

mkdir -p /downloads
chmod 777 /downloads
mkdir -p /var/log/lighttpd
chown -R "${UID}:${GID}" /var/log/lighttpd
mkdir -p /var/run/lighttpd
chown -R "${UID}:${GID}" /var/run/lighttpd
mkdir -p /var/www
chown -R "${UID}:${GID}" /var/www/

LEGO_LOG="${LEGO_LOG_FILE:-/var/log/lego.log}"
touch "$LEGO_LOG" /var/log/lighttpd/error.log /var/log/lighttpd/access.log
chown "${UID}:${GID}" "$LEGO_LOG" /var/log/lighttpd/error.log /var/log/lighttpd/access.log

# Forward log files to docker stdout so `docker logs` shows fetch progress + errors.
tail -n 0 -F "$LEGO_LOG" /var/log/lighttpd/error.log /var/log/lighttpd/access.log &
TAIL_PID=$!

cleanup() {
    kill "$TAIL_PID" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

echo "[start] launching lighttpd; logs follow"
exec /usr/sbin/lighttpd -D -f /etc/lighttpd/lighttpd.conf
