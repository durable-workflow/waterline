<?php

declare(strict_types=1);

namespace Waterline\Support;

/**
 * One trigger and backfill result shape for embedded and service mode.
 *
 * Embedded Workflow reports a trigger as a ScheduleTriggerResult and a
 * backfill as rows of `cron_time`/`instance_id`/`error`. The server reports a
 * trigger `outcome` with `workflow_id` and backfill rows with `fire_time`,
 * `workflow_id` and `outcome`. The page reads only this normalized form.
 */
final class ScheduleActionResult
{
    public const OVERLAP_POLICIES = [
        'skip',
        'buffer_one',
        'buffer_all',
        'cancel_other',
        'terminate_other',
        'allow_all',
    ];

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    public static function trigger(string $scheduleId, ?string $namespace, array $source): array
    {
        $outcome = self::string($source['outcome'] ?? null) ?? 'unknown';
        $workflowId = self::string($source['workflow_id'] ?? $source['instance_id'] ?? null);

        return [
            'schedule_id' => self::string($source['schedule_id'] ?? null) ?? $scheduleId,
            'namespace' => $namespace,
            'outcome' => $outcome,
            'triggered' => $outcome === 'triggered',
            'workflow_id' => $workflowId,
            // Earlier embedded responses named the started workflow instance_id.
            'instance_id' => $workflowId,
            'run_id' => self::string($source['run_id'] ?? null),
            'reason' => self::string($source['reason'] ?? null),
            'buffer_depth' => is_int($source['buffer_depth'] ?? null) ? $source['buffer_depth'] : null,
        ];
    }

    /**
     * @param list<mixed> $rows
     * @return array<string, mixed>
     */
    public static function backfill(string $scheduleId, ?string $namespace, array $rows): array
    {
        $results = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $workflowId = self::string($row['workflow_id'] ?? $row['instance_id'] ?? null);
            $reason = self::string($row['reason'] ?? $row['error'] ?? null);
            $outcome = self::string($row['outcome'] ?? null)
                ?? (array_key_exists('error', $row) ? 'failed' : ($workflowId !== null ? 'started' : 'skipped'));

            $results[] = [
                'fire_time' => self::string($row['fire_time'] ?? $row['cron_time'] ?? null),
                'workflow_id' => $workflowId,
                'outcome' => $outcome,
                'reason' => $reason,
            ];
        }

        $count = static fn (string $outcome): int => count(array_filter(
            $results,
            static fn (array $result): bool => $result['outcome'] === $outcome,
        ));

        return [
            'schedule_id' => $scheduleId,
            'namespace' => $namespace,
            'fires_attempted' => count($results),
            'started_count' => $count('started'),
            'skipped_count' => $count('skipped'),
            'failed_count' => $count('failed'),
            'results' => $results,
        ];
    }

    public static function validOverlapPolicy(?string $policy): bool
    {
        return $policy === null || in_array($policy, self::OVERLAP_POLICIES, true);
    }

    private static function string(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
