#!/bin/bash

if [[ -z ${UID} ]]; then
  UID=1000
fi
if [[ -z ${GID} ]]; then
  GID=1000
fi

usermod -u ${UID} www-data
groupmod -g ${GID} www-data
mkdir -p /downloads
chmod 777 /downloads
mkdir -p /var/log/lighttpd
chown -R ${UID}:${GID} /var/log/lighttpd
mkdir -p /var/run/lighttpd
chown -R ${UID}:${GID} /var/run/lighttpd
mkdir -p /var/www
chown -R ${UID}:${GID} /var/www/
/usr/sbin/lighttpd -D -f /etc/lighttpd/lighttpd.conf