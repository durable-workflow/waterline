<?php

declare(strict_types=1);

namespace Waterline\Support;

use BackedEnum;
use Throwable;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\RunActivityView;

final class CompensationVisibility
{
    private const PAUSE_MARKER_PREFIX = 'pause_after_';

    private const COMPENSATION_PREFIXES = [
        'cancel_',
        'compensate_',
        'refund_',
        'release_',
        'rollback_',
        'undo_',
    ];

    private const VISIBLE_MARKER_STATUSES = [
        'pending',
        'running',
        'completed',
    ];

    private const ACTIVITY_HISTORY_EVENT_TYPES = [
        'ActivityScheduled',
        'ActivityRetryScheduled',
        'ActivityStarted',
        'ActivityHeartbeatRecorded',
        'ActivityCompleted',
        'ActivityFailed',
        'ActivityTimedOut',
        'ActivityCancelled',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public static function activitiesForRun(WorkflowRun $run, bool $useDurableHistoryFallback = false): array
    {
        try {
            $activities = RunActivityView::activitiesForRun($run);

            if ($activities !== [] || ! $useDurableHistoryFallback) {
                return $activities;
            }
        } catch (Throwable) {
            if (! $useDurableHistoryFallback) {
                return [];
            }
        }

        return self::durableHistoryActivitiesForRun($run);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function durableHistoryActivitiesForRun(WorkflowRun $run): array
    {
        return self::activitiesFromDurableHistory($run);
    }

    /**
     * @return array<string, mixed>
     */
    public static function forRun(
        ?WorkflowRun $run,
        bool $useDurableHistoryFallback = false,
        bool $forceDurableHistoryWhenMarkerMissing = false,
    ): array
    {
        if (! $run instanceof WorkflowRun) {
            return self::empty();
        }

        $activities = self::activitiesForRun($run);
        $visibility = self::fromActivities($activities);
        $projectionIsSufficient = $activities !== []
            && (! $forceDurableHistoryWhenMarkerMissing || is_string($visibility['current_marker'] ?? null));

        if (! $useDurableHistoryFallback || $projectionIsSufficient) {
            return $visibility;
        }

        $durableActivities = self::durableHistoryActivitiesForRun($run);
        $durableVisibility = self::fromActivities($durableActivities);

        if ($activities === []) {
            return $durableVisibility;
        }

        if (! is_string($durableVisibility['current_marker'] ?? null)) {
            return $visibility;
        }

        return self::fromActivities(array_merge($activities, $durableActivities));
    }

    /**
     * @param mixed $activities
     * @return array<string, mixed>
     */
    public static function fromActivities(mixed $activities): array
    {
        $activities = is_array($activities)
            ? array_values(array_filter($activities, static fn (mixed $activity): bool => is_array($activity)))
            : [];

        $completed = [];
        $running = [];
        $pending = [];
        $failed = [];
        $markers = [];

        foreach ($activities as $activity) {
            $type = self::stringValue($activity['type'] ?? null);
            $status = self::stringValue($activity['status'] ?? null);

            if ($type === null || $status === null) {
                continue;
            }

            if (self::isPauseMarker($type) && in_array($status, self::VISIBLE_MARKER_STATUSES, true)) {
                $markers[] = [
                    'type' => $type,
                    'status' => $status,
                ];
            }

            if (! self::isCompensationActivity($type)) {
                continue;
            }

            match ($status) {
                'completed' => $completed[] = $type,
                'running' => $running[] = $type,
                'pending' => $pending[] = $type,
                'failed', 'timed_out', 'cancelled' => $failed[] = $type,
                default => null,
            };
        }

        return [
            'current_marker' => self::currentMarker($activities),
            'markers' => $markers,
            'running_compensations' => array_values(array_unique($running)),
            'pending_compensations' => array_values(array_unique($pending)),
            'completed_compensations' => array_values(array_unique($completed)),
            'failed_compensations' => array_values(array_unique($failed)),
        ];
    }

    /**
     * @param list<array<string, mixed>> $activities
     */
    private static function currentMarker(array $activities): ?string
    {
        foreach ($activities as $activity) {
            $type = self::stringValue($activity['type'] ?? null);
            $status = self::stringValue($activity['status'] ?? null);

            if (
                $type !== null
                && $status !== null
                && self::isPauseMarker($type)
                && in_array($status, self::VISIBLE_MARKER_STATUSES, true)
            ) {
                return $type;
            }
        }

        foreach (['running', 'pending'] as $expectedStatus) {
            foreach ($activities as $activity) {
                $type = self::stringValue($activity['type'] ?? null);
                $status = self::stringValue($activity['status'] ?? null);

                if ($type !== null && $status === $expectedStatus && self::isCompensationActivity($type)) {
                    return $type;
                }
            }
        }

        foreach (array_reverse($activities) as $activity) {
            $type = self::stringValue($activity['type'] ?? null);
            $status = self::stringValue($activity['status'] ?? null);

            if ($type !== null && $status === 'completed' && self::isCompensationActivity($type)) {
                return $type;
            }
        }

        return null;
    }

    private static function isPauseMarker(string $type): bool
    {
        return str_starts_with($type, self::PAUSE_MARKER_PREFIX);
    }

    private static function isCompensationActivity(string $type): bool
    {
        foreach (self::COMPENSATION_PREFIXES as $prefix) {
            if (str_starts_with($type, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function activitiesFromDurableHistory(WorkflowRun $run): array
    {
        try {
            $events = $run->historyEvents()
                ->whereIn('event_type', self::ACTIVITY_HISTORY_EVENT_TYPES)
                ->orderBy('sequence')
                ->get([
                    'id',
                    'workflow_run_id',
                    'sequence',
                    'event_type',
                    'payload',
                    'recorded_at',
                ]);
        } catch (Throwable) {
            return [];
        }

        $activities = [];

        foreach ($events as $event) {
            $payload = is_array($event->payload ?? null) ? $event->payload : [];
            $eventType = self::stringValue($event->event_type ?? null);
            $status = self::statusForHistoryEvent($eventType);

            if ($status === null) {
                continue;
            }

            $snapshot = is_array($payload['activity'] ?? null) ? $payload['activity'] : [];
            $activityId = self::stringValue($snapshot['id'] ?? null)
                ?? self::stringValue($payload['activity_execution_id'] ?? null);

            if ($activityId === null) {
                continue;
            }

            $activity = $activities[$activityId] ?? [
                'id' => $activityId,
                'history_authority' => 'durable_history_events',
                'history_event_types' => [],
                'attempts' => [],
            ];
            $historyEventTypes = is_array($activity['history_event_types'] ?? null)
                ? $activity['history_event_types']
                : [];
            $historyEventTypes[] = $eventType;

            $activity = array_merge($activity, array_filter([
                'idempotency_key' => self::stringValue($snapshot['idempotency_key'] ?? null)
                    ?? self::stringValue($payload['idempotency_key'] ?? null)
                    ?? $activityId,
                'sequence' => self::intValue($snapshot['sequence'] ?? null)
                    ?? self::intValue($payload['sequence'] ?? null)
                    ?? self::intValue($event->sequence ?? null),
                'type' => self::stringValue($snapshot['type'] ?? null)
                    ?? self::stringValue($payload['activity_type'] ?? null),
                'class' => self::stringValue($snapshot['class'] ?? null)
                    ?? self::stringValue($payload['activity_class'] ?? null),
                'attempt_id' => self::stringValue($snapshot['attempt_id'] ?? null)
                    ?? self::stringValue($payload['activity_attempt_id'] ?? null),
                'attempt_count' => self::intValue($snapshot['attempt_count'] ?? null)
                    ?? self::intValue($payload['attempt_number'] ?? null),
                'status' => $status,
                'row_status' => $status,
                'history_authority' => 'durable_history_events',
                'history_event_types' => array_values(array_unique($historyEventTypes)),
                'created_at' => self::activityTimestamp($activity['created_at'] ?? null, $event, 'created', $eventType),
                'started_at' => self::activityTimestamp($activity['started_at'] ?? null, $event, 'started', $eventType),
                'closed_at' => self::activityTimestamp($activity['closed_at'] ?? null, $event, 'closed', $eventType),
            ], static fn (mixed $value): bool => $value !== null));

            $activities[$activityId] = $activity;
        }

        $activities = array_values($activities);
        usort($activities, static function (array $left, array $right): int {
            $leftSequence = self::intValue($left['sequence'] ?? null) ?? PHP_INT_MAX;
            $rightSequence = self::intValue($right['sequence'] ?? null) ?? PHP_INT_MAX;

            if ($leftSequence !== $rightSequence) {
                return $leftSequence <=> $rightSequence;
            }

            return ((string) ($left['id'] ?? '')) <=> ((string) ($right['id'] ?? ''));
        });

        return $activities;
    }

    private static function statusForHistoryEvent(?string $eventType): ?string
    {
        return match ($eventType) {
            'ActivityScheduled', 'ActivityRetryScheduled' => 'pending',
            'ActivityStarted', 'ActivityHeartbeatRecorded' => 'running',
            'ActivityCompleted' => 'completed',
            'ActivityFailed', 'ActivityTimedOut' => 'failed',
            'ActivityCancelled' => 'cancelled',
            default => null,
        };
    }

    private static function activityTimestamp(
        mixed $existing,
        mixed $event,
        string $slot,
        ?string $eventType,
    ): mixed {
        if ($existing !== null) {
            return $existing;
        }

        if (
            ($slot === 'created' && $eventType === 'ActivityScheduled')
            || ($slot === 'started' && $eventType === 'ActivityStarted')
            || ($slot === 'closed' && in_array($eventType, [
                'ActivityCompleted',
                'ActivityFailed',
                'ActivityTimedOut',
                'ActivityCancelled',
            ], true))
        ) {
            return $event->recorded_at ?? null;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function empty(): array
    {
        return [
            'current_marker' => null,
            'markers' => [],
            'running_compensations' => [],
            'pending_compensations' => [],
            'completed_compensations' => [],
            'failed_compensations' => [],
        ];
    }

    private static function stringValue(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return is_string($value->value) && $value->value !== '' ? $value->value : null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
