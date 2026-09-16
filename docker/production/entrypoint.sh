#!/bin/sh
set -eu
php -r '
if (getenv("APP_ENV") !== "production" || getenv("APP_DEBUG") !== "false"
    || !str_starts_with(getenv("APP_URL") ?: "", "https://")
    || getenv("SESSION_SECURE_COOKIE") !== "true"
    || !str_starts_with(getenv("APP_KEY") ?: "", "base64:")
    || strlen(base64_decode(substr(getenv("APP_KEY"), 7), true) ?: "") !== 32) {
    fwrite(STDERR, "Production requires HTTPS, secure cookies, debug disabled, and a stable 32-byte APP_KEY.\n"); exit(1);
}'
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views
# These caches belong to this container and its runtime environment, not the image.
php artisan config:cache
php artisan route:cache
php artisan view:cache
exec "$@"
