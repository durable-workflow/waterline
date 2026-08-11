<?php

declare(strict_types=1);

namespace Waterline\Support;

final class BackendConfiguration
{
    public const EMBEDDED = 'embedded';

    public const SERVICE = 'service';

    public static function mode(): string
    {
        $mode = strtolower(trim((string) config('waterline.backend', self::EMBEDDED)));

        return $mode === self::SERVICE ? self::SERVICE : self::EMBEDDED;
    }

    public static function serviceMode(): bool
    {
        return self::mode() === self::SERVICE;
    }

    public static function accessMode(): string
    {
        $mode = strtolower(trim((string) config('waterline.service.access_mode', 'read_only')));

        return $mode === 'operator' ? 'operator' : 'read_only';
    }

    public static function namespace(): string
    {
        $namespace = trim((string) config('waterline.service.namespace', config('waterline.namespace', 'default')));

        return $namespace !== '' ? $namespace : 'default';
    }

    /** @return array<string, mixed> */
    public static function payload(?array $capabilities = null): array
    {
        $service = self::serviceMode();
        $accessMode = self::accessMode();

        return [
            'mode' => self::mode(),
            'label' => $service ? 'Standalone service' : 'Embedded Laravel',
            'transport' => $service ? 'durable-workflow/sdk' : 'workflow-package',
            'namespace' => $service ? self::namespace() : OperatorScope::namespace(),
            'access_mode' => $service ? $accessMode : 'host_authorized',
            'read_only' => $service && $accessMode === 'read_only',
            'authentication' => $service
                ? (self::serviceTokenConfigured() ? 'configured' : 'missing')
                : 'host_application',
            'persistence' => $service ? 'waterline_owned' : 'host_application',
            'capabilities' => $capabilities ?? self::declaredCapabilities(),
        ];
    }

    /** @return array<string, bool> */
    public static function declaredCapabilities(): array
    {
        $service = self::serviceMode();
        $operator = ! $service || self::accessMode() === 'operator';

        return [
            'workflows' => true,
            'history' => true,
            'health' => true,
            'metrics' => true,
            'capacity_evidence' => true,
            'dashboard_summary' => true,
            'workers' => true,
            'task_queues' => true,
            'schedules' => true,
            'services' => ! $service,
            'query' => true,
            'signal' => $operator,
            'update' => $operator,
            'cancel' => $operator,
            'terminate' => $operator,
            'repair' => $operator,
            'archive' => $operator,
        ];
    }

    private static function serviceTokenConfigured(): bool
    {
        return trim((string) config('waterline.service.token', '')) !== '';
    }
}
