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
chmod 777 /var/log/lighttpd/error.log
/usr/sbin/lighttpd -D -f /etc/lighttpd/lighttpd.conf