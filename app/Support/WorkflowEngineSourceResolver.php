<?php

declare(strict_types=1);

namespace Waterline\Support;

use Workflow\V2\Support\WaterlineEngineSource;

final class WorkflowEngineSourceResolver
{
    private const ENGINE_AUTO = 'auto';

    private const ENGINE_V1 = 'v1';

    private const ENGINE_V2 = 'v2';

    public static function resolve(string|null $configured = null): string
    {
        $configured ??= config('waterline.engine_source');

        if (class_exists(WaterlineEngineSource::class)) {
            return WaterlineEngineSource::resolve(is_string($configured) ? $configured : null);
        }

        return self::normalize($configured) === self::ENGINE_V2
            ? self::ENGINE_V2
            : self::ENGINE_V1;
    }

    public static function usesV2(string|null $configured = null): bool
    {
        return self::resolve($configured) === self::ENGINE_V2;
    }

    private static function normalize(string|null $configured): string
    {
        $normalized = strtolower(trim((string) $configured));

        return $normalized === '' ? self::ENGINE_AUTO : $normalized;
    }
}
