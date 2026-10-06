FROM php:8.4-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libcurl4-openssl-dev libzip-dev libonig-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev ca-certificates \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j2 pdo_mysql curl mbstring zip gd opcache \
 && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/tamasya
COPY . .
COPY deploy/php-production.ini /usr/local/etc/php/conf.d/zz-tamasya.ini
COPY deploy/fpm.conf /usr/local/etc/php-fpm.d/zz-tamasya.conf
COPY deploy/runtime-storage-init.sh /usr/local/bin/tamasya-storage-init
RUN chmod 0555 /usr/local/bin/tamasya-storage-init \
 && mkdir -p /var/lib/tamasya /var/www/tamasya/uploads \
 && chown www-data:www-data /var/lib/tamasya /var/www/tamasya/uploads
USER www-data
CMD ["php-fpm","-F"]
