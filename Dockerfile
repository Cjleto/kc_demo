FROM php:8.2-apache

RUN apt-get update && apt-get install -y unzip curl libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
    && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && rm composer-setup.php

COPY xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini

WORKDIR /var/www/html

COPY app/composer.json composer.json
RUN composer install --no-dev --optimize-autoloader

COPY app/ .
