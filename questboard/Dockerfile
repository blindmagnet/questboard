# syntax=docker/dockerfile:1

# ---- 1. PHP dependencies (no dev) ------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction \
        --ignore-platform-req=ext-redis
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts

# ---- 2. Runtime -------------------------------------------------------------
FROM php:8.3-fpm-alpine AS runtime

RUN apk add --no-cache icu-libs libzip fcgi \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev linux-headers \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql intl opcache pcntl zip bcmath \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-questboard.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-www.conf

WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /app /var/www/html

RUN php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x docker/entrypoint.sh

USER www-data

HEALTHCHECK --interval=15s --timeout=3s --retries=5 \
    CMD SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 || exit 1

ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["php-fpm"]
