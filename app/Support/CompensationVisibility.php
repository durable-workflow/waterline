<?php

declare(strict_types=1);

namespace Waterline\Support;

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

    /**
     * @return list<array<string, mixed>>
     */
    public static function activitiesForRun(WorkflowRun $run): array
    {
        try {
            return RunActivityView::activitiesForRun($run);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function forRun(?WorkflowRun $run): array
    {
        if (! $run instanceof WorkflowRun) {
            return self::empty();
        }

        return self::fromActivities(self::activitiesForRun($run));
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
        return is_string($value) && $value !== '' ? $value : null;
    }
}
