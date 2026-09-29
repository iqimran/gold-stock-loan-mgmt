#!/bin/sh
# Prepares Laravel before starting the container's main process (php-fpm, schedule:work, queue:work).
set -e

cd /var/www/html

# A fresh storage volume starts empty: create the directories Laravel writes to.
mkdir -p storage/app/public storage/app/private storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs bootstrap/cache

# Development: install dependencies on first start (vendor/ lives in the bind-mounted source).
if [ ! -f vendor/autoload.php ] && command -v composer >/dev/null 2>&1; then
    composer install --no-interaction --prefer-dist
fi

if [ "${APP_ENV}" = "production" ]; then
    # Only the app container migrates, and only when asked (see docs/deployment.md).
    if [ "${RUN_MIGRATIONS:-false}" = "true" ] && [ "$1" = "php-fpm" ]; then
        php artisan migrate --force --no-interaction
    fi

    # Cache config, routes, events and views from the runtime environment.
    php artisan optimize --no-interaction
fi

exec "$@"
