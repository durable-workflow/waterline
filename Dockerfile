FROM composer:2.8 AS vendor

WORKDIR /build/standalone
COPY standalone/composer.json standalone/composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --optimize-autoloader

FROM php:8.3-cli-alpine

ARG WATERLINE_VERSION=0.0.0-dev
ARG SOURCE_COMMIT=0000000000000000000000000000000000000000

LABEL org.opencontainers.image.revision="$SOURCE_COMMIT" \
    dev.durable-workflow.release.tag="$WATERLINE_VERSION"

RUN apk add --no-cache libpq libzip oniguruma sqlite-libs \
    && apk add --no-cache --virtual .build-deps libpq-dev libzip-dev oniguruma-dev sqlite-dev \
    && docker-php-ext-install mbstring pdo_mysql pdo_pgsql pdo_sqlite zip \
    && apk del .build-deps

WORKDIR /app
COPY standalone /app
COPY --from=vendor /build/standalone/vendor /app/vendor
COPY app /app/waterline/app
COPY config /app/waterline/config
COPY database /app/waterline/database
COPY public /app/waterline/public
COPY resources /app/waterline/resources
COPY routes /app/waterline/routes
COPY public /app/public/vendor/waterline

RUN chmod +x /app/artisan /app/entrypoint.sh \
    && mkdir -p /data /app/bootstrap/cache /app/storage/framework/cache/data /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs \
    && chown -R www-data:www-data /app/bootstrap/cache /app/storage /data

ENV APP_ENV=production \
    APP_DEBUG=false \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/data/waterline.sqlite \
    PORT=8080 \
    WATERLINE_BACKEND=service \
    WATERLINE_PATH=waterline

VOLUME ["/data"]
EXPOSE 8080
USER www-data

HEALTHCHECK --interval=10s --timeout=3s --start-period=30s --retries=5 \
    CMD php -r '$c=@file_get_contents("http://127.0.0.1:".(getenv("PORT")?:"8080")."/up"); exit($c===false?1:0);'

ENTRYPOINT ["/app/entrypoint.sh"]
