# syntax=docker/dockerfile:1.7
#
# Targets (mirrors the Inventory POS Dockerfile):
#   development  PHP-FPM + Composer + test extensions; code is bind-mounted (docker-compose.yml)
#   production   PHP-FPM with the application, production dependencies and built assets baked in;
#                also runs the scheduler and queue worker (docker-compose.prod.yml)
#   web          Nginx serving the built public/ directory and proxying PHP to "app"
# See docs/deployment.md.
#
ARG PHP_VERSION=8.3

# ── base: PHP-FPM with the extensions the application needs ───────────────────────────────
FROM php:${PHP_VERSION}-fpm-alpine AS base

# bcmath: all money arithmetic; pdo_pgsql: PostgreSQL; intl/zip: framework/Composer; pcntl: queue signals;
# gd: Excel exports (PhpSpreadsheet) and uploaded images; opcache: performance; fcgi: php-fpm health check.
RUN apk add --no-cache icu-libs libzip libpq fcgi freetype libpng libjpeg-turbo libwebp \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev libpq-dev linux-headers \
        freetype-dev libpng-dev libjpeg-turbo-dev libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" bcmath gd intl opcache pcntl pdo_pgsql zip \
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

# pdo_sqlite: the test suite (in-memory SQLite). git/unzip: Composer.
RUN apk add --no-cache git unzip shadow sqlite-libs \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS sqlite-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_sqlite \
    && apk del .build-deps \
    && groupmod -o -g "${GID}" www-data \
    && usermod -o -u "${UID}" -g www-data www-data

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/conf.d/development.ini /usr/local/etc/php/conf.d/zz-development.ini

USER www-data

# ── vendor: production Composer dependencies, checked against the real PHP extensions ─────
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Downloads are cached between builds (BuildKit), which also avoids GitHub download rate limits on rebuilds.
ENV COMPOSER_CACHE_DIR=/tmp/composer-cache
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache \
    composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY . .
# Runs package:discover, so bootstrap/cache/packages.php lists production packages only.
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-interaction

# ── assets: Vite production build ─────────────────────────────────────────────────────────
FROM node:22-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm npm ci --no-audit --no-fund

COPY vite.config.js tsconfig.json components.json ./
COPY resources ./resources
COPY public ./public
# The route() helper is imported from the Ziggy Composer package (tsconfig "ziggy-js" path).
COPY --from=vendor /var/www/html/vendor/tightenco/ziggy ./vendor/tightenco/ziggy
RUN npm run build

# ── production: immutable application image (php-fpm, scheduler, queue worker) ──────────
FROM base AS production

COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html
COPY --from=assets --chown=www-data:www-data /app/public/build /var/www/html/public/build

# storage/ is a volume in production (uploads, logs); it inherits this ownership when first created.
RUN mkdir -p storage/app/public storage/app/private storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

# ── web: Nginx with the public/ directory (static files, built assets) ───────────────────
FROM nginx:1.27-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=production /var/www/html/public /var/www/html/public
