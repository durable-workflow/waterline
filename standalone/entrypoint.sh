#!/bin/sh
set -eu

if [ -z "${APP_KEY:-}" ]; then
    APP_KEY="$(php artisan key:generate --show --no-ansi)"
    export APP_KEY
fi

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    database="${DB_DATABASE:-/data/waterline.sqlite}"
    mkdir -p "$(dirname "$database")"
    touch "$database"
fi

php artisan migrate --force --no-interaction

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}" --no-reload
