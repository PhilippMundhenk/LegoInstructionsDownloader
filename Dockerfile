FROM debian:bookworm-slim

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=Etc/UTC \
    LEGO_LOG_FILE=/var/log/lego.log \
    DOWNLOADS_DIR=/downloads

RUN apt-get update \
 && apt-get -y install --no-install-recommends \
        tzdata \
        ca-certificates \
        curl \
        wget \
        grep \
        sed \
        coreutils \
        lighttpd \
        php-cgi \
        php-curl \
        php-mbstring \
 && apt-get -y clean \
 && rm -rf /var/lib/apt/lists/*

RUN cp /etc/lighttpd/conf-available/15-fastcgi-php.conf /etc/lighttpd/conf-enabled/ \
 && cp /etc/lighttpd/conf-available/10-fastcgi.conf       /etc/lighttpd/conf-enabled/ \
 && sed -i 's|"index.html"|"index.php", "index.html"|' /etc/lighttpd/lighttpd.conf \
 && mkdir -p /var/run/lighttpd \
 && touch /var/run/lighttpd/php-fastcgi.socket \
 && chown -R www-data /var/run/lighttpd

COPY index.php list.php log.php download.php main.css lib.php /var/www/html/
COPY fetch.sh /var/www/html/
RUN chown -R www-data:www-data /var/www/ \
 && chmod u+x /var/www/html/fetch.sh \
 && ln -s /downloads /var/www/html/downloads

EXPOSE 80

COPY start.sh /
RUN chmod u+x /start.sh

HEALTHCHECK --interval=30s --timeout=5s CMD curl -fsS http://localhost/ >/dev/null || exit 1

CMD ["/start.sh"]
