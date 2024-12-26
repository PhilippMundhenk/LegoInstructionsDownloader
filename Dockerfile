FROM ubuntu:22.04

RUN DEBIAN_FRONTEND=noninteractive TZ=Etc/UTC apt-get update && apt-get -y install tzdata && apt-get -y clean

RUN apt-get update && apt-get install -y --no-install-recommends apt-utils && apt-get -y clean

RUN apt-get -y update && apt-get -y upgrade && apt-get -y clean
RUN apt-get -y install \
		curl \
		lighttpd \
        php-cgi \
        php-curl \
		wget \
		libfreetype6 libfreetype6-dev \
		libfontconfig1 libfontconfig1-dev \
		&& apt-get -y clean
		
#RUN cd /phantomjs && wget https://github.com/jonnnnyw/php-phantomjs/archive/refs/tags/v4.6.1.tar.gz && sudo tar xvjf v4.6.1.tar.gz
#RUN mv v4.6.1 /usr/local/share
#RUn ln -sf /usr/local/share/v4.6.1/bin/phantomjs /usr/local/bin

RUN cp /etc/lighttpd/conf-available/05-auth.conf /etc/lighttpd/conf-enabled/
RUN cp /etc/lighttpd/conf-available/15-fastcgi-php.conf /etc/lighttpd/conf-enabled/
RUN cp /etc/lighttpd/conf-available/10-fastcgi.conf /etc/lighttpd/conf-enabled/
RUN mkdir -p /var/run/lighttpd
RUN touch /var/run/lighttpd/php-fastcgi.socket
RUN chown -R www-data /var/run/lighttpd

ADD index.php /var/www/html
RUN chown -R www-data /var/www/

# Install Composer
RUN curl -s http://getcomposer.org/installer | php

COPY composer.json /var/www/composer.json
RUN cd /var/www && php composer.phar install

CMD ["/usr/sbin/lighttpd", "-f", "/etc/lighttpd/lighttpd.conf"]