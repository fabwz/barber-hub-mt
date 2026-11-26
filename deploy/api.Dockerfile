# API image of BarberHub (Laravel 13, PHP 8.4, PHP-FPM). One image serves four services of docker-compose.yml:
# app (php-fpm), worker-default, worker-realtime and reverb; only the command changes. Build context: the root of the monorepo.

# ---- Base: PHP with the extensions the application needs --------------------------------------------------------------------------
FROM php:8.4-fpm-alpine AS base

COPY --from=ghcr.io/mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

# pdo_pgsql: PostgreSQL. intl: formatting and collation. pcntl: signals of queue:work and reverb:start. opcache: speed.
RUN install-php-extensions pdo_pgsql intl pcntl opcache \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY deploy/php/barberhub.ini /usr/local/etc/php/conf.d/zz-barberhub.ini
COPY deploy/php/php-fpm.conf /usr/local/etc/php-fpm.d/zz-barberhub.conf

# ---- Vendor: the production dependencies, without the development ones ------------------------------------------------------------
FROM base AS vendor

RUN install-php-extensions zip @composer
WORKDIR /var/www/html

# The manifest first, so this layer is reused while composer.lock does not change.
COPY barberhub-api/composer.json barberhub-api/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY barberhub-api/ ./
# Runs the post-autoload-dump script (package:discover) now that the code is there.
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-interaction

# ---- Runtime ------------------------------------------------------------------------------------------------------------------------
FROM base AS runtime

WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /var/www/html ./

# storage/app/public is the mount point of the volume of the uploaded images; it must exist, owned by www-data, before the first mount.
RUN mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && rm -f bootstrap/cache/*.php

COPY deploy/api-entrypoint.sh /usr/local/bin/api-entrypoint.sh
RUN chmod +x /usr/local/bin/api-entrypoint.sh

# Nothing runs as root: php-fpm, the workers and Reverb all run as www-data.
USER www-data
EXPOSE 9000

ENTRYPOINT ["api-entrypoint.sh"]
CMD ["php-fpm"]
