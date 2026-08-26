<?php

declare(strict_types=1);

namespace Waterline\Http\Controllers\Remote;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Waterline\Repositories\Workflow\Infrastructure\V2VisibilityFilterContext;
use Waterline\Support\ActionabilityContract;
use Waterline\Support\ActionabilityVisibilityFilters;
use Waterline\Support\BackendConfiguration;
use Waterline\Support\Remote\RemoteBackend;
use Waterline\Support\ServiceVisibilityFilters;
use Waterline\Support\WorkflowStreamPresenter;

final class RemoteWorkflowsController extends RemoteController
{
    private const PAGE_SIZE = 50;

    public function __construct(RemoteBackend $backend)
    {
        parent::__construct($backend);
    }

    public function completed(Request $request): JsonResponse
    {
        return $this->list('completed', $request);
    }

    public function failed(Request $request): JsonResponse
    {
        return $this->list('failed', $request);
    }

    public function cancelled(Request $request): JsonResponse
    {
        return $this->list('cancelled', $request);
    }

    public function terminated(Request $request): JsonResponse
    {
        return $this->list('terminated', $request);
    }

    public function running(Request $request): JsonResponse
    {
        return $this->list('running', $request);
    }

    public function show(string $id): JsonResponse
    {
        return $this->detail($id, null);
    }

    public function showSelection(string $instanceId, ?string $runId = null): JsonResponse
    {
        return $this->detail($instanceId, $runId);
    }

    public function historyExport(string $id): JsonResponse
    {
        $execution = $this->backend->client()->describeWorkflow($id);

        return $this->historyExportFor($id, (string) $execution->runId);
    }

    public function historyExportInstance(string $instanceId): JsonResponse
    {
        $execution = $this->backend->client()->describeWorkflow($instanceId);

        return $this->historyExportFor($instanceId, (string) $execution->runId);
    }

    public function historyExportSelection(string $instanceId, string $runId): JsonResponse
    {
        return $this->historyExportFor($instanceId, $runId);
    }

    public function query(string $id, string $query, Request $request): JsonResponse
    {
        return $this->queryFor($id, null, $query, $request);
    }

    public function queryInstance(string $instanceId, string $query, Request $request): JsonResponse
    {
        return $this->queryFor($instanceId, null, $query, $request);
    }

    public function querySelection(string $instanceId, string $runId, string $query, Request $request): JsonResponse
    {
        return $this->queryFor($instanceId, $runId, $query, $request);
    }

    public function signal(string $id, string $signal, Request $request): JsonResponse
    {
        return $this->signalFor($id, null, $signal, $request);
    }

    public function signalInstance(string $instanceId, string $signal, Request $request): JsonResponse
    {
        return $this->signalFor($instanceId, null, $signal, $request);
    }

    public function signalSelection(string $instanceId, string $runId, string $signal, Request $request): JsonResponse
    {
        return $this->signalFor($instanceId, $runId, $signal, $request);
    }

    public function update(string $id, string $update, Request $request): JsonResponse
    {
        return $this->updateFor($id, null, $update, $request);
    }

    public function updateInstance(string $instanceId, string $update, Request $request): JsonResponse
    {
        return $this->updateFor($instanceId, null, $update, $request);
    }

    public function updateSelection(string $instanceId, string $runId, string $update, Request $request): JsonResponse
    {
        return $this->updateFor($instanceId, $runId, $update, $request);
    }

    public function cancel(string $id, Request $request): JsonResponse
    {
        return $this->terminalFor('cancel', $id, null, $request);
    }

    public function cancelInstance(string $instanceId, Request $request): JsonResponse
    {
        return $this->terminalFor('cancel', $instanceId, null, $request);
    }

    public function cancelSelection(string $instanceId, string $runId, Request $request): JsonResponse
    {
        return $this->terminalFor('cancel', $instanceId, $runId, $request);
    }

    public function terminate(string $id, Request $request): JsonResponse
    {
        return $this->terminalFor('terminate', $id, null, $request);
    }

    public function terminateInstance(string $instanceId, Request $request): JsonResponse
    {
        return $this->terminalFor('terminate', $instanceId, null, $request);
    }

    public function terminateSelection(string $instanceId, string $runId, Request $request): JsonResponse
    {
        return $this->terminalFor('terminate', $instanceId, $runId, $request);
    }

    public function repair(string $id): JsonResponse
    {
        return $this->repairFor($id, null);
    }

    public function repairInstance(string $instanceId): JsonResponse
    {
        return $this->repairFor($instanceId, null);
    }

    public function repairSelection(string $instanceId, string $runId): JsonResponse
    {
        return $this->repairFor($instanceId, $runId);
    }

    public function archive(string $id, Request $request): JsonResponse
    {
        return $this->archiveFor($id, null, $request);
    }

    public function archiveInstance(string $instanceId, Request $request): JsonResponse
    {
        return $this->archiveFor($instanceId, null, $request);
    }

    public function archiveSelection(string $instanceId, string $runId, Request $request): JsonResponse
    {
        return $this->archiveFor($instanceId, $runId, $request);
    }

    public function showUpdate(string $id, string $updateId): JsonResponse
    {
        return $this->showUpdateFor($id, null, $updateId);
    }

    public function showUpdateInstance(string $instanceId, string $updateId): JsonResponse
    {
        return $this->showUpdateFor($instanceId, null, $updateId);
    }

    public function showUpdateSelection(string $instanceId, string $runId, string $updateId): JsonResponse
    {
        return $this->showUpdateFor($instanceId, $runId, $updateId);
    }

    private function list(string $bucket, Request $request): JsonResponse
    {
        $pageNumber = max(1, (int) $request->query('page', 1));
        $context = V2VisibilityFilterContext::resolve($request, $bucket);
        $filterPlan = ServiceVisibilityFilters::plan(
            $context['applied_filters'],
            $bucket,
            $this->stringQuery($request, 'query'),
        );
        $savedViewPlan = ServiceVisibilityFilters::plan($context['saved_filters'], $bucket);
        $savedViewApplied = $context['saved_view'] === null
            ? null
            : ($context['saved_view_applied'] === true && $savedViewPlan['unavailable_filters'] === []);
        $savedViewWarning = $context['saved_view_warning']
            ?? ($context['saved_view'] === null ? null : $savedViewPlan['warning']);
        $token = null;
        $page = null;

        for ($current = 1; $current <= $pageNumber; $current++) {
            $page = $this->backend->client()->listWorkflows(
                workflowType: $filterPlan['workflow_type'],
                status: $bucket,
                query: $filterPlan['query'],
                pageSize: self::PAGE_SIZE,
                nextPageToken: $token,
            );
            $token = $page->nextPageToken;

            if ($current < $pageNumber && $token === null) {
                break;
            }
        }

        $items = array_map(fn ($execution): array => $this->listItem($execution->raw), $page?->executions ?? []);
        $lastPage = $token === null ? $pageNumber : $pageNumber + 1;

        return response()->json($this->scoped([
            'data' => $items,
            'current_page' => $pageNumber,
            'last_page' => $lastPage,
            'per_page' => self::PAGE_SIZE,
            'total' => (($pageNumber - 1) * self::PAGE_SIZE) + count($items) + ($token === null ? 0 : 1),
            'next_page_token' => $token,
            'visibility_filters' => [
                'version' => ActionabilityVisibilityFilters::VERSION,
                'supported_versions' => ActionabilityVisibilityFilters::supportedVersions(),
                'bucket' => $bucket,
                'definition' => ServiceVisibilityFilters::definition(),
                'applied' => $filterPlan['applied_filters'],
                'unavailable' => $filterPlan['unavailable_filters'],
                'saved_view' => $context['saved_view'],
                'saved_view_applied' => $savedViewApplied,
                'saved_view_warning' => $savedViewWarning,
                'capability_warning' => $filterPlan['warning'] ?? $savedViewWarning,
                'capability' => $filterPlan['capability'],
                'backend_contract' => 'durable-workflow/sdk.workflow-list',
            ],
        ]));
    }

    private function detail(string $workflowId, ?string $runId): JsonResponse
    {
        $client = $this->backend->client();
        $execution = $client->describeWorkflow($workflowId, $runId);
        $selectedRunId = (string) ($execution->runId ?? $runId ?? '');
        $runs = $client->listWorkflowRuns($workflowId);
        $history = $selectedRunId !== '' ? $client->workflowHistory($workflowId, $selectedRunId) : [];
        $diagnostics = [];
        $workflowStreams = [];
        $workflowStreamsState = 'unavailable';
        $workflowStreamsAvailable = false;
        $workflowStreamsUnavailableReason = 'workflow_streams_run_unavailable';

        if ($selectedRunId !== '' && $this->backend->supports('workflowDiagnostics')) {
            $diagnostics = $client->workflowDiagnostics($workflowId, $selectedRunId);
        }

        if ($selectedRunId !== '') {
            $workflowStreamsContract = $this->backend->workflowStreams($workflowId, $selectedRunId);
            $workflowStreams = app(WorkflowStreamPresenter::class)->service(
                $workflowStreamsContract['streams'],
            );
            $workflowStreamsState = $workflowStreamsContract['state'];
            $workflowStreamsAvailable = $workflowStreamsContract['available'];
            $workflowStreamsUnavailableReason = $workflowStreamsContract['reason'];
        }

        $payload = array_merge($execution->raw, $diagnostics, [
            'id' => $selectedRunId,
            'workflow_instance_id' => $execution->workflowId,
            'instance_id' => $execution->workflowId,
            'workflow_run_id' => $selectedRunId,
            'run_id' => $selectedRunId,
            'selected_run_id' => $selectedRunId,
            'engine_source' => 'service',
            'class' => $execution->workflowType,
            'workflow_type' => $execution->workflowType,
            'namespace' => $execution->namespace ?? BackendConfiguration::namespace(),
            'queue' => $execution->taskQueue,
            'connection' => 'standalone-server',
            'status' => $execution->status,
            'status_bucket' => $execution->statusBucket ?? $this->statusBucket($execution->status),
            'is_terminal' => $execution->isTerminal ?? $this->terminal($execution->status),
            'created_at' => $execution->startedAt,
            'started_at' => $execution->startedAt,
            'closed_at' => $execution->closedAt,
            'arguments' => $execution->input,
            'output' => $execution->output,
            'search_attributes' => $execution->searchAttributes ?? [],
            'timeline' => $this->historyEvents($history),
            'timeline_total_count' => count($this->historyEvents($history)),
            'timeline_returned_count' => count($this->historyEvents($history)),
            'history_event_count' => count($this->historyEvents($history)),
            'run_navigation' => $this->runNavigation($runs, $execution->workflowId, $selectedRunId),
            'activities' => is_array($diagnostics['activities'] ?? null) ? $diagnostics['activities'] : [],
            'tasks' => is_array($diagnostics['tasks'] ?? null) ? $diagnostics['tasks'] : [],
            'waits' => is_array($diagnostics['waits'] ?? null) ? $diagnostics['waits'] : [],
            'timers' => is_array($diagnostics['timers'] ?? null) ? $diagnostics['timers'] : [],
            'signals' => is_array($execution->raw['signals'] ?? null) ? $execution->raw['signals'] : [],
            'updates' => is_array($execution->raw['updates'] ?? null) ? $execution->raw['updates'] : [],
            'commands' => is_array($execution->raw['commands'] ?? null) ? $execution->raw['commands'] : [],
            'workflow_streams' => $workflowStreams,
            'workflow_streams_mode' => 'service',
            'workflow_streams_state' => $workflowStreamsState,
            'workflow_streams_available' => $workflowStreamsAvailable,
            'workflow_streams_unavailable_reason' => $workflowStreamsUnavailableReason,
            'logs' => [],
            'exceptions' => [],
            'chartData' => [],
        ]);

        $payload = $this->applyActions($payload);
        $payload = ActionabilityContract::annotateRun($payload);

        return response()->json($this->scoped($payload));
    }

    private function historyExportFor(string $workflowId, string $runId): JsonResponse
    {
        $payload = $this->backend->client()->exportWorkflowHistory($workflowId, $runId);

        return response()->json($this->scoped(ActionabilityContract::annotateExport($payload)));
    }

    private function queryFor(string $workflowId, ?string $runId, string $query, Request $request): JsonResponse
    {
        $result = $this->backend->client()->queryWorkflow(
            $workflowId,
            $query,
            $this->arguments($request),
            $runId,
        );

        return response()->json($this->scoped([
            'query' => $query,
            'result' => $result,
            'target_scope' => $runId === null ? 'instance' : 'run',
        ]));
    }

    private function signalFor(string $workflowId, ?string $runId, string $signal, Request $request): JsonResponse
    {
        if ($response = $this->requireWriteAccess('signal')) {
            return $response;
        }

        $result = $this->backend->client()->signalWorkflow(
            $workflowId,
            $signal,
            $this->arguments($request),
            $runId,
        );

        return response()->json($this->scoped($result));
    }

    private function updateFor(string $workflowId, ?string $runId, string $update, Request $request): JsonResponse
    {
        if ($response = $this->requireWriteAccess('update')) {
            return $response;
        }

        $result = $this->backend->client()->updateWorkflow(
            $workflowId,
            $update,
            $this->arguments($request),
            (string) $request->input('wait_for', 'completed'),
            $request->integer('wait_timeout_seconds') ?: null,
            is_string($request->input('request_id')) ? $request->input('request_id') : null,
            $runId,
        );

        return response()->json($this->scoped(['update' => $update, 'result' => $result]));
    }

    private function terminalFor(string $operation, string $workflowId, ?string $runId, Request $request): JsonResponse
    {
        if ($response = $this->requireWriteAccess($operation)) {
            return $response;
        }

        $reason = is_string($request->input('reason')) ? $request->input('reason') : null;
        $method = $operation === 'cancel' ? 'cancelWorkflow' : 'terminateWorkflow';
        $result = $this->backend->client()->{$method}($workflowId, $reason, $runId);

        return response()->json($this->scoped($result));
    }

    private function repairFor(string $workflowId, ?string $runId): JsonResponse
    {
        if ($response = $this->requireWriteAccess('repair')) {
            return $response;
        }
        if ($response = $this->requireCapability('repairWorkflow', 'repair')) {
            return $response;
        }

        return response()->json($this->scoped(
            $this->backend->client()->repairWorkflow($workflowId, $runId),
        ));
    }

    private function archiveFor(string $workflowId, ?string $runId, Request $request): JsonResponse
    {
        if ($response = $this->requireWriteAccess('archive')) {
            return $response;
        }
        if ($response = $this->requireCapability('archiveWorkflow', 'archive')) {
            return $response;
        }

        $reason = is_string($request->input('reason')) ? $request->input('reason') : null;

        return response()->json($this->scoped(
            $this->backend->client()->archiveWorkflow($workflowId, $reason, $runId),
        ));
    }

    private function showUpdateFor(string $workflowId, ?string $runId, string $updateId): JsonResponse
    {
        $execution = $this->backend->client()->describeWorkflow($workflowId, $runId);

        foreach (is_array($execution->raw['updates'] ?? null) ? $execution->raw['updates'] : [] as $update) {
            if (is_array($update) && (string) ($update['id'] ?? $update['update_id'] ?? '') === $updateId) {
                return response()->json($this->scoped($update));
            }
        }

        return response()->json([
            'message' => 'Workflow update not found.',
            'reason' => 'update_not_found',
            'update_id' => $updateId,
        ], 404);
    }

    /** @param array<string, mixed> $raw */
    private function listItem(array $raw): array
    {
        return [
            ...$raw,
            'id' => (string) ($raw['run_id'] ?? ''),
            'instance_id' => (string) ($raw['workflow_id'] ?? ''),
            'workflow_instance_id' => (string) ($raw['workflow_id'] ?? ''),
            'run_id' => (string) ($raw['run_id'] ?? ''),
            'class' => (string) ($raw['workflow_type'] ?? ''),
            'engine_source' => 'service',
            'namespace' => BackendConfiguration::namespace(),
            'queue' => $raw['task_queue'] ?? null,
            'created_at' => $raw['started_at'] ?? null,
            'updated_at' => $raw['closed_at'] ?? $raw['started_at'] ?? null,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function applyActions(array $payload): array
    {
        $actions = is_array($payload['actions'] ?? null) ? $payload['actions'] : [];
        foreach (['query', 'signal', 'update', 'cancel', 'terminate', 'repair', 'archive'] as $action) {
            $allowed = ($actions['can_'.$action] ?? false) === true;
            if ($this->backend->readOnly() && $action !== 'query') {
                $allowed = false;
                $payload[$action.'_blocked_reason'] = 'waterline_read_only';
            } elseif (! ($this->backend->capabilities()[$action] ?? false)) {
                $allowed = false;
                $payload[$action.'_blocked_reason'] = 'backend_capability_unavailable';
            } elseif (! $allowed) {
                $payload[$action.'_blocked_reason'] = $payload[$action.'_blocked_reason'] ?? 'server_action_unavailable';
            }

            $payload['can_'.$action] = $allowed;
        }

        $payload['read_only'] = $this->backend->readOnly();
        $payload['read_only_reason'] = $this->backend->readOnly()
            ? 'This Waterline service is configured for read-only observation.'
            : null;

        return $payload;
    }

    /** @param list<object> $runs
     * @return list<array<string, mixed>>
     */
    private function runNavigation(array $runs, string $workflowId, string $selectedRunId): array
    {
        return array_map(static fn ($run): array => [
            ...$run->raw,
            'instance_id' => $workflowId,
            'run_id' => $run->runId,
            'is_selected_run' => $run->runId === $selectedRunId,
            'is_current_run' => $run->isCurrentRun,
        ], $runs);
    }

    /** @param array<string, mixed> $history
     * @return list<array<string, mixed>>
     */
    private function historyEvents(array $history): array
    {
        $events = $history['events'] ?? $history['history_events'] ?? [];

        return is_array($events) ? array_values(array_filter($events, 'is_array')) : [];
    }

    /** @return list<mixed> */
    private function arguments(Request $request): array
    {
        $arguments = $request->input('arguments', []);

        return is_array($arguments) ? array_values($arguments) : [];
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function statusBucket(?string $status): string
    {
        return match ($status) {
            'completed' => 'completed',
            'failed', 'cancelled', 'terminated', 'timed_out' => 'failed',
            default => 'running',
        };
    }

    private function terminal(?string $status): bool
    {
        return in_array($status, ['completed', 'failed', 'cancelled', 'terminated', 'timed_out'], true);
    }
}
