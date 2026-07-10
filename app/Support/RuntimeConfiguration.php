<?php

declare(strict_types=1);

namespace Waterline\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

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
        'DW_WATERLINE_DB_CONNECTION',
        'DW_WATERLINE_DB_DATABASE',
        'DW_WATERLINE_DB_DRIVER',
        'DW_WATERLINE_DB_HOST',
        'DW_WATERLINE_DB_PASSWORD',
        'DW_WATERLINE_DB_PORT',
        'DW_WATERLINE_DB_SOCKET',
        'DW_WATERLINE_DB_USERNAME',
        'DW_WV_WATERLINE_DB_CONNECTION',
        'DW_WV_WATERLINE_DB_DATABASE',
        'DW_WV_WATERLINE_DB_DRIVER',
        'DW_WV_WATERLINE_DB_HOST',
        'DW_WV_WATERLINE_DB_PASSWORD',
        'DW_WV_WATERLINE_DB_PORT',
        'DW_WV_WATERLINE_DB_SOCKET',
        'DW_WV_WATERLINE_DB_USERNAME',
        'DW_STORAGE_CONNECTION',
        'DW_V2_TASK_DISPATCH_MODE',
        'WATERLINE_WORKFLOW_DB_CONNECTION',
        'WATERLINE_WORKFLOW_DB_DATABASE',
        'WATERLINE_WORKFLOW_DB_DRIVER',
        'WATERLINE_WORKFLOW_DB_HOST',
        'WATERLINE_WORKFLOW_DB_PASSWORD',
        'WATERLINE_WORKFLOW_DB_PORT',
        'WATERLINE_WORKFLOW_DB_SOCKET',
        'WATERLINE_WORKFLOW_DB_USERNAME',
        'WATERLINE_WORKFLOW_STORAGE_CONNECTION',
        'WORKFLOW_DB_CONNECTION',
        'WORKFLOW_DB_DATABASE',
        'WORKFLOW_DB_DRIVER',
        'WORKFLOW_DB_HOST',
        'WORKFLOW_DB_PASSWORD',
        'WORKFLOW_DB_PORT',
        'WORKFLOW_DB_SOCKET',
        'WORKFLOW_DB_USERNAME',
        'WORKFLOW_STORAGE_CONNECTION',
        'WORKFLOW_V2_TASK_DISPATCH_MODE',
        'QUEUE_CONNECTION',
        'SESSION_DRIVER',
        'WATERLINE_ALLOW_UNAUTHENTICATED',
        'WATERLINE_DOMAIN',
        'WATERLINE_ENGINE_SOURCE',
        'WATERLINE_HEALTH_TASK_DISPATCH_MODE',
        'WATERLINE_HYBRID_MIGRATION_VIEW',
        'WATERLINE_NAMESPACE',
        'WATERLINE_PATH',
    ];

    public static function hydrate(): void
    {
        self::allowLaravelServeEnvironmentPassthrough();
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
        self::setBooleanConfigFromEnvironment('WATERLINE_HYBRID_MIGRATION_VIEW', 'waterline.hybrid_migration_view');
        self::setBooleanConfigFromEnvironment('WATERLINE_ALLOW_UNAUTHENTICATED', 'waterline.allow_unauthenticated');
    }

    private static function hydrateWorkflowRuntimeConfig(): void
    {
        self::setStringConfigFromEnvironmentAny(
            ['DW_STORAGE_CONNECTION', 'WORKFLOW_STORAGE_CONNECTION', 'WATERLINE_WORKFLOW_STORAGE_CONNECTION'],
            'workflows.storage.connection',
        );
        self::hydrateWorkflowStorageDatabaseConnection();
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
        $before = config('database.connections.'.$connection);
        $prefix = 'database.connections.'.$connection.'.';

        self::setStringConfigFromEnvironment('DB_HOST', $prefix.'host');
        self::setStringConfigFromEnvironment('DB_PORT', $prefix.'port');
        self::setStringConfigFromEnvironment('DB_DATABASE', $prefix.'database');
        self::setStringConfigFromEnvironment('DB_USERNAME', $prefix.'username');
        self::setStringConfigFromEnvironment('DB_SOCKET', $prefix.'unix_socket');
        self::setStringConfigFromEnvironment('DB_PASSWORD', $prefix.'password', allowEmpty: true);

        if (config('database.connections.'.$connection) !== $before) {
            self::purgeDatabaseConnection($connection);
        }
    }

    private static function hydrateWorkflowStorageDatabaseConnection(): void
    {
        if (! self::hasWorkflowDatabaseEnvironment()) {
            return;
        }

        $connection = self::environmentValueAny(['WATERLINE_WORKFLOW_STORAGE_CONNECTION', 'WORKFLOW_STORAGE_CONNECTION', 'DW_STORAGE_CONNECTION'])
            ?? self::stringConfig('workflows.storage.connection')
            ?? 'waterline_workflow';
        $driver = self::environmentValueAny([
            'WATERLINE_WORKFLOW_DB_DRIVER',
            'WATERLINE_WORKFLOW_DB_CONNECTION',
            'WORKFLOW_DB_DRIVER',
            'WORKFLOW_DB_CONNECTION',
            'DW_WV_WATERLINE_DB_DRIVER',
            'DW_WV_WATERLINE_DB_CONNECTION',
            'DW_WATERLINE_DB_DRIVER',
            'DW_WATERLINE_DB_CONNECTION',
        ]) ?? 'mysql';

        $connection = trim($connection);
        $driver = strtolower(trim($driver));
        if ($connection === '' || $driver === '') {
            return;
        }

        $before = config('database.connections.'.$connection);
        config()->set('database.connections.'.$connection, self::workflowStorageConnectionConfig($driver));
        config()->set('workflows.storage.connection', $connection);

        if (config('database.connections.'.$connection) !== $before) {
            self::purgeDatabaseConnection($connection);
        }
    }

    private static function hasWorkflowDatabaseEnvironment(): bool
    {
        foreach ([
            'WATERLINE_WORKFLOW_DB_CONNECTION',
            'WATERLINE_WORKFLOW_DB_DATABASE',
            'WATERLINE_WORKFLOW_DB_DRIVER',
            'WATERLINE_WORKFLOW_DB_HOST',
            'WATERLINE_WORKFLOW_DB_PASSWORD',
            'WATERLINE_WORKFLOW_DB_PORT',
            'WATERLINE_WORKFLOW_DB_SOCKET',
            'WATERLINE_WORKFLOW_DB_USERNAME',
            'WORKFLOW_DB_CONNECTION',
            'WORKFLOW_DB_DATABASE',
            'WORKFLOW_DB_DRIVER',
            'WORKFLOW_DB_HOST',
            'WORKFLOW_DB_PASSWORD',
            'WORKFLOW_DB_PORT',
            'WORKFLOW_DB_SOCKET',
            'WORKFLOW_DB_USERNAME',
            'DW_WV_WATERLINE_DB_CONNECTION',
            'DW_WV_WATERLINE_DB_DATABASE',
            'DW_WV_WATERLINE_DB_DRIVER',
            'DW_WV_WATERLINE_DB_HOST',
            'DW_WV_WATERLINE_DB_PASSWORD',
            'DW_WV_WATERLINE_DB_PORT',
            'DW_WV_WATERLINE_DB_SOCKET',
            'DW_WV_WATERLINE_DB_USERNAME',
            'DW_WATERLINE_DB_CONNECTION',
            'DW_WATERLINE_DB_DATABASE',
            'DW_WATERLINE_DB_DRIVER',
            'DW_WATERLINE_DB_HOST',
            'DW_WATERLINE_DB_PASSWORD',
            'DW_WATERLINE_DB_PORT',
            'DW_WATERLINE_DB_SOCKET',
            'DW_WATERLINE_DB_USERNAME',
        ] as $key) {
            if (self::environmentValue($key, allowEmpty: $key === 'WATERLINE_WORKFLOW_DB_PASSWORD'
                || $key === 'WORKFLOW_DB_PASSWORD'
                || $key === 'DW_WV_WATERLINE_DB_PASSWORD'
                || $key === 'DW_WATERLINE_DB_PASSWORD') !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private static function workflowStorageConnectionConfig(string $driver): array
    {
        $base = config('database.connections.'.$driver);
        $base = is_array($base) ? $base : [];
        $config = array_merge($base, ['driver' => $driver]);

        if ($driver === 'sqlite') {
            $config['database'] = self::environmentValueAny([
                'WATERLINE_WORKFLOW_DB_DATABASE',
                'WORKFLOW_DB_DATABASE',
                'DW_WV_WATERLINE_DB_DATABASE',
                'DW_WATERLINE_DB_DATABASE',
            ]) ?? ($config['database'] ?? database_path('database.sqlite'));
            $config['prefix'] = $config['prefix'] ?? '';
            $config['foreign_key_constraints'] = $config['foreign_key_constraints'] ?? false;

            return $config;
        }

        self::setConnectionConfigValue($config, 'host', [
            'WATERLINE_WORKFLOW_DB_HOST',
            'WORKFLOW_DB_HOST',
            'DW_WV_WATERLINE_DB_HOST',
            'DW_WATERLINE_DB_HOST',
        ]);
        self::setConnectionConfigValue($config, 'port', [
            'WATERLINE_WORKFLOW_DB_PORT',
            'WORKFLOW_DB_PORT',
            'DW_WV_WATERLINE_DB_PORT',
            'DW_WATERLINE_DB_PORT',
        ]);
        self::setConnectionConfigValue($config, 'database', [
            'WATERLINE_WORKFLOW_DB_DATABASE',
            'WORKFLOW_DB_DATABASE',
            'DW_WV_WATERLINE_DB_DATABASE',
            'DW_WATERLINE_DB_DATABASE',
        ]);
        self::setConnectionConfigValue($config, 'username', [
            'WATERLINE_WORKFLOW_DB_USERNAME',
            'WORKFLOW_DB_USERNAME',
            'DW_WV_WATERLINE_DB_USERNAME',
            'DW_WATERLINE_DB_USERNAME',
        ]);
        self::setConnectionConfigValue($config, 'unix_socket', [
            'WATERLINE_WORKFLOW_DB_SOCKET',
            'WORKFLOW_DB_SOCKET',
            'DW_WV_WATERLINE_DB_SOCKET',
            'DW_WATERLINE_DB_SOCKET',
        ]);
        self::setConnectionConfigValue($config, 'password', [
            'WATERLINE_WORKFLOW_DB_PASSWORD',
            'WORKFLOW_DB_PASSWORD',
            'DW_WV_WATERLINE_DB_PASSWORD',
            'DW_WATERLINE_DB_PASSWORD',
        ], allowEmpty: true);

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $environmentKeys
     */
    private static function setConnectionConfigValue(
        array &$config,
        string $key,
        array $environmentKeys,
        bool $allowEmpty = false,
    ): void {
        $value = self::environmentValueAny($environmentKeys, $allowEmpty);

        if ($value !== null) {
            $config[$key] = $value;
        }
    }

    private static function stringConfig(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function purgeDatabaseConnection(string $connection): void
    {
        try {
            DB::purge($connection);
        } catch (Throwable) {
            // The database manager may not be bootstrapped during early package registration.
        }
    }

    private static function allowLaravelServeEnvironmentPassthrough(): void
    {
        $serveCommand = 'Illuminate\\Foundation\\Console\\ServeCommand';

        if (! class_exists($serveCommand) || ! property_exists($serveCommand, 'passthroughVariables')) {
            return;
        }

        try {
            $passthrough = $serveCommand::$passthroughVariables;

            if (! is_array($passthrough)) {
                return;
            }

            $serveCommand::$passthroughVariables = array_values(array_unique(array_merge(
                $passthrough,
                self::SERVE_ENVIRONMENT_KEYS,
            )));
        } catch (Throwable) {
            // Older framework versions may not expose this extension point.
        }
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

            $value = $allowEmpty ? (string) $value : trim((string) $value);

            if ($allowEmpty || $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
