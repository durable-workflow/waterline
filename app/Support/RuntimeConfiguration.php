<?php

declare(strict_types=1);

namespace Waterline\Support;

final class RuntimeConfiguration
{
    private const SERVE_ENVIRONMENT_KEYS = [
        'APP_ENV',
        'APP_DEBUG',
        'APP_KEY',
        'APP_URL',
        'CACHE_DRIVER',
        'CACHE_STORE',
        'DATABASE_URL',
        'DB_CONNECTION',
        'DB_DATABASE',
        'DB_HOST',
        'DB_PASSWORD',
        'DB_PORT',
        'DB_SOCKET',
        'DB_USERNAME',
        'DW_STORAGE_CONNECTION',
        'DW_V2_TASK_DISPATCH_MODE',
        'QUEUE_CONNECTION',
        'SESSION_DRIVER',
        'WATERLINE_ALLOW_UNAUTHENTICATED',
        'WATERLINE_DOMAIN',
        'WATERLINE_ENGINE_SOURCE',
        'WATERLINE_HEALTH_TASK_DISPATCH_MODE',
        'WATERLINE_NAMESPACE',
        'WATERLINE_PATH',
    ];

    public static function hydrate(): void
    {
        self::promoteProcessEnvironmentForServe();

        self::setStringConfigFromEnvironment('WATERLINE_DOMAIN', 'waterline.domain');
        self::setStringConfigFromEnvironment('WATERLINE_PATH', 'waterline.path');
        self::setStringConfigFromEnvironment('WATERLINE_ENGINE_SOURCE', 'waterline.engine_source');
        self::setStringConfigFromEnvironment('WATERLINE_NAMESPACE', 'waterline.namespace');
        self::setStringConfigFromEnvironment('WATERLINE_HEALTH_TASK_DISPATCH_MODE', 'waterline.health.task_dispatch_mode');
        self::setBooleanConfigFromEnvironment('WATERLINE_ALLOW_UNAUTHENTICATED', 'waterline.allow_unauthenticated');
    }

    private static function promoteProcessEnvironmentForServe(): void
    {
        foreach (self::SERVE_ENVIRONMENT_KEYS as $environmentKey) {
            self::promoteProcessEnvironmentValue($environmentKey);
        }
    }

    private static function promoteProcessEnvironmentValue(string $environmentKey): void
    {
        $value = self::processEnvironmentValue($environmentKey);

        if ($value === null) {
            return;
        }

        $_ENV[$environmentKey] = $value;
        $_SERVER[$environmentKey] = $value;
    }

    private static function processEnvironmentValue(string $environmentKey): ?string
    {
        $values = [
            getenv($environmentKey),
            $_SERVER[$environmentKey] ?? null,
            $_ENV[$environmentKey] ?? null,
        ];

        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private static function setStringConfigFromEnvironment(string $environmentKey, string $configKey): void
    {
        $value = self::environmentValue($environmentKey);

        if ($value !== null) {
            config()->set($configKey, $value);
        }
    }

    private static function setBooleanConfigFromEnvironment(string $environmentKey, string $configKey): void
    {
        $value = self::environmentValue($environmentKey);

        if ($value === null) {
            return;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($parsed !== null) {
            config()->set($configKey, $parsed);
        }
    }

    private static function environmentValue(string $environmentKey): ?string
    {
        $values = [
            $_SERVER[$environmentKey] ?? null,
            $_ENV[$environmentKey] ?? null,
            getenv($environmentKey),
        ];

        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
