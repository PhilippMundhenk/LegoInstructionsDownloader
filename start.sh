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
chown ${UID}:${GID} /var/log/lighttpd
/usr/sbin/lighttpd -D -f /etc/lighttpd/lighttpd.conf