<?php

declare(strict_types=1);

namespace Waterline\Support;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;

/**
 * Maps embedded and service schedule payloads onto one Waterline row shape.
 *
 * A schedule is identified by its namespace and its user-chosen
 * `schedule_id`. Workflow's internal primary key never leaves this class:
 * schedule routes resolve by `schedule_id`, so exposing the key as an
 * identifier sends row actions to a schedule that cannot be found.
 *
 * The server's detail payload nests lifecycle state under `state` and fire
 * counters under `info`; embedded rows and server list rows are flat. Both
 * forms are accepted.
 */
final class ScheduleRow
{
    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    public static function row(array $source): array
    {
        $source = self::flatten($source);
        $action = is_array($source['action'] ?? null) ? $source['action'] : [];
        $scheduleId = self::string($source['schedule_id'] ?? null) ?? '';
        $status = self::string($source['status'] ?? null);

        return [
            // Kept for clients that read `id`; it always equals schedule_id.
            'id' => $scheduleId,
            'schedule_id' => $scheduleId,
            'namespace' => self::string($source['namespace'] ?? null),
            'workflow_type' => self::string($source['workflow_type'] ?? null)
                ?? self::string($action['workflow_type'] ?? null),
            'workflow_class' => self::string($source['workflow_class'] ?? null)
                ?? self::string($action['workflow_class'] ?? null),
            'status' => $status,
            'paused' => $status === 'paused',
            'note' => self::string($source['note'] ?? null),
            'spec' => self::spec($source['spec'] ?? null),
            'overlap_policy' => self::string($source['overlap_policy'] ?? null),
            'next_fire_at' => self::timestamp($source['next_fire_at'] ?? $source['next_fire'] ?? null),
            'last_fired_at' => self::timestamp($source['last_fired_at'] ?? $source['last_fire'] ?? null),
            'last_outcome' => self::lastOutcome($source),
            'fires_count' => self::integer($source['fires_count'] ?? null),
            'failures_count' => self::integer($source['failures_count'] ?? null),
            'skipped_trigger_count' => self::integer($source['skipped_trigger_count'] ?? null),
            'remaining_actions' => self::integer($source['remaining_actions'] ?? null),
            'search_attributes' => is_array($source['search_attributes'] ?? null) ? $source['search_attributes'] : null,
            'created_at' => self::timestamp($source['created_at'] ?? null),
            'updated_at' => self::timestamp($source['updated_at'] ?? null),
        ];
    }

    /**
     * The row plus the facts shown in the schedule detail dialog.
     *
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    public static function detail(array $source): array
    {
        $flat = self::flatten($source);

        return [
            ...self::row($source),
            'action' => is_array($flat['action'] ?? null) ? $flat['action'] : null,
            'max_runs' => self::integer($flat['max_runs'] ?? null),
            'jitter_seconds' => self::integer($flat['jitter_seconds'] ?? null),
            'paused_at' => self::timestamp($flat['paused_at'] ?? null),
            'last_skip_reason' => self::string($flat['last_skip_reason'] ?? null),
            'last_skipped_at' => self::timestamp($flat['last_skipped_at'] ?? null),
            'latest_workflow_instance_id' => self::string(
                $flat['latest_workflow_instance_id'] ?? $flat['latest_instance_id'] ?? null
            ),
            'recent_actions' => self::recentActions($flat['recent_actions'] ?? null),
            'buffered_actions' => self::list($flat['buffered_actions'] ?? null),
            'memo' => is_array($flat['memo'] ?? null) ? $flat['memo'] : null,
        ];
    }

    /**
     * The most recent recorded fire result, or null when nothing was recorded.
     *
     * Workflow appends started and failed fires to `recent_actions` and keeps
     * the latest skipped trigger in `last_skip_reason`/`last_skipped_at`, so the
     * newer of the two is the schedule's last outcome.
     *
     * @param array<string, mixed> $source
     * @return array{outcome: string, at: string|null, reason: string|null, workflow_id: string|null, run_id: string|null}|null
     */
    public static function lastOutcome(array $source): ?array
    {
        $source = self::flatten($source);
        $actions = self::recentActions($source['recent_actions'] ?? null);
        $latest = $actions === [] ? null : $actions[array_key_last($actions)];

        $fire = $latest === null ? null : [
            'outcome' => $latest['outcome'] ?? 'unknown',
            'at' => $latest['fired_at'],
            'reason' => $latest['reason'],
            'workflow_id' => $latest['workflow_id'],
            'run_id' => $latest['run_id'],
        ];

        $skippedAt = self::timestamp($source['last_skipped_at'] ?? null);
        $skip = $skippedAt === null ? null : [
            'outcome' => 'skipped',
            'at' => $skippedAt,
            'reason' => self::string($source['last_skip_reason'] ?? null),
            'workflow_id' => null,
            'run_id' => null,
        ];

        if ($fire === null || $skip === null) {
            return $fire ?? $skip;
        }

        return self::isAfter($skip['at'], $fire['at']) ? $skip : $fire;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private static function flatten(array $source): array
    {
        foreach (['state', 'info'] as $group) {
            if (! is_array($source[$group] ?? null)) {
                continue;
            }

            foreach ($source[$group] as $key => $value) {
                if (is_string($key) && ! array_key_exists($key, $source)) {
                    $source[$key] = $value;
                }
            }
        }

        return $source;
    }

    /**
     * @return array{cron_expressions: list<string>, intervals: list<array{every: string, offset?: string}>, timezone: string|null}
     */
    private static function spec(mixed $spec): array
    {
        $spec = is_array($spec) ? $spec : [];
        $cronExpressions = [];
        $intervals = [];

        foreach (is_array($spec['cron_expressions'] ?? null) ? $spec['cron_expressions'] : [] as $expression) {
            if (is_string($expression) && trim($expression) !== '') {
                $cronExpressions[] = trim($expression);
            }
        }

        foreach (is_array($spec['intervals'] ?? null) ? $spec['intervals'] : [] as $interval) {
            $every = is_array($interval) ? self::string($interval['every'] ?? null) : null;

            if ($every === null) {
                continue;
            }

            $offset = self::string($interval['offset'] ?? null);
            $intervals[] = $offset === null ? ['every' => $every] : ['every' => $every, 'offset' => $offset];
        }

        return [
            'cron_expressions' => $cronExpressions,
            'intervals' => $intervals,
            'timezone' => self::string($spec['timezone'] ?? null),
        ];
    }

    /**
     * @return list<array{outcome: string|null, fired_at: string|null, reason: string|null, workflow_id: string|null, run_id: string|null, occurrence_time: string|null}>
     */
    private static function recentActions(mixed $actions): array
    {
        $rows = [];

        foreach (is_array($actions) ? $actions : [] as $action) {
            if (! is_array($action)) {
                continue;
            }

            $rows[] = [
                'outcome' => self::string($action['outcome'] ?? null),
                'fired_at' => self::timestamp($action['fired_at'] ?? null),
                'reason' => self::string($action['reason'] ?? null),
                'workflow_id' => self::string($action['workflow_id'] ?? null),
                'run_id' => self::string($action['run_id'] ?? null),
                'occurrence_time' => self::timestamp($action['occurrence_time'] ?? null),
            ];
        }

        return $rows;
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    private static function isAfter(?string $left, ?string $right): bool
    {
        if ($left === null) {
            return false;
        }

        if ($right === null) {
            return true;
        }

        try {
            return new DateTimeImmutable($left) > new DateTimeImmutable($right);
        } catch (Exception) {
            return false;
        }
    }

    private static function timestamp(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }

        return self::string($value);
    }

    private static function string(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function integer(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && is_numeric($value)) ? (int) $value : null;
    }
}
