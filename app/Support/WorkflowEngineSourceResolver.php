<?php

declare(strict_types=1);

namespace Waterline\Support;

use Workflow\V2\Support\ReadinessContract;
use Workflow\V2\Support\WaterlineEngineSource;

final class WorkflowEngineSourceResolver
{
    private const ENGINE_AUTO = 'auto';

    private const ENGINE_V1 = 'v1';

    private const ENGINE_V2 = 'v2';

    // The operator bridge can still serve health, running-list, and selected-run
    // detail from durable instance/run/history state when these projections lag
    // or are absent in a package-installed Waterline host.
    private const OPTIONAL_V2_CONFIG_KEYS = [
        'activity_attempt_model',
        'activity_execution_model',
        'command_model',
        'failure_model',
        'link_model',
        'run_summary_model',
        'run_lineage_entry_model',
        'run_timer_entry_model',
        'run_timeline_entry_model',
        'run_wait_model',
        'task_model',
        'timer_model',
    ];

    private const OPTIONAL_V2_TABLES = [
        'activity_attempts',
        'activity_executions',
        'workflow_commands',
        'workflow_failures',
        'workflow_links',
        'workflow_run_summaries',
        'workflow_run_lineage_entries',
        'workflow_run_timer_entries',
        'workflow_run_timeline_entries',
        'workflow_run_waits',
        'workflow_signal_records',
        'workflow_tasks',
        'workflow_timers',
        'workflow_updates',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function status(string|null $configured = null): array
    {
        $configured ??= config('waterline.engine_source');
        $normalized = self::normalize($configured);

        if (class_exists(WaterlineEngineSource::class)) {
            return self::allowDegradedV2OperatorSurface(
                WaterlineEngineSource::status(is_string($configured) ? $configured : null),
                $normalized,
            );
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

    /**
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    private static function allowDegradedV2OperatorSurface(array $status, string $configured): array
    {
        if (($status['uses_v2'] ?? false) === true) {
            return $status;
        }

        if (! in_array($configured, [self::ENGINE_AUTO, self::ENGINE_V2], true)) {
            return $status;
        }

        $issues = is_array($status['issues'] ?? null) ? $status['issues'] : [];

        if ($issues === [] || ! self::onlyOptionalProjectionIssues($issues)) {
            return $status;
        }

        $status['resolved'] = self::ENGINE_V2;
        $status['uses_v2'] = true;
        $status['v2_operator_surface_available'] = true;
        $status['status'] = $configured === self::ENGINE_V2 ? 'v2_pinned_degraded' : 'v2_auto_degraded';
        $status['severity'] = 'warning';
        $status['message'] = 'Waterline is using the v2 operator bridge with degraded selected-run projections; durable run and history state remain available.';
        $status['degraded_operator_surface'] = true;
        $status['readiness_contract'] = ReadinessContract::forEngineSourceStatus(
            configured: $configured,
            resolved: self::ENGINE_V2,
            usesV2: true,
            v2OperatorSurfaceAvailable: true,
        );

        return $status;
    }

    /**
     * @param list<mixed> $issues
     */
    private static function onlyOptionalProjectionIssues(array $issues): bool
    {
        foreach ($issues as $issue) {
            if (! is_array($issue)) {
                return false;
            }

            $configKey = $issue['config_key'] ?? null;
            $table = $issue['table'] ?? null;

            if (is_string($configKey) && in_array($configKey, self::OPTIONAL_V2_CONFIG_KEYS, true)) {
                continue;
            }

            if (is_string($table) && in_array($table, self::OPTIONAL_V2_TABLES, true)) {
                continue;
            }

            return false;
        }

        return true;
    }
}
