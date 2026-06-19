<?php

declare(strict_types=1);

namespace Waterline\Support;

final class RuntimeConfiguration
{
    public static function hydrate(): void
    {
        self::setStringConfigFromEnvironment('WATERLINE_DOMAIN', 'waterline.domain');
        self::setStringConfigFromEnvironment('WATERLINE_PATH', 'waterline.path');
        self::setStringConfigFromEnvironment('WATERLINE_ENGINE_SOURCE', 'waterline.engine_source');
        self::setStringConfigFromEnvironment('WATERLINE_NAMESPACE', 'waterline.namespace');
        self::setStringConfigFromEnvironment('WATERLINE_HEALTH_TASK_DISPATCH_MODE', 'waterline.health.task_dispatch_mode');
        self::setBooleanConfigFromEnvironment('WATERLINE_ALLOW_UNAUTHENTICATED', 'waterline.allow_unauthenticated');
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
