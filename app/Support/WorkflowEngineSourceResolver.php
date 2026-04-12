<?php

declare(strict_types=1);

namespace Waterline\Support;

use Workflow\V2\Support\WaterlineEngineSource;

final class WorkflowEngineSourceResolver
{
    private const ENGINE_AUTO = 'auto';

    private const ENGINE_V1 = 'v1';

    private const ENGINE_V2 = 'v2';

    /**
     * @return array<string, mixed>
     */
    public static function status(string|null $configured = null): array
    {
        $configured ??= config('waterline.engine_source');
        $normalized = self::normalize($configured);

        if (class_exists(WaterlineEngineSource::class)) {
            return WaterlineEngineSource::status(is_string($configured) ? $configured : null);
        }

        $resolved = $normalized === self::ENGINE_V2
            ? self::ENGINE_V2
            : self::ENGINE_V1;

        return [
            'configured' => $normalized,
            'resolved' => $resolved,
            'uses_v2' => $resolved === self::ENGINE_V2,
            'v2_operator_surface_available' => $resolved === self::ENGINE_V2,
            'status' => $resolved === self::ENGINE_V2 ? 'v2_pinned' : 'v1_pinned',
            'severity' => 'ok',
            'message' => $resolved === self::ENGINE_V2
                ? 'Waterline is pinned to the v2 operator bridge.'
                : 'Waterline is pinned to the legacy v1 workflow tables.',
            'issues' => [],
            'required_tables' => [],
        ];
    }

    public static function resolve(string|null $configured = null): string
    {
        /** @var string $resolved */
        $resolved = self::status($configured)['resolved'];

        return $resolved;
    }

    public static function usesV2(string|null $configured = null): bool
    {
        return self::status($configured)['uses_v2'] === true;
    }

    private static function normalize(string|null $configured): string
    {
        $normalized = strtolower(trim((string) $configured));

        return $normalized === '' ? self::ENGINE_AUTO : $normalized;
    }
}
