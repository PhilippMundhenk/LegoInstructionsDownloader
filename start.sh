#!/bin/bash

NAME=lego
if [[ -z ${UID} ]]; then
  UID=1000
fi
if [[ -z ${GID} ]]; then
  GID=1000
fi

groupadd --gid "$GID" lego
adduser "$NAME" --uid $UID --gid "$GID" --disabled-password --force-badname --gecos ""
mkdir -p /downloads
chmod 777 /downloads
su - "$NAME" -c "/usr/sbin/lighttpd -D -f /etc/lighttpd/lighttpd.conf"