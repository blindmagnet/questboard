#!/bin/sh
set -e

# Cache configuration at start-up (not build time) so the image stays environment-agnostic.
if [ "${APP_ENV:-production}" != "local" ]; then
    php artisan config:cache --quiet
    php artisan route:cache --quiet
    php artisan event:cache --quiet
fi

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

if [ "${RUN_SEEDER:-false}" = "true" ]; then
    php artisan db:seed --force
fi

exec "$@"
