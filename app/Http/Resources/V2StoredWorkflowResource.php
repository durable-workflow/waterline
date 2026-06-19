<?php

namespace Waterline\Http\Resources;

use BackedEnum;
use Illuminate\Http\Resources\Json\JsonResource;
use Throwable;
use Waterline\Models\WorkerRegistration;
use Workflow\V2\Contracts\OperatorObservabilityRepository;
use Workflow\V2\Models\WorkflowRun;
use Waterline\Support\ActionabilityContract;
use Waterline\Support\CompatibilitySemantics;
use Waterline\Support\CompensationVisibility;
use Waterline\Support\ObserverStateEnvelope;
use Waterline\Support\OperatorScope;
use Waterline\Support\RunDiagnostics;

/**
 * @mixin WorkflowRun
 */
class V2StoredWorkflowResource extends JsonResource
{
    public static $wrap = null;

    private const DEFAULT_TIMELINE_WINDOW = 200;
    private const MIN_TIMELINE_WINDOW = 50;
    private const MAX_TIMELINE_WINDOW = 1000;

    public function toArray($request)
    {
        try {
            $detail = app(OperatorObservabilityRepository::class)->runDetail(
                $this->resource,
                $this->timelineLimit($request),
            );
        } catch (Throwable) {
            $detail = $this->fallbackRunDetail();
        }

        $detail = $this->withTimelineWindow($detail, $request);
        $detail = $this->withDurableCompensationActivities($detail);
        $detail = $this->withSelectedRunIdentity($detail);
        $detail = $this->withSelectedRunStatus($detail);
        $detail = $this->withSelectedRunCompatibility($detail);
        $compensationVisibility = CompensationVisibility::fromActivities($detail['activities'] ?? []);
        $detail['current_compensation_marker'] = $compensationVisibility['current_marker'];
        $detail['compensation_visibility'] = $compensationVisibility;
        $detail['run_diagnostics'] = $this->runDiagnostics($detail);
        $detail = ObserverStateEnvelope::annotateRun($detail, $this->observerPaths($request, $detail));
        $detail = CompatibilitySemantics::annotateRun($detail);
        $detail['namespace'] = $this->resource->namespace;
        $detail['operator_scope'] = OperatorScope::payload();

        return ActionabilityContract::annotateRun($detail);
    }

    /**
     * @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    private function withSelectedRunIdentity(array $detail): array
    {
        $detail['id'] = $this->resource->id;
        $detail['workflow_instance_id'] = $this->resource->workflow_instance_id;
        $detail['instance_id'] = $this->resource->workflow_instance_id;
        $detail['workflow_run_id'] = $this->resource->id;
        $detail['run_id'] = $this->resource->id;
        $detail['selected_run_id'] = $this->resource->id;

        return $detail;
    }

    /**
     * @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    private function withSelectedRunStatus(array $detail): array
    {
        $status = $this->statusValue($this->resource->status);

        if (! is_string($detail['status'] ?? null) || trim((string) $detail['status']) === '') {
            $detail['status'] = $status;
        }

        if (! is_string($detail['status_bucket'] ?? null) || trim((string) $detail['status_bucket']) === '') {
            $detail['status_bucket'] = $this->statusBucket($status);
        }

        if (! is_bool($detail['is_terminal'] ?? null)) {
            $detail['is_terminal'] = $this->isTerminalStatus($status);
        }

        return $detail;
    }

    /**
     * @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    private function withSelectedRunCompatibility(array $detail): array
    {
        foreach ([
            'compatibility' => $this->resource->compatibility,
            'connection' => $this->resource->connection,
            'queue' => $this->resource->queue,
        ] as $key => $value) {
            if (! is_string($detail[$key] ?? null) || trim((string) $detail[$key]) === '') {
                $detail[$key] = is_string($value) && trim($value) !== '' ? trim($value) : null;
            }
        }

        return $detail;
    }

    /**
     * @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    private function withDurableCompensationActivities(array $detail): array
    {
        $activities = $this->activityList($detail['activities'] ?? null);
        $visibility = CompensationVisibility::fromActivities($activities);

        if (is_string($visibility['current_marker'] ?? null)) {
            $detail['activities'] = $activities;

            return $detail;
        }

        $durableActivities = CompensationVisibility::durableHistoryActivitiesForRun($this->resource);
        $durableVisibility = CompensationVisibility::fromActivities($durableActivities);

        if (! is_string($durableVisibility['current_marker'] ?? null)) {
            $detail['activities'] = $activities;

            return $detail;
        }

        $detail['activities'] = $this->mergeActivityLists($activities, $durableActivities);

        if (! is_array($detail['operator_visibility_degraded'] ?? null)) {
            $detail['operator_visibility_degraded'] = [
                'reason' => 'selected_run_projection_incomplete',
                'message' => 'Waterline merged durable activity history because selected-run activity projections did not expose the current compensation marker.',
            ];
        }

        return $detail;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activityList(mixed $activities): array
    {
        if (! is_array($activities)) {
            return [];
        }

        return array_values(array_filter(
            $activities,
            static fn (mixed $activity): bool => is_array($activity),
        ));
    }

    /**
     * @param list<array<string, mixed>> $activities
     * @param list<array<string, mixed>> $durableActivities
     * @return list<array<string, mixed>>
     */
    private function mergeActivityLists(array $activities, array $durableActivities): array
    {
        $merged = [];

        foreach ($activities as $index => $activity) {
            $key = $this->activityKey($activity);
            $key ??= 'selected:'.$index;
            $merged[$key] = $activity;
        }

        foreach ($durableActivities as $index => $activity) {
            $key = $this->activityKey($activity);
            $key ??= 'durable:'.$index;
            $merged[$key] = array_merge($merged[$key] ?? [], $activity);
        }

        return array_values($merged);
    }

    /**
     * @param array<string, mixed> $activity
     */
    private function activityKey(array $activity): ?string
    {
        foreach (['id', 'idempotency_key', 'type'] as $field) {
            $value = $activity[$field] ?? null;

            if (is_string($value) && $value !== '') {
                return $field.':'.$value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $detail
     * @return list<array<string, mixed>>
     */
    private function runDiagnostics(array $detail): array
    {
        try {
            return app(RunDiagnostics::class)->forRun($this->resource, $detail);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fallbackRunDetail(): array
    {
        $status = $this->statusValue($this->resource->status);
        $activities = CompensationVisibility::durableHistoryActivitiesForRun($this->resource);

        return [
            'id' => $this->resource->id,
            'instance_id' => $this->resource->workflow_instance_id,
            'workflow_instance_id' => $this->resource->workflow_instance_id,
            'selected_run_id' => $this->resource->id,
            'run_id' => $this->resource->id,
            'workflow_run_id' => $this->resource->id,
            'run_number' => $this->resource->run_number,
            'is_current_run' => true,
            'current_run_id' => $this->resource->id,
            'engine_source' => 'v2',
            'class' => $this->resource->workflow_class,
            'workflow_type' => $this->resource->workflow_type,
            'namespace' => $this->resource->namespace,
            'business_key' => $this->resource->business_key,
            'compatibility' => $this->resource->compatibility,
            'connection' => $this->resource->connection,
            'queue' => $this->resource->queue,
            'visibility_labels' => is_array($this->resource->visibility_labels)
                ? $this->resource->visibility_labels
                : [],
            'status' => $status,
            'status_bucket' => $this->statusBucket($status),
            'is_terminal' => $this->isTerminalStatus($status),
            'closed_reason' => $this->resource->closed_reason,
            'closed_at' => $this->resource->closed_at,
            'created_at' => $this->resource->started_at ?? $this->resource->created_at,
            'updated_at' => $this->resource->last_progress_at ?? $this->resource->updated_at,
            'history_event_count' => is_numeric($this->resource->last_history_sequence)
                ? (int) $this->resource->last_history_sequence
                : count($activities),
            'history_size_bytes' => 0,
            'history_fan_out' => 0,
            'activities_scope' => 'selected_run',
            'activities' => $activities,
            'tasks' => $this->fallbackTasks(),
            'timeline' => [],
            'timeline_total_count' => 0,
            'timeline_returned_count' => 0,
            'operator_visibility_degraded' => [
                'reason' => 'selected_run_projection_unavailable',
                'message' => 'Waterline rendered durable run state and activity history because selected-run projections were unavailable.',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fallbackTasks(): array
    {
        try {
            return $this->resource->tasks()
                ->orderBy('available_at')
                ->get()
                ->map(fn ($task): array => $this->fallbackTask($task))
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fallbackTask($task): array
    {
        $type = $this->statusValue($task->task_type ?? null);
        $status = $this->statusValue($task->status ?? null);
        $compatibility = is_string($task->compatibility ?? null) && trim((string) $task->compatibility) !== ''
            ? trim((string) $task->compatibility)
            : null;
        $supported = $this->fallbackTaskCompatibilitySupported($task, $compatibility);

        return [
            'id' => $task->id ?? null,
            'type' => $type,
            'status' => $status,
            'is_open' => in_array($status, ['ready', 'leased'], true),
            'compatibility' => $compatibility,
            'connection' => $task->connection ?? null,
            'queue' => $task->queue ?? null,
            'attempt_count' => is_numeric($task->attempt_count ?? null) ? (int) $task->attempt_count : null,
            'available_at' => $task->available_at,
            'compatibility_supported_in_fleet' => $supported,
            'compatibility_fleet_reason' => $supported === false && $compatibility !== null
                ? sprintf('No active worker heartbeat advertises compatibility [%s].', $compatibility)
                : null,
        ];
    }

    private function fallbackTaskCompatibilitySupported($task, ?string $compatibility): ?bool
    {
        if ($compatibility === null) {
            return null;
        }

        try {
            $query = WorkerRegistration::query()
                ->where('status', 'active')
                ->where('build_id', $compatibility);

            if (is_string($task->namespace ?? null) && trim((string) $task->namespace) !== '') {
                $query->where('namespace', trim((string) $task->namespace));
            }

            if (is_string($task->queue ?? null) && trim((string) $task->queue) !== '') {
                $query->where('task_queue', trim((string) $task->queue));
            }

            return $query->exists();
        } catch (Throwable) {
            return null;
        }
    }

    private function statusValue(mixed $status): ?string
    {
        if ($status instanceof BackedEnum) {
            return is_string($status->value) ? $status->value : null;
        }

        return is_string($status) && $status !== '' ? $status : null;
    }

    private function statusBucket(?string $status): ?string
    {
        return match ($status) {
            'completed' => 'completed',
            'failed' => 'failed',
            'cancelled' => 'cancelled',
            'terminated' => 'terminated',
            null => null,
            default => 'running',
        };
    }

    private function isTerminalStatus(?string $status): bool
    {
        return in_array($status, ['completed', 'failed', 'cancelled', 'terminated', 'timed_out'], true);
    }

    /**
     * @param array<string, mixed> $detail
     *
     * @return array<string, string|null>
     */
    private function observerPaths($request, array $detail): array
    {
        $waterlinePath = trim((string) config('waterline.path', 'waterline'), '/');
        $basePath = ($waterlinePath === '' ? '' : '/'.$waterlinePath).'/api';
        $instanceId = $this->pathValue($detail['instance_id'] ?? null);
        $runId = $this->pathValue($detail['run_id'] ?? $detail['selected_run_id'] ?? null);

        return [
            'selected_run_detail' => '/'.ltrim((string) $request->path(), '/'),
            'selected_run_query_template' => $instanceId === null || $runId === null
                ? null
                : sprintf('%s/instances/%s/runs/%s/queries/{query}', $basePath, $instanceId, $runId),
            'instance_query_template' => $instanceId === null
                ? null
                : sprintf('%s/instances/%s/queries/{query}', $basePath, $instanceId),
        ];
    }

    private function pathValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? rawurlencode($value) : null;
    }

    /**
     * @param array<string, mixed> $detail
     *
     * @return array<string, mixed>
     */
    private function withTimelineWindow(array $detail, $request): array
    {
        $timeline = is_array($detail['timeline'] ?? null)
            ? array_values($detail['timeline'])
            : [];
        $limit = $this->timelineLimit($request);
        $total = $this->timelineTotal($detail, count($timeline));

        if ($limit !== null && count($timeline) > $limit) {
            $timeline = array_slice($timeline, -$limit);
        }

        $returned = count($timeline);
        $detail['timeline'] = $timeline;
        $detail['timeline_total_count'] = $total;
        $detail['timeline_returned_count'] = $returned;
        $detail['timeline_window_limit'] = $limit;
        $detail['timeline_window_direction'] = 'latest';
        $detail['timeline_truncated'] = $returned < $total;
        $detail['timeline_older_count'] = max(0, $total - $returned);
        $detail['timeline_window_start_sequence'] = $this->timelineBoundary($timeline, 'first');
        $detail['timeline_window_end_sequence'] = $this->timelineBoundary($timeline, 'last');

        return $detail;
    }

    private function timelineLimit($request): ?int
    {
        $requested = $request->query('history_limit', self::DEFAULT_TIMELINE_WINDOW);

        if ($requested === 'all') {
            return null;
        }

        $limit = filter_var($requested, FILTER_VALIDATE_INT);

        if ($limit === false) {
            $limit = self::DEFAULT_TIMELINE_WINDOW;
        }

        return min(self::MAX_TIMELINE_WINDOW, max(self::MIN_TIMELINE_WINDOW, $limit));
    }

    /**
     * @param array<string, mixed> $detail
     */
    private function timelineTotal(array $detail, int $fallback): int
    {
        $total = $detail['timeline_total_count'] ?? null;

        return is_numeric($total) ? max(0, (int) $total) : $fallback;
    }

    /**
     * @param list<array<string, mixed>> $timeline
     */
    private function timelineBoundary(array $timeline, string $position): ?int
    {
        if ($timeline === []) {
            return null;
        }

        $entry = $position === 'last'
            ? $timeline[array_key_last($timeline)]
            : $timeline[0];

        $sequence = $entry['sequence'] ?? null;

        return is_numeric($sequence) ? (int) $sequence : null;
    }

}
