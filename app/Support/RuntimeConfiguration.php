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
        'WORKFLOW_STORAGE_CONNECTION',
        'WORKFLOW_V2_TASK_DISPATCH_MODE',
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

        self::hydrateLaravelRuntimeConfig();
        self::hydrateWorkflowRuntimeConfig();
        self::hydrateWaterlineRuntimeConfig();
    }

    private static function hydrateWaterlineRuntimeConfig(): void
    {
        self::setStringConfigFromEnvironment('WATERLINE_DOMAIN', 'waterline.domain');
        self::setStringConfigFromEnvironment('WATERLINE_PATH', 'waterline.path');
        self::setStringConfigFromEnvironment('WATERLINE_ENGINE_SOURCE', 'waterline.engine_source');
        self::setStringConfigFromEnvironment('WATERLINE_NAMESPACE', 'waterline.namespace');
        self::setStringConfigFromEnvironment('WATERLINE_HEALTH_TASK_DISPATCH_MODE', 'waterline.health.task_dispatch_mode');
        self::setBooleanConfigFromEnvironment('WATERLINE_ALLOW_UNAUTHENTICATED', 'waterline.allow_unauthenticated');
    }

    private static function hydrateWorkflowRuntimeConfig(): void
    {
        self::setStringConfigFromEnvironmentAny(
            ['DW_STORAGE_CONNECTION', 'WORKFLOW_STORAGE_CONNECTION'],
            'workflows.storage.connection',
        );
        self::setStringConfigFromEnvironmentAny(
            ['DW_V2_TASK_DISPATCH_MODE', 'WORKFLOW_V2_TASK_DISPATCH_MODE'],
            'workflows.v2.task_dispatch_mode',
        );
    }

    private static function hydrateLaravelRuntimeConfig(): void
    {
        self::setStringConfigFromEnvironment('APP_ENV', 'app.env');
        self::setBooleanConfigFromEnvironment('APP_DEBUG', 'app.debug');
        self::setStringConfigFromEnvironment('APP_KEY', 'app.key');
        self::setStringConfigFromEnvironment('APP_URL', 'app.url');
        self::setStringConfigFromEnvironment('QUEUE_CONNECTION', 'queue.default');
        self::setStringConfigFromEnvironmentAny(['CACHE_STORE', 'CACHE_DRIVER'], 'cache.default');
        self::setStringConfigFromEnvironment('SESSION_DRIVER', 'session.driver');

        $databaseConnection = self::environmentValue('DB_CONNECTION');
        if ($databaseConnection !== null) {
            config()->set('database.default', $databaseConnection);
            self::hydrateDatabaseConnectionConfig($databaseConnection);

            return;
        }

        $configuredConnection = config('database.default');
        if (is_string($configuredConnection) && trim($configuredConnection) !== '') {
            self::hydrateDatabaseConnectionConfig(trim($configuredConnection));
        }
    }

    private static function hydrateDatabaseConnectionConfig(string $connection): void
    {
        $prefix = 'database.connections.'.$connection.'.';

        self::setStringConfigFromEnvironment('DB_HOST', $prefix.'host');
        self::setStringConfigFromEnvironment('DB_PORT', $prefix.'port');
        self::setStringConfigFromEnvironment('DB_DATABASE', $prefix.'database');
        self::setStringConfigFromEnvironment('DB_USERNAME', $prefix.'username');
        self::setStringConfigFromEnvironment('DB_SOCKET', $prefix.'unix_socket');
        self::setStringConfigFromEnvironment('DB_PASSWORD', $prefix.'password', allowEmpty: true);
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
            if ($value === false) {
                continue;
            }

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

    private static function setStringConfigFromEnvironment(
        string $environmentKey,
        string $configKey,
        bool $allowEmpty = false,
    ): void
    {
        self::setStringConfigFromEnvironmentAny([$environmentKey], $configKey, $allowEmpty);
    }

    /**
     * @param list<string> $environmentKeys
     */
    private static function setStringConfigFromEnvironmentAny(
        array $environmentKeys,
        string $configKey,
        bool $allowEmpty = false,
    ): void
    {
        $value = self::environmentValueAny($environmentKeys, $allowEmpty);

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

    private static function environmentValue(string $environmentKey, bool $allowEmpty = false): ?string
    {
        return self::environmentValueAny([$environmentKey], $allowEmpty);
    }

    /**
     * @param list<string> $environmentKeys
     */
    private static function environmentValueAny(array $environmentKeys, bool $allowEmpty = false): ?string
    {
        foreach ($environmentKeys as $environmentKey) {
            $value = self::environmentValueForKey($environmentKey, $allowEmpty);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private static function environmentValueForKey(string $environmentKey, bool $allowEmpty): ?string
    {
        $values = [
            $_SERVER[$environmentKey] ?? null,
            $_ENV[$environmentKey] ?? null,
            getenv($environmentKey),
        ];

        foreach ($values as $value) {
            if ($value === false) {
                continue;
            }

            if (! is_scalar($value)) {
                continue;
            }

            $value = $allowEmpty ? (string) $value : trim((string) $value);

            if ($allowEmpty || $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
