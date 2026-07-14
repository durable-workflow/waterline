<?php

namespace Waterline\Http\Resources;

use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Http\Resources\Json\JsonResource;
use Throwable;
use Waterline\Models\WorkerRegistration;
use Waterline\Support\ActionabilityContract;
use Waterline\Support\CompatibilitySemantics;
use Waterline\Support\CompensationVisibility;
use Waterline\Support\DurableCommandAttribution;
use Waterline\Support\ObserverStateEnvelope;
use Waterline\Support\OperatorScope;
use Waterline\Support\RunDiagnostics;
use Waterline\Support\SelectedRunCommandContract;
use Workflow\V2\Contracts\OperatorObservabilityRepository;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\Support\CommandPayloadPreview;

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

        $detail = DurableCommandAttribution::annotateRunDetail($detail, $this->resource);
        $detail = $this->withTimelineWindow($detail, $request);
        $detail = $this->withDurableCompensationActivities($detail);
        $detail = $this->withSelectedRunIdentity($detail);
        $detail = $this->withSelectedRunStatus($detail);
        $detail = $this->withSelectedRunCompatibility($detail);
        $detail = $this->withSelectedRunSearchAttributes($detail);
        $detail = $this->withUpdateDiagnostics($detail);
        $compensationVisibility = CompensationVisibility::fromActivities($detail['activities'] ?? []);
        $detail['current_compensation_marker'] = $compensationVisibility['current_marker'];
        $detail['compensation_visibility'] = $compensationVisibility;
        $detail['run_diagnostics'] = $this->runDiagnostics($detail);
        $detail = SelectedRunCommandContract::annotateRunDetail($detail, $this->resource);
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
    private function withSelectedRunSearchAttributes(array $detail): array
    {
        if (! is_array($detail['search_attributes'] ?? null)) {
            $detail['search_attributes'] = $this->selectedRunSearchAttributes();
        }

        return $detail;
    }

    /**
     * @return array<string, mixed>
     */
    private function selectedRunSearchAttributes(): array
    {
        try {
            return $this->resource->typedSearchAttributes();
        } catch (Throwable) {
            return [];
        }
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
        $updateFallback = $this->durableUpdateFallbackRows();

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
            'search_attributes' => $this->selectedRunSearchAttributes(),
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
            'updates_scope' => 'selected_run',
            'updates' => $updateFallback['updates'],
            'commands' => $updateFallback['commands'],
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
     * @return array{updates: list<array<string, mixed>>, commands: list<array<string, mixed>>}
     */
    private function durableUpdateFallbackRows(): array
    {
        try {
            $this->resource->loadMissing(['updates.command', 'updates.failure']);
        } catch (Throwable) {
            return ['updates' => [], 'commands' => []];
        }

        $updates = [];
        $commands = [];

        foreach ($this->resource->updates ?? [] as $update) {
            if (! $update instanceof WorkflowUpdate) {
                continue;
            }

            $command = $update->command instanceof WorkflowCommand ? $update->command : null;
            $failure = $update->failure instanceof WorkflowFailure ? $update->failure : null;

            if ($command instanceof WorkflowCommand) {
                $commands[$command->id] = $this->durableCommandFallbackRow($command);
            }

            $updates[] = $this->durableUpdateFallbackRow($update, $command, $failure);
        }

        return [
            'updates' => $updates,
            'commands' => array_values($commands),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function durableCommandFallbackRow(WorkflowCommand $command): array
    {
        return $this->compactDetails([
            'id' => $command->id,
            'type' => $this->statusValue($command->command_type),
            'status' => $this->statusValue($command->status),
            'outcome' => $this->statusValue($command->outcome),
            'target_name' => $this->safeModelString($command, 'targetName'),
            'request_id' => $this->safeModelString($command, 'requestId'),
            'correlation_id' => $this->safeModelString($command, 'correlationId'),
            'request_method' => $this->safeModelString($command, 'requestMethod'),
            'request_path' => $this->safeModelString($command, 'requestPath'),
            'request_route_name' => $this->safeModelString($command, 'requestRouteName'),
            'request_fingerprint' => $this->safeModelString($command, 'requestFingerprint'),
            'principal_type' => $this->safeModelString($command, 'principalType'),
            'principal_id' => $this->safeModelString($command, 'principalId'),
            'principal_label' => $this->safeModelString($command, 'principalLabel'),
            'caller_label' => $this->safeModelString($command, 'callerLabel'),
            'auth_status' => $this->safeModelString($command, 'authStatus'),
            'auth_method' => $this->safeModelString($command, 'authMethod'),
            'source' => $this->stringValue($command->source),
            'payload_codec' => $this->stringValue($command->payload_codec),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function durableUpdateFallbackRow(
        WorkflowUpdate $update,
        ?WorkflowCommand $command,
        ?WorkflowFailure $failure,
    ): array {
        $argumentsAvailable = $this->payloadAvailable($update->arguments);
        $arguments = $argumentsAvailable ? $this->decodeUpdateArguments($update) : null;
        $resultAvailable = $this->payloadAvailable($update->result);
        $result = $resultAvailable ? $this->decodeUpdateResult($update) : null;
        $failureMessage = $this->stringValue($failure?->message);
        $exceptionClass = $this->stringValue($failure?->exception_class);
        $status = $this->statusValue($update->status);
        $name = $this->stringValue($update->update_name)
            ?? ($command instanceof WorkflowCommand ? $this->safeModelString($command, 'targetName') : null);
        $validationErrors = is_array($update->validation_errors) ? $update->validation_errors : [];
        $error = $this->compactDetails([
            'failure_id' => $this->stringValue($update->failure_id),
            'message' => $failureMessage,
            'rejection_reason' => $this->stringValue($update->rejection_reason),
            'validation_errors' => $validationErrors,
            'exception_class' => $exceptionClass,
        ]);

        return $this->compactDetails([
            'id' => $update->id,
            'update_id' => $update->id,
            'command_id' => $update->workflow_command_id,
            'workflow_command_id' => $update->workflow_command_id,
            'name' => $name,
            'update_name' => $name,
            'status' => $status,
            'state' => $status,
            'state_label' => $status === 'rejected' ? 'refused' : $status,
            'refused' => $status === 'rejected' ? true : null,
            'outcome' => $this->statusValue($update->outcome),
            'reason' => $this->stringValue($update->rejection_reason) ?? $failureMessage,
            'rejection_reason' => $this->stringValue($update->rejection_reason),
            'validation_errors' => $validationErrors,
            'failure_id' => $this->stringValue($update->failure_id),
            'failure_message' => $failureMessage,
            'exception_class' => $exceptionClass,
            'request_id' => $command instanceof WorkflowCommand ? $this->safeModelString($command, 'requestId') : null,
            'correlation_id' => $command instanceof WorkflowCommand ? $this->safeModelString($command, 'correlationId') : null,
            'request_method' => $command instanceof WorkflowCommand ? $this->safeModelString($command, 'requestMethod') : null,
            'request_path' => $command instanceof WorkflowCommand ? $this->safeModelString($command, 'requestPath') : null,
            'request_route_name' => $command instanceof WorkflowCommand ? $this->safeModelString($command, 'requestRouteName') : null,
            'request_fingerprint' => $command instanceof WorkflowCommand ? $this->safeModelString($command, 'requestFingerprint') : null,
            'principal_type' => $command instanceof WorkflowCommand ? $this->safeModelString($command, 'principalType') : null,
            'principal_id' => $command instanceof WorkflowCommand ? $this->safeModelString($command, 'principalId') : null,
            'principal_label' => $command instanceof WorkflowCommand ? $this->safeModelString($command, 'principalLabel') : null,
            'caller_label' => $command instanceof WorkflowCommand ? $this->safeModelString($command, 'callerLabel') : null,
            'auth_status' => $command instanceof WorkflowCommand ? $this->safeModelString($command, 'authStatus') : null,
            'auth_method' => $command instanceof WorkflowCommand ? $this->safeModelString($command, 'authMethod') : null,
            'source' => $this->stringValue($command?->source),
            'payload_codec' => $this->stringValue($update->payload_codec),
            'arguments_available' => $argumentsAvailable,
            'arguments' => $argumentsAvailable ? ($arguments ?? []) : null,
            'payload_available' => $argumentsAvailable || $name !== null,
            'payload' => $argumentsAvailable || $name !== null
                ? $this->compactDetails(['name' => $name, 'arguments' => $arguments ?? []])
                : null,
            'result_available' => $resultAvailable,
            'result' => $result,
            'error_available' => $error !== [],
            'error' => $error,
        ]);
    }

    private function safeModelString(object $model, string $method): ?string
    {
        if (! method_exists($model, $method)) {
            return null;
        }

        try {
            return $this->stringValue($model->{$method}());
        } catch (Throwable) {
            return null;
        }
    }

    private function decodeUpdateArguments(WorkflowUpdate $update): mixed
    {
        try {
            return $update->updateArguments();
        } catch (Throwable) {
            return $this->decodedPayloadPreview($update->arguments, $this->stringValue($update->payload_codec));
        }
    }

    private function decodeUpdateResult(WorkflowUpdate $update): mixed
    {
        try {
            return $update->updateResult();
        } catch (Throwable) {
            return $this->decodedPayloadPreview($update->result, $this->stringValue($update->payload_codec));
        }
    }

    private function decodedPayloadPreview(mixed $payload, ?string $codec): mixed
    {
        if (! $this->payloadAvailable($payload)) {
            return null;
        }

        return CommandPayloadPreview::previewWithCodec($payload, $codec);
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
            'selected_run_history_export' => $instanceId === null || $runId === null
                ? null
                : sprintf('%s/instances/%s/runs/%s/history-export', $basePath, $instanceId, $runId),
            'selected_run_query_template' => $instanceId === null || $runId === null
                ? null
                : sprintf('%s/instances/%s/runs/%s/queries/{query}', $basePath, $instanceId, $runId),
            'selected_run_update_template' => $instanceId === null || $runId === null
                ? null
                : sprintf('%s/instances/%s/runs/%s/updates/{update}', $basePath, $instanceId, $runId),
            'selected_run_update_lookup_template' => $instanceId === null || $runId === null
                ? null
                : sprintf('%s/instances/%s/runs/%s/updates/{updateId}', $basePath, $instanceId, $runId),
            'instance_query_template' => $instanceId === null
                ? null
                : sprintf('%s/instances/%s/queries/{query}', $basePath, $instanceId),
            'instance_update_template' => $instanceId === null
                ? null
                : sprintf('%s/instances/%s/updates/{update}', $basePath, $instanceId),
            'instance_update_lookup_template' => $instanceId === null
                ? null
                : sprintf('%s/instances/%s/updates/{updateId}', $basePath, $instanceId),
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

    /**
     * @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    private function withUpdateDiagnostics(array $detail): array
    {
        $updates = $this->rowList($detail['updates'] ?? null);
        $commandsById = $this->rowsById($detail['commands'] ?? null);
        [$historyByUpdateId, $historyByCommandId, $allHistoryReferences] = $this->updateHistoryReferenceMaps();
        $enriched = [];
        $historyReferences = [];

        foreach ($updates as $update) {
            $updateId = $this->stringValue($update['id'] ?? null);
            $commandId = $this->stringValue($update['command_id'] ?? null);
            $command = $commandId === null ? [] : ($commandsById[$commandId] ?? []);
            $references = $this->historyReferencesForUpdate(
                $updateId,
                $commandId,
                $historyByUpdateId,
                $historyByCommandId,
            );

            $enrichedUpdate = $this->withUpdateRowDiagnostics($update, $command, $references);
            $enriched[] = $enrichedUpdate;

            foreach ($references as $reference) {
                $referenceId = $this->stringValue($reference['id'] ?? null)
                    ?? implode(':', array_filter([
                        $this->stringValue($reference['type'] ?? null),
                        (string) ($reference['sequence'] ?? ''),
                    ]));
                $historyReferences[$referenceId] = $reference;
            }
        }

        $detail['updates_scope'] = $detail['updates_scope'] ?? 'selected_run';
        $detail['updates_projection_source'] = $allHistoryReferences === []
            ? ($detail['updates_projection_source'] ?? 'workflow_updates')
            : 'workflow_history_events';
        $detail['updates'] = $enriched;
        $detail['update_history_references'] = array_values($historyReferences);
        $detail['update_diagnostics'] = $this->updateDiagnosticsSummary($enriched, $historyReferences);

        return $detail;
    }

    /**
     * @param array<string, mixed> $update
     * @param array<string, mixed> $command
     * @param list<array<string, mixed>> $historyReferences
     * @return array<string, mixed>
     */
    private function withUpdateRowDiagnostics(array $update, array $command, array $historyReferences): array
    {
        foreach ([
            'request_id',
            'correlation_id',
            'request_method',
            'request_path',
            'request_route_name',
            'request_fingerprint',
            'principal_type',
            'principal_id',
            'principal_label',
            'caller_label',
            'auth_status',
            'auth_method',
        ] as $field) {
            if (! $this->hasDetailValue($update[$field] ?? null)) {
                $update[$field] = $this->stringValue($command[$field] ?? null)
                    ?? $this->firstHistoryValue($historyReferences, $field);
            }
        }

        if (! $this->hasDetailValue($update['source'] ?? null)) {
            $update['source'] = $this->stringValue($command['source'] ?? null)
                ?? $this->firstHistoryValue($historyReferences, 'source');
        }

        $status = $this->stringValue($update['status'] ?? null);
        $update['state'] = $status;
        $update['state_label'] = $status === 'rejected' ? 'refused' : $status;
        $update['refused'] = $status === 'rejected';
        $update['reason'] = $this->stringValue($update['reason'] ?? null)
            ?? $this->stringValue($update['rejection_reason'] ?? null)
            ?? $this->stringValue($update['failure_message'] ?? null)
            ?? $this->stringValue($update['outcome'] ?? null);

        $payload = $this->updatePayload($update, $command);
        $update['payload_available'] = $this->payloadAvailable($payload);
        $update['payload'] = $payload;
        $update['error'] = $this->updateError($update);
        $update['error_available'] = $update['error'] !== null;
        $update['request_identifiers'] = $this->compactDetails([
            'request_id' => $update['request_id'] ?? null,
            'correlation_id' => $update['correlation_id'] ?? null,
            'request_fingerprint' => $update['request_fingerprint'] ?? null,
            'command_id' => $update['command_id'] ?? null,
            'update_id' => $update['id'] ?? null,
        ]);
        $update['history_events'] = $historyReferences;
        $update['history_event_ids'] = array_values(array_filter(array_map(
            fn (array $reference): ?string => $this->stringValue($reference['id'] ?? null),
            $historyReferences,
        )));
        $update['history_event_sequences'] = array_values(array_filter(array_map(
            fn (array $reference): ?int => is_int($reference['sequence'] ?? null)
                ? $reference['sequence']
                : null,
            $historyReferences,
        ), static fn (?int $sequence): bool => $sequence !== null));
        $update['history_event_types'] = array_values(array_unique(array_filter(array_map(
            fn (array $reference): ?string => $this->stringValue($reference['type'] ?? null),
            $historyReferences,
        ))));

        return $update;
    }

    /**
     * @param mixed $rows
     * @return list<array<string, mixed>>
     */
    private function rowList(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter(
            $rows,
            static fn (mixed $row): bool => is_array($row),
        ));
    }

    /**
     * @param mixed $rows
     * @return array<string, array<string, mixed>>
     */
    private function rowsById(mixed $rows): array
    {
        $indexed = [];

        foreach ($this->rowList($rows) as $row) {
            $id = $this->stringValue($row['id'] ?? null);

            if ($id !== null) {
                $indexed[$id] = $row;
            }
        }

        return $indexed;
    }

    /**
     * @return array{
     *     0: array<string, list<array<string, mixed>>>,
     *     1: array<string, list<array<string, mixed>>>,
     *     2: list<array<string, mixed>>
     * }
     */
    private function updateHistoryReferenceMaps(): array
    {
        try {
            $this->resource->loadMissing(['historyEvents']);
        } catch (Throwable) {
            return [[], [], []];
        }

        $byUpdateId = [];
        $byCommandId = [];
        $all = [];

        foreach ($this->resource->historyEvents ?? [] as $event) {
            if (! $event instanceof WorkflowHistoryEvent || ! $this->isUpdateHistoryEvent($event)) {
                continue;
            }

            $reference = $this->updateHistoryReference($event);
            $all[] = $reference;
            $updateId = $this->stringValue($reference['update_id'] ?? null);
            $commandId = $this->stringValue($reference['workflow_command_id'] ?? null);

            if ($updateId !== null) {
                $byUpdateId[$updateId][] = $reference;
            }

            if ($commandId !== null) {
                $byCommandId[$commandId][] = $reference;
            }
        }

        return [$byUpdateId, $byCommandId, $all];
    }

    private function isUpdateHistoryEvent(WorkflowHistoryEvent $event): bool
    {
        return in_array($this->statusValue($event->event_type), [
            'UpdateAccepted',
            'UpdateRejected',
            'UpdateApplied',
            'UpdateCompleted',
        ], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function updateHistoryReference(WorkflowHistoryEvent $event): array
    {
        $payload = is_array($event->payload) ? $event->payload : [];
        $command = is_array($payload['command'] ?? null) ? $payload['command'] : [];
        $exception = is_array($payload['exception'] ?? null) ? $payload['exception'] : [];
        $type = $this->statusValue($event->event_type);
        $failureId = $this->stringValue($payload['failure_id'] ?? null);

        return $this->compactDetails([
            'id' => $event->id,
            'sequence' => is_numeric($event->sequence ?? null) ? (int) $event->sequence : null,
            'type' => $type,
            'event_type' => $type,
            'recorded_at' => $this->timestampValue($event->recorded_at),
            'workflow_command_id' => $this->stringValue($event->workflow_command_id)
                ?? $this->stringValue($payload['workflow_command_id'] ?? null)
                ?? $this->stringValue($command['id'] ?? null),
            'update_id' => $this->stringValue($payload['update_id'] ?? null),
            'update_name' => $this->stringValue($payload['update_name'] ?? null)
                ?? $this->stringValue($command['target_name'] ?? null),
            'outcome' => $this->stringValue($payload['outcome'] ?? null)
                ?? $this->stringValue($command['outcome'] ?? null),
            'rejection_reason' => $this->stringValue($payload['rejection_reason'] ?? null)
                ?? $this->stringValue($command['rejection_reason'] ?? null),
            'failure_id' => $failureId,
            'message' => $this->stringValue($payload['message'] ?? null)
                ?? $this->stringValue($exception['message'] ?? null),
            'request_id' => $this->stringValue($command['request_id'] ?? null),
            'correlation_id' => $this->stringValue($command['correlation_id'] ?? null),
            'request_method' => $this->stringValue($command['request_method'] ?? null),
            'request_path' => $this->stringValue($command['request_path'] ?? null),
            'request_route_name' => $this->stringValue($command['request_route_name'] ?? null),
            'request_fingerprint' => $this->stringValue($command['request_fingerprint'] ?? null),
            'source' => $this->stringValue($command['source'] ?? null),
            'principal_type' => $this->stringValue($command['principal_type'] ?? null),
            'principal_id' => $this->stringValue($command['principal_id'] ?? null),
            'principal_label' => $this->stringValue($command['principal_label'] ?? null),
            'caller_label' => $this->stringValue($command['caller_label'] ?? null),
            'auth_status' => $this->stringValue($command['auth_status'] ?? null),
            'auth_method' => $this->stringValue($command['auth_method'] ?? null),
            'arguments_available' => array_key_exists('arguments', $payload),
            'result_available' => array_key_exists('result', $payload) && $failureId === null,
        ]);
    }

    /**
     * @param array<string, list<array<string, mixed>>> $historyByUpdateId
     * @param array<string, list<array<string, mixed>>> $historyByCommandId
     * @return list<array<string, mixed>>
     */
    private function historyReferencesForUpdate(
        ?string $updateId,
        ?string $commandId,
        array $historyByUpdateId,
        array $historyByCommandId,
    ): array {
        $references = [];

        foreach ([
            $updateId === null ? [] : ($historyByUpdateId[$updateId] ?? []),
            $commandId === null ? [] : ($historyByCommandId[$commandId] ?? []),
        ] as $group) {
            foreach ($group as $reference) {
                $key = $this->stringValue($reference['id'] ?? null)
                    ?? implode(':', array_filter([
                        $this->stringValue($reference['type'] ?? null),
                        (string) ($reference['sequence'] ?? ''),
                    ]));
                $references[$key] = $reference;
            }
        }

        usort($references, static function (array $left, array $right): int {
            $leftSequence = is_int($left['sequence'] ?? null) ? $left['sequence'] : PHP_INT_MAX;
            $rightSequence = is_int($right['sequence'] ?? null) ? $right['sequence'] : PHP_INT_MAX;

            if ($leftSequence !== $rightSequence) {
                return $leftSequence <=> $rightSequence;
            }

            return (string) ($left['id'] ?? '') <=> (string) ($right['id'] ?? '');
        });

        return array_values($references);
    }

    /**
     * @param list<array<string, mixed>> $historyReferences
     */
    private function firstHistoryValue(array $historyReferences, string $field): ?string
    {
        foreach ($historyReferences as $reference) {
            $value = $this->stringValue($reference[$field] ?? null);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $update
     * @param array<string, mixed> $command
     */
    private function updatePayload(array $update, array $command): mixed
    {
        if (($command['payload_available'] ?? false) === true && array_key_exists('payload', $command)) {
            return $command['payload'];
        }

        if (array_key_exists('payload', $update) && $this->payloadAvailable($update['payload'])) {
            return $update['payload'];
        }

        if (($update['arguments_available'] ?? false) !== true && ! array_key_exists('arguments', $update)) {
            return null;
        }

        return $this->compactDetails([
            'name' => $update['name'] ?? null,
            'arguments' => $update['arguments'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $update
     * @return array<string, mixed>|null
     */
    private function updateError(array $update): ?array
    {
        $error = $this->compactDetails([
            'failure_id' => $update['failure_id'] ?? null,
            'message' => $update['failure_message'] ?? null,
            'rejection_reason' => $update['rejection_reason'] ?? null,
            'validation_errors' => $update['validation_errors'] ?? null,
            'exception_type' => $update['exception_type'] ?? null,
            'exception_class' => $update['exception_class'] ?? null,
            'exception_resolved_class' => $update['exception_resolved_class'] ?? null,
            'exception_resolution_source' => $update['exception_resolution_source'] ?? null,
            'exception_resolution_error' => $update['exception_resolution_error'] ?? null,
            'exception_replay_blocked' => ($update['exception_replay_blocked'] ?? false) === true ? true : null,
        ]);

        return $error === [] ? null : $error;
    }

    private function payloadAvailable(mixed $payload): bool
    {
        return ! ($payload === null || $payload === '' || $payload === []);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function compactDetails(array $values): array
    {
        return array_filter($values, static function (mixed $value): bool {
            return ! ($value === null || $value === '' || $value === []);
        });
    }

    /**
     * @param list<array<string, mixed>> $updates
     * @param array<string, array<string, mixed>> $historyReferences
     * @return array<string, mixed>
     */
    private function updateDiagnosticsSummary(array $updates, array $historyReferences): array
    {
        return [
            'surface' => 'selected_run_detail',
            'scope' => 'selected_run',
            'history_authority' => 'workflow_history_events',
            'update_count' => count($updates),
            'history_event_count' => count($historyReferences),
            'state_counts' => $this->updateStateCounts($updates),
            'raw_status_counts' => $this->updateRawStatusCounts($updates),
            'request_identifier_fields' => [
                'update_id',
                'command_id',
                'request_id',
                'correlation_id',
                'request_fingerprint',
            ],
            'payload_fields' => [
                'payload',
                'arguments',
                'result',
                'error',
            ],
            'history_reference_fields' => [
                'history_events',
                'history_event_ids',
                'history_event_sequences',
                'history_event_types',
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $updates
     * @return array<string, int>
     */
    private function updateStateCounts(array $updates): array
    {
        $counts = [
            'accepted' => 0,
            'completed' => 0,
            'failed' => 0,
            'refused' => 0,
        ];

        foreach ($updates as $update) {
            $status = $this->stringValue($update['status'] ?? null);
            $key = $status === 'rejected' ? 'refused' : $status;

            if (is_string($key) && array_key_exists($key, $counts)) {
                $counts[$key]++;
            }
        }

        return $counts;
    }

    /**
     * @param list<array<string, mixed>> $updates
     * @return array<string, int>
     */
    private function updateRawStatusCounts(array $updates): array
    {
        $counts = [];

        foreach ($updates as $update) {
            $status = $this->stringValue($update['status'] ?? null);

            if ($status === null) {
                continue;
            }

            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function hasDetailValue(mixed $value): bool
    {
        return ! ($value === null || $value === '' || $value === []);
    }

    private function timestampValue(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toJSON();
        }

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

}
