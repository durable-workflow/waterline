<?php

declare(strict_types=1);

namespace Waterline\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Throwable;

final class RunWaitSummary
{
    private const LIMIT = 50;

    /** @param array<string, mixed> $detail */
    public static function annotate(array $detail, ?CarbonInterface $now = null): array
    {
        $now ??= Carbon::now();
        $tasks = self::indexedRows($detail['tasks'] ?? null);
        $activities = self::indexedRows($detail['activities'] ?? null);
        $waits = [];
        $openCount = 0;

        foreach (is_array($detail['waits'] ?? null) ? $detail['waits'] : [] as $wait) {
            if (! is_array($wait) || ($wait['status'] ?? null) !== 'open') {
                continue;
            }

            $openCount++;
            if (count($waits) >= self::LIMIT) {
                continue;
            }

            $task = $tasks[$wait['task_id'] ?? ''] ?? [];
            $activity = ($wait['kind'] ?? null) === 'activity'
                ? ($activities[$wait['resume_source_id'] ?? ''] ?? [])
                : [];
            $retry = ($wait['kind'] ?? null) === 'activity'
                && is_numeric($task['retry_after_attempt'] ?? null)
                && (int) $task['retry_after_attempt'] > 0;
            $unavailable = ($wait['diagnostic_only'] ?? false) === true;
            $deadline = self::timestamp($wait['deadline_at'] ?? null);
            $resume = ($task['status'] ?? null) === 'ready'
                ? self::timestamp($task['available_at'] ?? null)
                : null;
            if (($wait['kind'] ?? null) === 'timer' && $resume === null) {
                $resume = $deadline;
            }
            if (($wait['kind'] ?? null) === 'timer') {
                // A timer's fire time makes it eligible, rather than expiring the run.
                $deadline = null;
            }

            $state = 'resume_time_unknown';
            if ($unavailable) {
                $state = 'unavailable';
                $resume = null;
                $deadline = null;
            } elseif ($deadline !== null && $deadline->lessThanOrEqualTo($now)) {
                $state = 'deadline_elapsed';
            } elseif ($resume !== null) {
                $state = $resume->greaterThan($now) ? 'planned' : 'eligible';
            }

            $waits[] = [
                'id' => $wait['id'] ?? null,
                'kind' => $retry ? 'activity_retry' : ($wait['kind'] ?? 'unknown'),
                'state' => $state,
                'reason' => $retry ? ($task['summary'] ?? $wait['summary'] ?? null) : ($wait['summary'] ?? null),
                'dependency_id' => $wait['resume_source_id'] ?? null,
                'dependency_type' => $wait['target_type'] ?? null,
                'target_name' => $wait['target_name'] ?? null,
                'task_id' => $wait['task_id'] ?? null,
                'next_scheduled_resume_at' => $resume?->toIso8601String(),
                'deadline_at' => $deadline?->toIso8601String(),
                'attempt_number' => $retry ? (int) $task['retry_after_attempt'] + 1 : ($activity['attempt_count'] ?? null),
                'attempt_limit' => $task['retry_max_attempts'] ?? $activity['retry_policy']['max_attempts'] ?? null,
                'unavailable_reason' => $unavailable ? ($wait['history_unsupported_reason'] ?? 'wait_history_unavailable') : null,
            ];
        }

        $detail['current_waits'] = $waits;
        $detail['current_waits_count'] = $openCount;
        $detail['current_waits_truncated'] = $openCount > count($waits);
        $detail['current_waits_limit'] = self::LIMIT;
        $detail['current_waits_state'] = ($detail['details_pruned_at'] ?? null) !== null
            ? 'pruned'
            : (is_array($detail['waits'] ?? null) ? 'available' : 'unavailable');

        return $detail;
    }

    /** @return array<string, array<string, mixed>> */
    private static function indexedRows(mixed $rows): array
    {
        $index = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && is_string($row['id'] ?? null)) {
                $index[$row['id']] = $row;
            }
        }

        return $index;
    }

    private static function timestamp(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
