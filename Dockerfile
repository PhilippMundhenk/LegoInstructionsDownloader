FROM python:3-bookworm

RUN DEBIAN_FRONTEND=noninteractive TZ=Etc/UTC apt-get update && apt-get -y install tzdata && apt-get -y clean

RUN apt-get -y update && apt-get -y upgrade && apt-get -y clean
RUN apt-get -y install \
		curl \
		lighttpd \
        php-cgi \
        php-curl \
		&& apt-get -y clean

RUN cp /etc/lighttpd/conf-available/05-auth.conf /etc/lighttpd/conf-enabled/
RUN cp /etc/lighttpd/conf-available/15-fastcgi-php.conf /etc/lighttpd/conf-enabled/
RUN cp /etc/lighttpd/conf-available/10-fastcgi.conf /etc/lighttpd/conf-enabled/
RUN mkdir -p /var/run/lighttpd
RUN touch /var/run/lighttpd/php-fastcgi.socket
RUN chown -R www-data /var/run/lighttpd

ADD index.php /var/www/html
ADD log.php /var/www/html
ADD main.css /var/www/html
ADD fetch.sh /var/www/html
RUN chown -R www-data /var/www/
RUN chmod u+x /var/www/html/fetch.sh

EXPOSE 80

ADD start.sh /
RUN chmod u+x start.sh

CMD ["bash", "-c", "/start.sh"]