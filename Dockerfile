FROM python:3-bookworm

RUN DEBIAN_FRONTEND=noninteractive TZ=Etc/UTC apt-get update && apt-get -y install tzdata && apt-get -y clean

RUN apt-get update && apt-get install -y --no-install-recommends apt-utils && apt-get -y clean

RUN apt-get -y update && apt-get -y upgrade && apt-get -y clean
RUN apt-get -y install \
		curl \
		lighttpd \
        php-cgi \
        php-curl \
		libglib2.0-0 \
		libnss3 \
		libgconf-2-4 \
		libfontconfig1 \
		chromium \
		&& apt-get -y clean

RUN cp /etc/lighttpd/conf-available/05-auth.conf /etc/lighttpd/conf-enabled/
RUN cp /etc/lighttpd/conf-available/15-fastcgi-php.conf /etc/lighttpd/conf-enabled/
RUN cp /etc/lighttpd/conf-available/10-fastcgi.conf /etc/lighttpd/conf-enabled/
RUN mkdir -p /var/run/lighttpd
RUN touch /var/run/lighttpd/php-fastcgi.socket
RUN chown -R www-data /var/run/lighttpd

RUN pip install selenium

ADD index.php /var/www/html
ADD fetch.py /var/www/
RUN chown -R www-data /var/www/
RUN chmod u+x /var/www/fetch.py 

CMD ["/usr/sbin/lighttpd", "-D", "-f", "/etc/lighttpd/lighttpd.conf"]