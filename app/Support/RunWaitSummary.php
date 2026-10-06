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
        if (! is_array($detail['waits'] ?? null)
            && ($detail['engine_source'] ?? null) === 'service'
            && is_array($detail['execution'] ?? null)) {
            return self::serviceSummary($detail, $now);
        }
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

            if ($unavailable) {
                $resume = null;
                $deadline = null;
            }

            $waits[] = [
                'id' => $wait['id'] ?? null,
                'kind' => $retry ? 'activity_retry' : ($wait['kind'] ?? 'unknown'),
                'state' => $unavailable ? 'unavailable' : self::state($resume, $deadline, $now),
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

    /**
     * Server diagnostics are a bounded summary, rather than a complete wait set.
     * Keep run timing separate from activity timing when the server has not
     * identified which activity owns its next scheduled task.
     *
     * @param array<string, mixed> $detail
     */
    private static function serviceSummary(array $detail, CarbonInterface $now): array
    {
        $execution = $detail['execution'];
        $pruned = ($detail['details_pruned_at'] ?? null) !== null;
        $waits = [];
        $kind = $execution['wait_kind'] ?? null;
        if (! $pruned && is_string($kind) && $kind !== '' && $kind !== 'none'
            && ($execution['is_terminal'] ?? $detail['is_terminal'] ?? false) !== true) {
            $next = is_array($execution['next_scheduled_event'] ?? null)
                ? $execution['next_scheduled_event'] : [];
            $resume = ($next['task_status'] ?? null) === 'ready'
                ? self::timestamp($next['available_at'] ?? null) : null;
            $deadline = $kind === 'timer' ? null : self::timestamp($next['wait_deadline_at'] ?? null);
            $waits[] = [
                'id' => 'summary:'.($detail['run_id'] ?? ''),
                'kind' => $kind,
                'state' => self::state($resume, $deadline, $now),
                'reason' => $execution['wait_reason'] ?? str_replace('_', ' ', $kind),
                'dependency_id' => null,
                'dependency_type' => null,
                'task_id' => $next['task_id'] ?? null,
                'next_scheduled_resume_at' => $resume?->toIso8601String(),
                'deadline_at' => $deadline?->toIso8601String(),
                'attempt_number' => null,
                'attempt_limit' => null,
            ];
        }

        $activities = self::indexedRows($detail['activities'] ?? null);
        foreach (! $pruned && is_array($detail['pending_activities'] ?? null) ? $detail['pending_activities'] : [] as $activity) {
            if (! is_array($activity) || ! is_string($activity['activity_execution_id'] ?? null)
                || ! in_array($activity['status'] ?? null, ['pending', 'running'], true)) {
                continue;
            }
            if (count($waits) >= self::LIMIT) {
                break;
            }
            $id = $activity['activity_execution_id'];
            $deadlineFields = ($activity['status'] ?? null) === 'running'
                ? ['close_deadline_at', 'heartbeat_deadline_at', 'schedule_to_close_deadline_at']
                : ['schedule_deadline_at', 'schedule_to_close_deadline_at'];
            $deadline = null;
            foreach ($deadlineFields as $field) {
                $candidate = self::timestamp($activity[$field] ?? null);
                if ($candidate !== null && ($deadline === null || $candidate->lessThan($deadline))) {
                    $deadline = $candidate;
                }
            }
            $waits[] = [
                'id' => 'activity:'.$id,
                'kind' => 'activity',
                'state' => self::state(null, $deadline, $now),
                'reason' => $activity['activity_type'] ?? $activity['activity_class'] ?? 'Activity',
                'dependency_id' => $id,
                'dependency_type' => $activity['activity_type'] ?? $activity['activity_class'] ?? null,
                'task_id' => null,
                'next_scheduled_resume_at' => null,
                'deadline_at' => $deadline?->toIso8601String(),
                'attempt_number' => $activity['current_attempt']['attempt_number'] ?? $activity['attempt_count'] ?? null,
                'attempt_limit' => $activities[$id]['retry_policy']['max_attempts'] ?? null,
            ];
        }

        $detail['current_waits'] = $waits;
        $detail['current_waits_count'] = null;
        $detail['current_waits_returned_count'] = count($waits);
        $detail['current_waits_truncated'] = count($waits) >= self::LIMIT;
        $detail['current_waits_limit'] = self::LIMIT;
        $detail['current_waits_state'] = $pruned ? 'pruned' : 'partial';
        $detail['current_waits_source'] = 'server_diagnostics';

        return $detail;
    }

    private static function state(?CarbonInterface $resume, ?CarbonInterface $deadline, CarbonInterface $now): string
    {
        if ($deadline !== null && $deadline->lessThanOrEqualTo($now)) {
            return 'deadline_elapsed';
        }
        if ($resume !== null) {
            return $resume->greaterThan($now) ? 'planned' : 'eligible';
        }

        return 'resume_time_unknown';
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
