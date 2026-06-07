FROM python:3-bookworm

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=Etc/UTC \
    LEGO_LOG_FILE=/var/log/lego.log \
    DOWNLOADS_DIR=/downloads

RUN apt-get update && apt-get -y install tzdata && apt-get -y clean

RUN apt-get -y update && apt-get -y upgrade && apt-get -y clean
RUN apt-get -y install \
        curl \
        wget \
        lighttpd \
        php-cgi \
        php-curl \
        php-mbstring \
        && apt-get -y clean

RUN cp /etc/lighttpd/conf-available/05-auth.conf /etc/lighttpd/conf-enabled/
RUN cp /etc/lighttpd/conf-available/15-fastcgi-php.conf /etc/lighttpd/conf-enabled/
RUN cp /etc/lighttpd/conf-available/10-fastcgi.conf /etc/lighttpd/conf-enabled/
RUN echo 'dir-listing.activate = "enable"' >> /etc/lighttpd/lighttpd.conf
RUN mkdir -p /var/run/lighttpd
RUN touch /var/run/lighttpd/php-fastcgi.socket
RUN chown -R www-data /var/run/lighttpd

ADD index.php /var/www/html
ADD log.php /var/www/html
ADD download.php /var/www/html
ADD delete.php /var/www/html
ADD rename.php /var/www/html
ADD list.php /var/www/html
ADD main.css /var/www/html
ADD favicon.svg /var/www/html
ADD lib.php /var/www/html
ADD fetch.sh /var/www/html
ADD migrate.sh /var/www/html
RUN chown -R www-data /var/www/
RUN chmod u+x /var/www/html/fetch.sh /var/www/html/migrate.sh

RUN ln -s /downloads /var/www/html/downloads

EXPOSE 80

ADD start.sh /
RUN chmod u+x start.sh

HEALTHCHECK --interval=30s --timeout=5s CMD curl -fsS http://localhost/ >/dev/null || exit 1

CMD ["bash", "-c", "/start.sh"]
