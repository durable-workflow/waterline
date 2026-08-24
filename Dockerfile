FROM composer:2.8 AS vendor

WORKDIR /build/standalone
COPY standalone/composer.json standalone/composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --optimize-autoloader
COPY app /build/standalone/waterline/app
RUN composer dump-autoload \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --optimize \
    && php -r '\
        require "vendor/autoload.php"; \
        $manifest = json_decode(file_get_contents("composer.json"), true, flags: JSON_THROW_ON_ERROR); \
        $expectedSdk = $manifest["require"]["durable-workflow/sdk"] ?? null; \
        $actualSdk = Composer\InstalledVersions::getPrettyVersion("durable-workflow/sdk"); \
        $controller = new ReflectionClass(Waterline\Http\Controllers\Remote\RemoteWorkflowsController::class); \
        $presenter = new ReflectionClass(Waterline\Support\WorkflowStreamPresenter::class); \
        $sourceRoot = realpath("waterline/app").DIRECTORY_SEPARATOR; \
        foreach ([$controller, $presenter] as $class) { \
            $file = $class->getFileName(); \
            if (!is_string($file) || !str_starts_with(realpath($file), $sourceRoot)) { \
                fwrite(STDERR, "Waterline runtime class escaped the candidate source tree.\n"); \
                exit(1); \
            } \
        } \
        if ($actualSdk !== $expectedSdk || !method_exists(DurableWorkflow\Client::class, "listWorkflowStreams")) { \
            fwrite(STDERR, "The packaged SDK does not satisfy the candidate Workflow Streams contract.\n"); \
            exit(1); \
        }'

FROM php:8.3-cli-alpine

RUN apk add --no-cache libpq libzip oniguruma sqlite-libs su-exec \
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
STOPSIGNAL SIGTERM

HEALTHCHECK --interval=5s --timeout=2s --start-period=5s --retries=5 \
    CMD php -r '$c=@file_get_contents("http://127.0.0.1:".(getenv("PORT")?:"8080")."/up"); exit($c===false?1:0);'

ARG WATERLINE_VERSION=0.0.0-dev
ARG SOURCE_COMMIT=0000000000000000000000000000000000000000

LABEL org.opencontainers.image.revision="$SOURCE_COMMIT" \
    dev.durable-workflow.release.tag="$WATERLINE_VERSION"

ENTRYPOINT ["/app/entrypoint.sh"]
