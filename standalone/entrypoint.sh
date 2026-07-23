#!/bin/sh
set -eu

log() {
    printf 'waterline-service: %s\n' "$*" >&2
}

fail() {
    log "startup failed: $*"
    exit 1
}

app_root="$(CDPATH= cd "$(dirname "$0")" && pwd)"
port="${PORT:-8080}"
migration_timeout="${WATERLINE_MIGRATION_TIMEOUT_SECONDS:-20}"

case "$port" in
    ''|*[!0-9]*) fail "PORT must be an integer between 1 and 65535" ;;
esac
if [ "$port" -lt 1 ] || [ "$port" -gt 65535 ]; then
    fail "PORT must be an integer between 1 and 65535"
fi

case "$migration_timeout" in
    ''|*[!0-9]*) fail "WATERLINE_MIGRATION_TIMEOUT_SECONDS must be an integer between 1 and 60" ;;
esac
if [ "$migration_timeout" -lt 1 ] || [ "$migration_timeout" -gt 60 ]; then
    fail "WATERLINE_MIGRATION_TIMEOUT_SECONDS must be an integer between 1 and 60"
fi

if [ "${WATERLINE_BACKEND:-service}" != "service" ]; then
    fail "the packaged image requires WATERLINE_BACKEND=service"
fi
if [ -z "${WATERLINE_SERVER_ENDPOINT:-}" ]; then
    fail "WATERLINE_SERVER_ENDPOINT is required"
fi
case "${WATERLINE_ACCESS_MODE:-read_only}" in
    read_only|operator) ;;
    *) fail "WATERLINE_ACCESS_MODE must be read_only or operator" ;;
esac

if [ -z "${APP_KEY:-}" ]; then
    APP_KEY="$(php -r 'echo "base64:", base64_encode(random_bytes(32));')"
    export APP_KEY
fi

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    database="${DB_DATABASE:-/data/waterline.sqlite}"
    if [ "$database" = ':memory:' ]; then
        fail "DB_DATABASE=:memory: is process-local and is not supported by the packaged service;" \
            "use a file-backed SQLite database such as /data/waterline.sqlite or configure MySQL or PostgreSQL"
    fi

    database_directory="$(dirname "$database")"
    mkdir -p "$database_directory" || fail "could not create SQLite directory [$database_directory]"
    touch "$database" || fail "could not create SQLite database [$database]"

    if [ "$(id -u)" -eq 0 ]; then
        chown www-data:www-data "$database_directory" \
            || fail "could not grant www-data access to SQLite directory [$database_directory]"
        for sqlite_file in "$database" "$database-wal" "$database-shm"; do
            if [ -e "$sqlite_file" ]; then
                chown www-data:www-data "$sqlite_file" \
                    || fail "could not grant www-data access to SQLite file [$sqlite_file]"
            fi
        done
    fi
fi

if [ "$(id -u)" -eq 0 ]; then
    chown -R www-data:www-data "$app_root/bootstrap/cache" "$app_root/storage" \
        || fail "could not grant www-data access to Laravel runtime directories"
fi

log "applying locked database migrations (timeout ${migration_timeout}s)"
cd "$app_root"
set +e
if [ "$(id -u)" -eq 0 ]; then
    timeout -s TERM -k 5 "$migration_timeout" \
        su-exec www-data php artisan migrate --force --no-interaction
else
    timeout -s TERM -k 5 "$migration_timeout" \
        php artisan migrate --force --no-interaction
fi
migration_status=$?
set -e
if [ "$migration_status" -ne 0 ]; then
    fail "database migrations exited with status $migration_status"
fi

router="$app_root/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"
[ -f "$router" ] || fail "packaged Laravel server router is missing"

log "migrations complete; binding HTTP service on 0.0.0.0:$port"
cd "$app_root/public"

if [ "$(id -u)" -eq 0 ]; then
    exec su-exec www-data php -d variables_order=EGPCS -S "0.0.0.0:$port" "$router"
fi

exec php -d variables_order=EGPCS -S "0.0.0.0:$port" "$router"
