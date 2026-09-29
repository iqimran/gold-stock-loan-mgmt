# syntax=docker/dockerfile:1.7
#
# Targets (mirrors the Inventory POS Dockerfile):
#   development  PHP-FPM + Composer + test extensions; code is bind-mounted (docker-compose.yml)
# Production targets are added with the production Docker configuration (docs/tasks/022).
#
ARG PHP_VERSION=8.3

# ── base: PHP-FPM with the extensions the application needs ───────────────────────────────
FROM php:${PHP_VERSION}-fpm-alpine AS base

# bcmath: all money arithmetic; pdo_pgsql: PostgreSQL; intl/zip: framework/Composer; pcntl: queue signals;
# opcache: performance; fcgi: php-fpm health check (cgi-fcgi).
RUN apk add --no-cache icu-libs libzip libpq fcgi \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev libpq-dev linux-headers \
    && docker-php-ext-install -j"$(nproc)" bcmath intl opcache pcntl pdo_pgsql zip \
    && apk del .build-deps

WORKDIR /var/www/html

COPY docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-www.conf
COPY --chmod=755 docker/php/entrypoint.sh /usr/local/bin/app-entrypoint

ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]

# ── development: source is bind-mounted; runs as the host user so files stay editable ────
FROM base AS development

ARG UID=1000
ARG GID=1000

# gd + pdo_sqlite: the test suite (fake images, in-memory SQLite). git/unzip: Composer.
RUN apk add --no-cache git unzip shadow freetype libpng libjpeg-turbo libwebp sqlite-libs \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS freetype-dev libpng-dev libjpeg-turbo-dev libwebp-dev sqlite-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd pdo_sqlite \
    && apk del .build-deps \
    && groupmod -o -g "${GID}" www-data \
    && usermod -o -u "${UID}" -g www-data www-data

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/conf.d/development.ini /usr/local/etc/php/conf.d/zz-development.ini

USER www-data
