#!/usr/bin/env sh
set -eu

mkdir -p \
    storage/app/private \
    storage/app/public \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

ln -sf /app/storage/app/public public/storage

if [ "$(id -u)" = "0" ]; then
    chown -R www-data:www-data storage bootstrap/cache
fi

php artisan package:discover --ansi
php artisan optimize --ansi

exec docker-php-entrypoint "$@"
