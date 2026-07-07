<?php

namespace Waterline\Http\Controllers;

use BackedEnum;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use InvalidArgumentException;
use LogicException;
use Throwable;
use Workflow\V2\CommandContext;
use Workflow\V2\Contracts\HistoryExportRedactor;
use Workflow\V2\Contracts\OperatorObservabilityRepository;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\Support\CommandPayloadPreview;
use Workflow\V2\Support\CommandResponse;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\HistoryBudget;
use Workflow\V2\Support\QueryResponse;
use Workflow\V2\Support\RunListItemView;
use Workflow\V2\Support\UpdateWaitPolicy;
use Workflow\V2\Support\WorkflowExecutionGate;
use Workflow\V2\UpdateResult;
use Workflow\V2\WorkflowStub as V2WorkflowStub;
use Waterline\Http\Resources\StoredWorkflowResource;
use Waterline\Http\Resources\V2StoredWorkflowResource;
use Waterline\Repositories\Workflow\Infrastructure\V2VisibilityFilterContext;
use Waterline\Repositories\Workflow\Interfaces\WorkflowRepositoryInterface;
use Waterline\Support\ActionabilityContract;
use Waterline\Support\ActionabilityVisibilityFilters;
use Waterline\Support\CompatibilitySemantics;
use Waterline\Support\CompensationVisibility;
use Waterline\Support\OperatorScope;
use Waterline\Support\SelectedRunCommandContract;
use Waterline\Waterline;

class WorkflowsController extends Controller
{
    public function completed(WorkflowRepositoryInterface $repository)
    {
        return $this->listResponse('completed', $repository, $repository->completedFlows());
    }

    public function failed(WorkflowRepositoryInterface $repository)
    {
        return $this->listResponse('failed', $repository, $repository->failedFlows());
    }

    public function cancelled(WorkflowRepositoryInterface $repository)
    {
        return $this->listResponse('cancelled', $repository, $repository->cancelledFlows());
    }

    public function terminated(WorkflowRepositoryInterface $repository)
    {
        return $this->listResponse('terminated', $repository, $repository->terminatedFlows());
    }

    public function running(WorkflowRepositoryInterface $repository)
    {
        return $this->listResponse('running', $repository, $repository->runningFlows());
    }

    public function show(string $id, WorkflowRepositoryInterface $repository)
    {
        $flow = $repository->findFlow($id);

        return $repository->engineSource() === 'v2'
            ? V2StoredWorkflowResource::make($flow)
            : StoredWorkflowResource::make($flow);
    }

    public function showSelection(string $instanceId, WorkflowRepositoryInterface $repository, ?string $runId = null)
    {
        abort_unless($repository->engineSource() === 'v2', 404);

        $flow = $repository->findFlowSelection($instanceId, $runId);

        return V2StoredWorkflowResource::make($flow);
    }

    public function historyExport(
        string $id,
        WorkflowRepositoryInterface $repository,
        OperatorObservabilityRepository $observability,
    )
    {
        abort_unless($repository->engineSource() === 'v2', 404);

        $flow = $repository->findFlow($id);

        return $this->historyExportResponse($flow, $observability);
    }

    public function historyExportInstance(
        string $instanceId,
        WorkflowRepositoryInterface $repository,
        OperatorObservabilityRepository $observability,
    ) {
        abort_unless($repository->engineSource() === 'v2', 404);

        $flow = $repository->findFlowSelection($instanceId);

        return $this->historyExportResponse($flow, $observability);
    }

    public function historyExportSelection(
        string $instanceId,
        string $runId,
        WorkflowRepositoryInterface $repository,
        OperatorObservabilityRepository $observability,
    ) {
        abort_unless($repository->engineSource() === 'v2', 404);

        $flow = $repository->findFlowSelection($instanceId, $runId);

        return $this->historyExportResponse($flow, $observability);
    }

    public function query(
        string $id,
        string $query,
        Request $request,
        WorkflowRepositoryInterface $repository,
    ) {
        abort_unless($repository->engineSource() === 'v2', 404);

        $flow = $repository->findFlow($id);

        return $this->queryResponse(
            V2WorkflowStub::loadRun($flow->id, $this->commandNamespace()),
            $query,
            $this->commandArguments($request),
            'run',
        );
    }

    public function queryInstance(
        string $instanceId,
        string $query,
        Request $request,
        WorkflowRepositoryInterface $repository,
    ) {
        abort_unless($repository->engineSource() === 'v2', 404);

        return $this->queryResponse(
            V2WorkflowStub::load($instanceId, $this->commandNamespace()),
            $query,
            $this->commandArguments($request),
            'instance',
        );
    }

    public function querySelection(
        string $instanceId,
        string $runId,
        string $query,
        Request $request,
        WorkflowRepositoryInterface $repository,
    ) {
        abort_unless($repository->engineSource() === 'v2', 404);

        $flow = $repository->findFlowSelection($instanceId, $runId);

        return $this->queryResponse(
            V2WorkflowStub::loadSelection($instanceId, $runId, $this->commandNamespace()),
            $query,
            $this->commandArguments($request),
            'run',
            $flow,
        );
    }

    public function cancel(string $id, Request $request, WorkflowRepositoryInterface $repository)
    {
        abort_unless($repository->engineSource() === 'v2', 404);

        $reason = $this->commandReason($request);

        $flow = $repository->findFlow($id);
        $result = V2WorkflowStub::loadRun($flow->id, $this->commandNamespace())
            ->withCommandContext($this->commandContext($request))
            ->attemptCancel($reason);

        return response()->json(
            $this->withOperatorScope(CommandResponse::payload($result)),
            $result->accepted() ? 200 : 409,
        );
    }

    public function signal(
        string $id,
        string $signal,
        Request $request,
        WorkflowRepositoryInterface $repository,
    ) {
        abort_unless($repository->engineSource() === 'v2', 404);

        $flow = $repository->findFlow($id);
        $result = V2WorkflowStub::loadRun($flow->id, $this->commandNamespace())
            ->withCommandContext($this->commandContext($request))
            ->attemptSignalWithArguments($signal, $this->commandArguments($request));

        return $this->commandResponse($result);
    }

    public function signalInstance(
        string $instanceId,
        string $signal,
        Request $request,
        WorkflowRepositoryInterface $repository,
    ) {
        abort_unless($repository->engineSource() === 'v2', 404);

        $result = V2WorkflowStub::load($instanceId, $this->commandNamespace())
            ->withCommandContext($this->commandContext($request))
            ->attemptSignalWithArguments($signal, $this->commandArguments($request));

        return $this->commandResponse($result);
    }

    public function signalSelection(
        string $instanceId,
        string $runId,
        string $signal,
        Request $request,
        WorkflowRepositoryInterface $repository,
    ) {
        abort_unless($repository->engineSource() === 'v2', 404);

        $result = V2WorkflowStub::loadSelection($instanceId, $runId, $this->commandNamespace())
            ->withCommandContext($this->commandContext($request))
            ->attemptSignalWithArguments($signal, $this->commandArguments($request));

        return $this->commandResponse($result);
    }

    public function update(
        string $id,
        string $update,
        Request $request,
        WorkflowRepositoryInterface $repository,
    ) {
        abort_unless($repository->engineSource() === 'v2', 404);

        $flow = $repository->findFlow($id);
        $stub = $this->updateStub(
            V2WorkflowStub::loadRun($flow->id, $this->commandNamespace())
                ->withCommandContext($this->commandContext($request)),
            $request,
        );
        $result = $this->shouldSubmitUpdate($request)
            ? $stub->submitUpdateWithArguments($update, $this->commandArguments($request))
            : $stub->attemptUpdateWithArguments($update, $this->commandArguments($request));

        return $this->commandResponse($result);
    }

    public function updateInstance(
        string $instanceId,
        string $update,
        Request $request,
        WorkflowRepositoryInterface $repository,
    ) {
        abort_unless($repository->engineSource() === 'v2', 404);

        $stub = $this->updateStub(
            V2WorkflowStub::load($instanceId, $this->commandNamespace())
                ->withCommandContext($this->commandContext($request)),
            $request,
        );
        $result = $this->shouldSubmitUpdate($request)
            ? $stub->submitUpdateWithArguments($update, $this->commandArguments($request))
            : $stub->attemptUpdateWithArguments($update, $this->commandArguments($request));

        return $this->commandResponse($result);
    }

    public function updateSelection(
        string $instanceId,
        string $runId,
        string $update,
        Request $request,
        WorkflowRepositoryInterface $repository,
    ) {
        abort_unless($repository->engineSource() === 'v2', 404);

        $stub = $this->updateStub(
            V2WorkflowStub::loadSelection($instanceId, $runId, $this->commandNamespace())
                ->withCommandContext($this->commandContext($request)),
            $request,
        );
        $result = $this->shouldSubmitUpdate($request)
            ? $stub->submitUpdateWithArguments($update, $this->commandArguments($request))
            : $stub->attemptUpdateWithArguments($update, $this->commandArguments($request));

        return $this->commandResponse($result);
    }

    public function showUpdate(string $id, string $updateId, WorkflowRepositoryInterface $repository)
    {
        abort_unless($repository->engineSource() === 'v2', 404);

        $flow = $repository->findFlow($id);

        try {
            $result = V2WorkflowStub::loadRun($flow->id, $this->commandNamespace())->inspectUpdate($updateId);
        } catch (LogicException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 404);
        }

        return $this->updateLookupResponse($result, $flow);
    }

    public function showUpdateInstance(string $instanceId, string $updateId, WorkflowRepositoryInterface $repository)
    {
        abort_unless($repository->engineSource() === 'v2', 404);

        try {
            $result = V2WorkflowStub::load($instanceId, $this->commandNamespace())->inspectUpdate($updateId);
        } catch (LogicException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 404);
        }

        return $this->updateLookupResponse($result, $this->flowForUpdateResult($result));
    }

    public function showUpdateSelection(
        string $instanceId,
        string $runId,
        string $updateId,
        WorkflowRepositoryInterface $repository,
    ) {
        abort_unless($repository->engineSource() === 'v2', 404);

        try {
            $result = V2WorkflowStub::loadSelection($instanceId, $runId, $this->commandNamespace())->inspectUpdate($updateId);
        } catch (LogicException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 404);
        }

        try {
            $flow = $repository->findFlowSelection($instanceId, $runId);
        } catch (Throwable) {
            $flow = null;
        }

        return $this->updateLookupResponse($result, $flow);
    }

    public function cancelInstance(string $instanceId, Request $request, WorkflowRepositoryInterface $repository)
    {
        abort_unless($repository->engineSource() === 'v2', 404);

        $reason = $this->commandReason($request);

        $result = V2WorkflowStub::load($instanceId, $this->commandNamespace())
            ->withCommandContext($this->commandContext($request))
            ->attemptCancel($reason);

        return response()->json(
            $this->withOperatorScope(CommandResponse::payload($result)),
            $result->accepted() ? 200 : 409,
        );
    }

    public function cancelSelection(
        string $instanceId,
        string $runId,
        Request $request,
        WorkflowRepositoryInterface $repository,
    ) {
        abort_unless($repository->engineSource() === 'v2', 404);

        $reason = $this->commandReason($request);

        $result = V2WorkflowStub::loadSelection($instanceId, $runId, $this->commandNamespace())
            ->withCommandContext($this->commandContext($request))
            ->attemptCancel($reason);

        return response()->json(
            $this->withOperatorScope(CommandResponse::payload($result)),
            $result->accepted() ? 200 : 409,
        );
    }

    public function repair(string $id, Request $request, WorkflowRepositoryInterface $repository)
    {
        abort_unless($repository->engineSource() === 'v2', 404);

        $flow = $repository->findFlow($id);
        $result = V2WorkflowStub::loadRun($flow->id, $this->commandNamespace())
            ->withCommandContext($this->commandContext($request))
            ->attemptRepair();

        return response()->json(
            $this->withOperatorScope(CommandResponse::payload($result)),
            $result->accepted() ? 200 : 409,
        );
    }

    public function repairInstance(string $instanceId, Request $request, WorkflowRepositoryInterface $repository)
    {
        abort_unless($repository->engineSource() === 'v2', 404);

        $result = V2WorkflowStub::load($instanceId, $this->commandNamespace())
            ->withCommandContext($this->commandContext($request))
            ->attemptRepair();

        return response()->json(
            $this->withOperatorScope(CommandResponse::payload($result)),
            $result->accepted() ? 200 : 409,
        );
    }

    public function repairSelection(
        string $instanceId,
        string $runId,
        Request $request,
        WorkflowRepositoryInterface $repository,
    ) {
        abort_unless($repository->engineSource() === 'v2', 404);

        $result = V2WorkflowStub::loadSelection($instanceId, $runId, $this->commandNamespace())
            ->withCommandContext($this->commandContext($request))
            ->attemptRepair();

        return response()->json(
            $this->withOperatorScope(CommandResponse::payload($result)),
            $result->accepted() ? 200 : 409,
        );
    }

    public function terminate(string $id, Request $request, WorkflowRepositoryInterface $repository)
    {
        abort_unless($repository->engineSource() === 'v2', 404);

        $reason = $this->commandReason($request);

        $flow = $repository->findFlow($id);
        $result = V2WorkflowStub::loadRun($flow->id, $this->commandNamespace())
            ->withCommandContext($this->commandContext($request))
            ->attemptTerminate($reason);

        return response()->json(
            $this->withOperatorScope(CommandResponse::payload($result)),
            $result->accepted() ? 200 : 409,
        );
    }

    public function terminateInstance(string $instanceId, Request $request, WorkflowRepositoryInterface $repository)
    {
        abort_unless($repository->engineSource() === 'v2', 404);

        $reason = $this->commandReason($request);

        $result = V2WorkflowStub::load($instanceId, $this->commandNamespace())
            ->withCommandContext($this->commandContext($request))
            ->attemptTerminate($reason);

        return response()->json(
            $this->withOperatorScope(CommandResponse::payload($result)),
            $result->accepted() ? 200 : 409,
        );
    }

    public function terminateSelection(
        string $instanceId,
        string $runId,
        Request $request,
        WorkflowRepositoryInterface $repository,
    ) {
        abort_unless($repository->engineSource() === 'v2', 404);

        $reason = $this->commandReason($request);

        $result = V2WorkflowStub::loadSelection($instanceId, $runId, $this->commandNamespace())
            ->withCommandContext($this->commandContext($request))
            ->attemptTerminate($reason);

        return response()->json(
            $this->withOperatorScope(CommandResponse::payload($result)),
            $result->accepted() ? 200 : 409,
        );
    }

    public function archive(string $id, Request $request, WorkflowRepositoryInterface $repository)
    {
        abort_unless($repository->engineSource() === 'v2', 404);

        $flow = $repository->findFlow($id);
        $result = V2WorkflowStub::loadRun($flow->id, $this->commandNamespace())
            ->withCommandContext($this->commandContext($request))
            ->attemptArchive($this->archiveReason($request));

        return $this->commandResponse($result);
    }

    public function archiveInstance(string $instanceId, Request $request, WorkflowRepositoryInterface $repository)
    {
        abort_unless($repository->engineSource() === 'v2', 404);

        $result = V2WorkflowStub::load($instanceId, $this->commandNamespace())
            ->withCommandContext($this->commandContext($request))
            ->attemptArchive($this->archiveReason($request));

        return $this->commandResponse($result);
    }

    public function archiveSelection(
        string $instanceId,
        string $runId,
        Request $request,
        WorkflowRepositoryInterface $repository,
    ) {
        abort_unless($repository->engineSource() === 'v2', 404);

        $result = V2WorkflowStub::loadSelection($instanceId, $runId, $this->commandNamespace())
            ->withCommandContext($this->commandContext($request))
            ->attemptArchive($this->archiveReason($request));

        return $this->commandResponse($result);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function commandArguments(Request $request): array
    {
        if (! $request->exists('arguments')) {
            return [];
        }

        $arguments = $request->input('arguments');

        return is_array($arguments)
            ? $arguments
            : [$arguments];
    }

    private function commandReason(Request $request): ?string
    {
        $reason = $request->input('reason');

        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }

    private function archiveReason(Request $request): ?string
    {
        $reason = $request->input('reason');

        if (! is_string($reason)) {
            $arguments = $request->input('arguments');
            $reason = is_array($arguments) && is_string($arguments['reason'] ?? null)
                ? $arguments['reason']
                : null;
        }

        $reason = is_string($reason) ? trim($reason) : '';

        return $reason === '' ? null : $reason;
    }

    private function commandNamespace(): ?string
    {
        $namespace = config('waterline.namespace');

        return is_string($namespace) && trim($namespace) !== '' ? trim($namespace) : null;
    }

    private function commandContext(Request $request): CommandContext
    {
        $context = CommandContext::waterline($request);
        $principal = Waterline::principalFor($request);

        return $principal === null
            ? $context
            : $context->withPrincipal($principal['type'], $principal['id'], $principal['label'] ?? null);
    }

    private function shouldSubmitUpdate(Request $request): bool
    {
        return UpdateWaitPolicy::shouldSubmitAcceptedOnly($request->input('wait_for'));
    }

    private function updateStub(V2WorkflowStub $stub, Request $request): V2WorkflowStub
    {
        if ($this->shouldSubmitUpdate($request)) {
            return $stub;
        }

        return $stub->withUpdateWaitTimeout(
            UpdateWaitPolicy::requestedTimeoutSeconds($request->input('wait_timeout_seconds'))
        );
    }

    private function listResponse(string $bucket, WorkflowRepositoryInterface $repository, mixed $result)
    {
        if ($repository->engineSource() !== 'v2' || ! $result instanceof Arrayable) {
            return $result;
        }

        $context = V2VisibilityFilterContext::resolve(request(), $bucket);
        $payload = $result->toArray();

        $payload['data'] = array_map(
            fn (mixed $item): array => $item instanceof WorkflowRunSummary
                ? $this->listItemView($item, $bucket === 'running')
                : (is_array($item) ? $this->annotateListItem($item) : []),
            $result instanceof LengthAwarePaginator ? $result->items() : ($payload['data'] ?? []),
        );

        $payload['visibility_filters'] = [
            'version' => ActionabilityVisibilityFilters::VERSION,
            'supported_versions' => ActionabilityVisibilityFilters::supportedVersions(),
            'bucket' => $bucket,
            'definition' => $context['definition'],
            'applied' => $context['applied_filters'],
            'saved_view' => $context['saved_view'],
            'saved_view_applied' => $context['saved_view_applied'],
            'saved_view_warning' => $context['saved_view_warning'],
            'actionability_contract' => ActionabilityContract::definition(),
        ];
        $payload['operator_scope'] = OperatorScope::payload();

        return response()->json($payload);
    }

    /**
     * @return mixed
     */
    private function historyExportResponse(WorkflowRun $flow, OperatorObservabilityRepository $observability)
    {
        $redactor = $this->historyExportRedactor();

        try {
            $export = $observability->runHistoryExport($flow, null, $redactor);
        } catch (Throwable) {
            $export = $this->durableHistoryExportFallback($flow, $redactor);
        }

        return response()->json($this->annotateHistoryExport(
            ActionabilityContract::annotateExport($export),
            $flow->namespace,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function durableHistoryExportFallback(
        WorkflowRun $flow,
        HistoryExportRedactor|callable|null $redactor = null,
    ): array {
        $status = $this->statusValue($flow->status);
        $historyEvents = $this->durableHistoryEvents($flow);
        $updateFallback = $this->durableUpdateFallbackRows($flow);

        $export = [
            'schema' => HistoryExport::SCHEMA,
            'schema_version' => HistoryExport::SCHEMA_VERSION,
            'exported_at' => $this->timestamp(now()),
            'dedupe_key' => hash('sha256', implode('|', [
                $flow->workflow_instance_id,
                $flow->id,
                (string) ($flow->last_history_sequence ?? ''),
                (string) count($historyEvents),
            ])),
            'history_complete' => $this->isTerminalStatus($status),
            'workflow' => [
                'instance_id' => $flow->workflow_instance_id,
                'run_id' => $flow->id,
                'run_number' => $flow->run_number,
                'is_current_run' => true,
                'current_run_id' => $flow->id,
                'current_run_source' => 'durable_run_fallback',
                'workflow_type' => $flow->workflow_type,
                'workflow_class' => $flow->workflow_class,
                'business_key' => $flow->business_key,
                'visibility_labels' => is_array($flow->visibility_labels) ? $flow->visibility_labels : [],
                'status' => $status,
                'status_bucket' => $this->statusBucket($status),
                'closed_reason' => $flow->closed_reason,
                'archived_at' => $this->timestamp($flow->archived_at),
                'archive_command_id' => $flow->archive_command_id,
                'archive_reason' => $flow->archive_reason,
                'compatibility' => $flow->compatibility,
                'connection' => $flow->connection,
                'queue' => $flow->queue,
                'last_history_sequence' => $flow->last_history_sequence,
                'started_at' => $this->timestamp($flow->started_at),
                'closed_at' => $this->timestamp($flow->closed_at),
                'last_progress_at' => $this->timestamp($flow->last_progress_at),
            ],
            'payloads' => [
                'codec' => is_string($flow->payload_codec) && $flow->payload_codec !== ''
                    ? $flow->payload_codec
                    : config('workflows.serializer'),
                'arguments' => [
                    'available' => is_string($flow->arguments),
                    'data' => null,
                ],
                'output' => [
                    'available' => is_string($flow->output),
                    'data' => null,
                ],
            ],
            'summary' => null,
            'selected_run' => [
                'waits_projection_source' => 'durable_run_fallback',
                'timeline_projection_source' => 'durable_history_events',
                'timers_projection_source' => 'durable_run_fallback',
                'timers_projection_rebuild_reasons' => [],
                'lineage_projection_source' => 'durable_run_fallback',
            ],
            'history_events' => $historyEvents,
            'waits' => [],
            'timeline' => $historyEvents,
            'linked_intakes_scope' => 'selected_run',
            'linked_intakes' => [],
            'commands' => $updateFallback['commands'],
            'signals' => [],
            'updates' => $updateFallback['updates'],
            'tasks' => [],
            'activities' => [],
            'timers' => [],
            'failures' => [],
            'links' => [
                'projection_source' => 'durable_run_fallback',
                'parents' => [],
                'children' => [],
            ],
            'operator_visibility_degraded' => [
                'reason' => 'selected_run_projection_unavailable',
                'message' => 'Waterline rendered a durable history export fallback because selected-run projections were unavailable.',
            ],
        ];

        $export = $this->withHistoryExportUpdateDiagnostics($export);
        $export = $this->withFallbackRedaction($export, $flow, $redactor);
        $export['integrity'] = $this->fallbackIntegrity($export);

        return $export;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function durableHistoryEvents(WorkflowRun $flow): array
    {
        try {
            return $flow->historyEvents()
                ->orderBy('sequence')
                ->get()
                ->map(fn ($event): array => [
                    'id' => $event->id,
                    'sequence' => $event->sequence,
                    'type' => $this->statusValue($event->event_type) ?? (string) $event->event_type,
                    'payload' => is_array($event->payload) ? $event->payload : [],
                    'recorded_at' => $this->timestamp($event->recorded_at),
                ])
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array{updates: list<array<string, mixed>>, commands: list<array<string, mixed>>}
     */
    private function durableUpdateFallbackRows(WorkflowRun $flow): array
    {
        try {
            $flow->loadMissing(['updates.command', 'updates.failure']);
        } catch (Throwable) {
            return ['updates' => [], 'commands' => []];
        }

        $updates = [];
        $commands = [];

        foreach ($flow->updates ?? [] as $update) {
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
        $argumentsAvailable = $this->hasDetailValue($update->arguments);
        $arguments = $argumentsAvailable ? $this->decodeUpdateArguments($update) : null;
        $resultAvailable = $this->hasDetailValue($update->result);
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
            return $this->historyExportDecodedPayload(
                $update->arguments,
                $this->stringValue($update->payload_codec),
            );
        }
    }

    private function decodeUpdateResult(WorkflowUpdate $update): mixed
    {
        try {
            return $update->updateResult();
        } catch (Throwable) {
            return $this->historyExportDecodedPayload(
                $update->result,
                $this->stringValue($update->payload_codec),
            );
        }
    }

    /**
     * @param array<string, mixed> $export
     * @return array<string, mixed>
     */
    private function withFallbackRedaction(
        array $export,
        WorkflowRun $flow,
        HistoryExportRedactor|callable|null $redactor,
    ): array {
        if ($redactor === null) {
            $export['redaction'] = [
                'applied' => false,
                'policy' => null,
                'paths' => [],
            ];

            return $export;
        }

        $paths = [];

        if (isset($export['payloads']['arguments']) && is_array($export['payloads']['arguments'])) {
            $this->redactFallbackField(
                $export['payloads']['arguments'],
                'data',
                $redactor,
                $this->fallbackRedactionContext($flow, 'payloads.arguments.data', 'workflow_payload', [
                    'field' => 'arguments',
                ]),
                $paths,
            );
        }

        if (isset($export['payloads']['output']) && is_array($export['payloads']['output'])) {
            $this->redactFallbackField(
                $export['payloads']['output'],
                'data',
                $redactor,
                $this->fallbackRedactionContext($flow, 'payloads.output.data', 'workflow_payload', [
                    'field' => 'output',
                ]),
                $paths,
            );
        }

        foreach (['history_events', 'timeline'] as $section) {
            if (! isset($export[$section]) || ! is_array($export[$section])) {
                continue;
            }

            foreach ($export[$section] as $index => &$event) {
                if (! is_array($event)) {
                    continue;
                }

                $this->redactFallbackField(
                    $event,
                    'payload',
                    $redactor,
                    $this->fallbackRedactionContext($flow, "{$section}.{$index}.payload", 'history_event', [
                        'history_event_id' => $event['id'] ?? null,
                        'history_event_type' => $event['type'] ?? null,
                        'sequence' => $event['sequence'] ?? null,
                    ]),
                    $paths,
                );
            }

            unset($event);
        }

        if (isset($export['updates']) && is_array($export['updates'])) {
            $this->redactFallbackUpdateRows($export['updates'], 'updates', $flow, $redactor, $paths);
        }

        if (isset($export['update_history_references']) && is_array($export['update_history_references'])) {
            $this->redactFallbackUpdateHistoryReferences(
                $export['update_history_references'],
                'update_history_references',
                $flow,
                $redactor,
                $paths,
            );
        }

        if (isset($export['update_diagnostics']['items']) && is_array($export['update_diagnostics']['items'])) {
            $this->redactFallbackUpdateRows(
                $export['update_diagnostics']['items'],
                'update_diagnostics.items',
                $flow,
                $redactor,
                $paths,
            );
        }

        $export['redaction'] = [
            'applied' => true,
            'policy' => $this->fallbackRedactorName($redactor),
            'paths' => array_values(array_unique($paths)),
        ];

        return $export;
    }

    /**
     * @param array<int|string, mixed> $updates
     * @param list<string> $paths
     */
    private function redactFallbackUpdateRows(
        array &$updates,
        string $pathPrefix,
        WorkflowRun $flow,
        HistoryExportRedactor|callable $redactor,
        array &$paths,
    ): void {
        foreach ($updates as $index => &$update) {
            if (! is_array($update)) {
                continue;
            }

            $metadata = [
                'update_id' => $update['id'] ?? $update['update_id'] ?? null,
                'workflow_command_id' => $update['workflow_command_id'] ?? $update['command_id'] ?? null,
                'update_name' => $update['update_name'] ?? $update['name'] ?? null,
                'update_status' => $update['status'] ?? $update['state'] ?? null,
                'update_outcome' => $update['outcome'] ?? null,
            ];

            foreach ([
                'arguments' => 'workflow_update_arguments',
                'payload' => 'workflow_update_payload',
                'result' => 'workflow_update_result',
                'error' => 'workflow_update_error',
            ] as $field => $category) {
                $this->redactFallbackField(
                    $update,
                    $field,
                    $redactor,
                    $this->fallbackRedactionContext($flow, "{$pathPrefix}.{$index}.{$field}", $category, array_merge(
                        $metadata,
                        ['field' => $field],
                    )),
                    $paths,
                );
            }

            if (isset($update['history_events']) && is_array($update['history_events'])) {
                $this->redactFallbackUpdateHistoryReferences(
                    $update['history_events'],
                    "{$pathPrefix}.{$index}.history_events",
                    $flow,
                    $redactor,
                    $paths,
                    $metadata,
                );
            }
        }

        unset($update);
    }

    /**
     * @param array<int|string, mixed> $references
     * @param array<string, mixed> $metadata
     * @param list<string> $paths
     */
    private function redactFallbackUpdateHistoryReferences(
        array &$references,
        string $pathPrefix,
        WorkflowRun $flow,
        HistoryExportRedactor|callable $redactor,
        array &$paths,
        array $metadata = [],
    ): void {
        foreach ($references as $index => &$reference) {
            if (! is_array($reference)) {
                continue;
            }

            $referenceMetadata = array_merge($metadata, [
                'history_event_id' => $reference['id'] ?? null,
                'history_event_type' => $reference['type'] ?? $reference['event_type'] ?? null,
                'sequence' => $reference['sequence'] ?? null,
                'update_id' => $reference['update_id'] ?? $metadata['update_id'] ?? null,
                'workflow_command_id' => $reference['workflow_command_id'] ?? $metadata['workflow_command_id'] ?? null,
                'update_name' => $reference['update_name'] ?? $metadata['update_name'] ?? null,
            ]);

            foreach ([
                'message' => 'workflow_update_history_reference',
                'rejection_reason' => 'workflow_update_history_reference',
            ] as $field => $category) {
                $this->redactFallbackField(
                    $reference,
                    $field,
                    $redactor,
                    $this->fallbackRedactionContext($flow, "{$pathPrefix}.{$index}.{$field}", $category, array_merge(
                        $referenceMetadata,
                        ['field' => $field],
                    )),
                    $paths,
                );
            }
        }

        unset($reference);
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $context
     * @param list<string> $paths
     */
    private function redactFallbackField(
        array &$target,
        string $field,
        HistoryExportRedactor|callable $redactor,
        array $context,
        array &$paths,
    ): void {
        if (! array_key_exists($field, $target)) {
            return;
        }

        $target[$field] = $this->redactFallbackValue($redactor, $target[$field], $context);
        $paths[] = (string) $context['path'];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function redactFallbackValue(
        HistoryExportRedactor|callable $redactor,
        mixed $value,
        array $context,
    ): mixed {
        try {
            return $redactor instanceof HistoryExportRedactor
                ? $redactor->redact($value, $context)
                : $redactor($value, $context);
        } catch (Throwable $exception) {
            throw new LogicException(
                sprintf(
                    'Workflow v2 history export redactor failed for [%s]: %s',
                    $context['path'] ?? 'unknown',
                    $exception->getMessage(),
                ),
                previous: $exception,
            );
        }
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function fallbackRedactionContext(
        WorkflowRun $flow,
        string $path,
        string $category,
        array $extra = [],
    ): array {
        return array_merge([
            'path' => $path,
            'category' => $category,
            'workflow_instance_id' => $flow->workflow_instance_id,
            'workflow_run_id' => $flow->id,
            'workflow_type' => $flow->workflow_type,
        ], $extra);
    }

    private function fallbackRedactorName(HistoryExportRedactor|callable $redactor): string
    {
        if ($redactor instanceof Closure) {
            return 'closure';
        }

        if ($redactor instanceof HistoryExportRedactor || is_object($redactor)) {
            return $redactor::class;
        }

        if (is_array($redactor)) {
            $target = $redactor[0] ?? null;
            $method = $redactor[1] ?? '__invoke';
            $targetName = is_object($target)
                ? $target::class
                : (is_string($target) ? $target : 'callable');

            return $targetName . '::' . (is_string($method) ? $method : '__invoke');
        }

        return is_string($redactor) ? $redactor : 'callable';
    }

    /**
     * @param array<string, mixed> $export
     * @return array<string, mixed>
     */
    private function fallbackIntegrity(array $export): array
    {
        $canonicalJson = json_encode($this->canonicalize($export), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signingKey = $this->fallbackSigningKey();

        return [
            'canonicalization' => 'json-recursive-ksort-v1',
            'checksum_algorithm' => 'sha256',
            'checksum' => hash('sha256', $canonicalJson),
            'signature_algorithm' => $signingKey === null ? null : 'hmac-sha256',
            'signature' => $signingKey === null ? null : hash_hmac('sha256', $canonicalJson, $signingKey),
            'key_id' => $signingKey === null ? null : $this->fallbackSigningKeyId(),
        ];
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function fallbackSigningKey(): ?string
    {
        $key = config('workflows.v2.history_export.signing_key');

        if (! is_string($key)) {
            return null;
        }

        $key = trim($key);

        return $key === '' ? null : $key;
    }

    private function fallbackSigningKeyId(): ?string
    {
        $keyId = config('workflows.v2.history_export.signing_key_id');

        if (! is_string($keyId)) {
            return null;
        }

        $keyId = trim($keyId);

        return $keyId === '' ? null : $keyId;
    }

    /**
     * @param array<string, mixed> $export
     * @return array<string, mixed>
     */
    private function annotateHistoryExport(array $export, ?string $namespace): array
    {
        if (isset($export['workflow']) && is_array($export['workflow'])) {
            $export['workflow']['namespace'] = $namespace;
        }

        $export['namespace'] = $namespace;

        if (! isset($export['update_diagnostics']) || ! is_array($export['update_diagnostics'])) {
            $export = $this->withHistoryExportUpdateDiagnostics($export);
        }

        $export = $this->withOperatorScope($export);

        return $export;
    }

    private function historyExportRedactor(): HistoryExportRedactor|callable|null
    {
        $configured = config('workflows.v2.history_export.redactor');

        if ($configured === null || $configured === false || $configured === '') {
            return null;
        }

        if (is_string($configured) && class_exists($configured)) {
            $configured = app($configured);
        }

        if ($configured instanceof HistoryExportRedactor || is_callable($configured)) {
            return $configured;
        }

        throw new InvalidArgumentException(
            'Configured workflow v2 history export redactor must implement '
            . HistoryExportRedactor::class
            . ' or be callable.',
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withOperatorScope(array $payload): array
    {
        $payload['operator_scope'] = OperatorScope::payload();

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function listItemView(WorkflowRunSummary $summary, bool $useDurableHistoryFallback = false): array
    {
        $item = RunListItemView::fromSummary($summary);
        $item = $this->withSummaryRunIdentity($item, $summary);
        $item['namespace'] ??= is_string($summary->run?->namespace ?? null)
            ? $summary->run->namespace
            : null;
        $compensationVisibility = CompensationVisibility::forRun(
            $summary->run,
            $useDurableHistoryFallback,
            $useDurableHistoryFallback,
        );
        $item['current_compensation_marker'] = $compensationVisibility['current_marker'];
        $item['compensation_visibility'] = $compensationVisibility;

        return $this->annotateListItem($item);
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function withSummaryRunIdentity(array $item, WorkflowRunSummary $summary): array
    {
        if (! $summary->run instanceof WorkflowRun) {
            return $item;
        }

        $item['id'] = $summary->run->id;
        $item['workflow_instance_id'] = $summary->run->workflow_instance_id;
        $item['instance_id'] = $summary->run->workflow_instance_id;
        $item['selected_run_id'] = $summary->run->id;
        $item['run_id'] = $summary->run->id;

        $runNamespace = $summary->run->namespace ?? null;

        if (is_string($runNamespace)) {
            $item['namespace'] = $runNamespace;
        }

        foreach (['compatibility', 'connection', 'queue'] as $field) {
            $value = $summary->run->{$field} ?? null;

            if ((! is_string($item[$field] ?? null) || trim((string) $item[$field]) === '')
                && is_string($value)
                && trim($value) !== '') {
                $item[$field] = trim($value);
            }
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function annotateListItem(array $item): array
    {
        $compensationVisibility = is_array($item['compensation_visibility'] ?? null)
            ? $item['compensation_visibility']
            : CompensationVisibility::fromActivities($item['activities'] ?? []);
        $currentCompensationMarker = $item['current_compensation_marker'] ?? null;

        $item['current_compensation_marker'] = is_string($currentCompensationMarker) && $currentCompensationMarker !== ''
            ? $currentCompensationMarker
            : (is_string($compensationVisibility['current_marker'] ?? null)
                ? $compensationVisibility['current_marker']
                : null);
        $item['compensation_visibility'] = $compensationVisibility;
        $item['history_budget_indicator'] = $this->historyBudgetIndicator($item);
        $item = CompatibilitySemantics::annotateListItem($item);
        $item['actionability'] = ActionabilityContract::annotateRun($item)['actionability'];
        $item['detail_action'] = $this->detailAction($item);

        return $item;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function detailAction(array $item): array
    {
        $historyEventCount = $this->intValue($item['history_event_count'] ?? null);
        $hasTypedHistory = $historyEventCount !== null && $historyEventCount > 0;

        return [
            'label' => 'Run Detail',
            'available' => true,
            'history_available' => $hasTypedHistory,
            'unavailable_label' => $hasTypedHistory ? null : 'No typed history',
            'description' => $hasTypedHistory
                ? 'Open the selected run detail, including history, tasks, commands, activities, metadata, and timeline data.'
                : 'Open the selected run detail. This row has no typed history events, so history-specific diagnostics may be unavailable.',
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function historyBudgetIndicator(array $item): array
    {
        $eventCount = $this->intValue($item['history_event_count'] ?? null);
        $sizeBytes = $this->intValue($item['history_size_bytes'] ?? null);
        $eventThreshold = HistoryBudget::eventThreshold();
        $sizeThreshold = HistoryBudget::sizeBytesThreshold();
        $recommended = ($item['continue_as_new_recommended'] ?? false) === true;

        $eventRatio = $eventCount !== null && $eventThreshold > 0
            ? $eventCount / $eventThreshold
            : null;
        $sizeRatio = $sizeBytes !== null && $sizeThreshold > 0
            ? $sizeBytes / $sizeThreshold
            : null;
        $ratio = max($eventRatio ?? 0.0, $sizeRatio ?? 0.0);
        $nearLimit = $ratio >= $this->historyBudgetWarningRatio();

        $status = $recommended
            ? 'recommended'
            : ($nearLimit ? 'near_limit' : 'ok');

        return [
            'status' => $status,
            'label' => match ($status) {
                'recommended' => 'Continue as new',
                'near_limit' => 'History near limit',
                default => 'History OK',
            },
            'description' => match ($status) {
                'recommended' => 'This run has crossed a configured history budget.',
                'near_limit' => 'This run is approaching a configured history budget.',
                default => 'This run is below the configured history budget warning threshold.',
            },
            'tone' => match ($status) {
                'recommended' => 'warning',
                'near_limit' => 'info',
                default => 'secondary',
            },
            'badge_visible' => $recommended || $nearLimit,
            'ratio' => round($ratio, 4),
            'event_ratio' => $eventRatio === null ? null : round($eventRatio, 4),
            'size_ratio' => $sizeRatio === null ? null : round($sizeRatio, 4),
            'history_event_threshold' => $eventThreshold,
            'history_size_bytes_threshold' => $sizeThreshold,
        ];
    }

    private function historyBudgetWarningRatio(): float
    {
        $configured = config('waterline.run_diagnostics.history_budget_warning_ratio', 0.8);

        return is_numeric($configured)
            ? max(0.0, min(1.0, (float) $configured))
            : 0.8;
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

    private function timestamp(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toIso8601String() : (is_string($value) ? $value : null);
    }

    private function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function hasDetailValue(mixed $value): bool
    {
        return ! ($value === null || $value === '' || $value === []);
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

    private function commandResponse($result)
    {
        $status = $result instanceof UpdateResult && $result->updateStatus() === 'accepted'
            ? 202
            : ($result->accepted() ? 200 : 409);

        return response()->json(
            $this->withOperatorScope(CommandResponse::payload($result)),
            $status,
        );
    }

    private function updateLookupResponse(UpdateResult $result, ?WorkflowRun $flow = null)
    {
        $payload = CommandResponse::payload($result);

        if ($flow instanceof WorkflowRun) {
            $diagnostic = $this->selectedRunUpdateDiagnostic(
                $flow,
                $this->stringValue($payload['update_id'] ?? null),
            );

            if ($diagnostic !== null) {
                $payload = array_merge($payload, $diagnostic, [
                    'update_id' => $payload['update_id'] ?? $diagnostic['id'] ?? null,
                    'update_status' => $payload['update_status'] ?? $diagnostic['status'] ?? null,
                    'command_response' => CommandResponse::payload($result),
                    'update' => $diagnostic,
                    'update_diagnostics' => [
                        'surface' => 'selected_run_update_lookup',
                        'scope' => 'selected_run',
                        'state' => $diagnostic['state_label'] ?? $diagnostic['status'] ?? null,
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
                    ],
                ]);
            }
        }

        return response()->json(
            $this->withOperatorScope($payload),
            $result->updateStatus() === 'accepted' ? 202 : 200,
        );
    }

    private function flowForUpdateResult(UpdateResult $result): ?WorkflowRun
    {
        $runId = $this->stringValue($result->runId());

        if ($runId === null) {
            return null;
        }

        try {
            $run = WorkflowRun::query()->find($runId);

            return $run instanceof WorkflowRun ? $run : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function selectedRunUpdateDiagnostic(WorkflowRun $flow, ?string $updateId): ?array
    {
        if ($updateId === null) {
            return null;
        }

        try {
            $detail = V2StoredWorkflowResource::make($flow)->resolve(request());
        } catch (Throwable) {
            return null;
        }

        $updates = is_array($detail['updates'] ?? null) ? $detail['updates'] : [];

        foreach ($updates as $update) {
            if (! is_array($update)) {
                continue;
            }

            if (! in_array($updateId, [
                $this->stringValue($update['id'] ?? null),
                $this->stringValue($update['update_id'] ?? null),
                $this->stringValue($update['command_id'] ?? null),
                $this->stringValue($update['workflow_command_id'] ?? null),
            ], true)) {
                continue;
            }

            $observerState = is_array($detail['observer_state'] ?? null) ? $detail['observer_state'] : [];
            $paths = is_array($observerState['paths'] ?? null) ? $observerState['paths'] : [];
            $historyExportPath = $this->stringValue($paths['selected_run_history_export'] ?? null);

            $update['surface'] = 'selected_run_update_lookup';
            $update['scope'] = 'selected_run';
            $update['selected_run_detail_path'] = $historyExportPath === null
                ? $this->stringValue($paths['selected_run_detail'] ?? null)
                : preg_replace('#/history-export$#', '', $historyExportPath);
            $update['selected_run_history_export_path'] = $historyExportPath;
            $update['selected_run_update_lookup_template'] = $this->stringValue(
                $paths['selected_run_update_lookup_template'] ?? null,
            );

            return $update;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $export
     * @return array<string, mixed>
     */
    private function withHistoryExportUpdateDiagnostics(array $export): array
    {
        $updates = $this->listOfMaps($export['updates'] ?? null);
        $commands = $this->rowsById($export['commands'] ?? null);
        [$historyByUpdateId, $historyByCommandId, $historyReferences] = $this->historyExportUpdateReferenceMaps($export);
        $diagnosticRows = [];

        foreach ($updates as $update) {
            $updateId = $this->stringValue($update['id'] ?? $update['update_id'] ?? null);
            $commandId = $this->stringValue($update['command_id'] ?? $update['workflow_command_id'] ?? null);
            $command = $commandId === null ? [] : ($commands[$commandId] ?? []);
            $references = $this->referencesForUpdate($updateId, $commandId, $historyByUpdateId, $historyByCommandId);

            $diagnosticRows[] = $this->historyExportUpdateRow($update, $command, $references);
        }

        $export['update_history_references'] = $historyReferences;
        $export['update_diagnostics'] = [
            'surface' => 'selected_run_history_export',
            'scope' => 'selected_run',
            'history_authority' => 'workflow_history_events',
            'update_count' => count($diagnosticRows),
            'history_event_count' => count($historyReferences),
            'state_counts' => $this->updateStateCounts($diagnosticRows),
            'items' => $diagnosticRows,
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

        return $export;
    }

    /**
     * @param array<string, mixed> $update
     * @param array<string, mixed> $command
     * @param list<array<string, mixed>> $historyReferences
     * @return array<string, mixed>
     */
    private function historyExportUpdateRow(array $update, array $command, array $historyReferences): array
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
                    ?? $this->firstReferenceString($historyReferences, $field);
            }
        }

        $status = $this->stringValue($update['status'] ?? null);
        $update['state'] = $status;
        $update['state_label'] = $status === 'rejected' ? 'refused' : $status;
        $update['refused'] = $status === 'rejected';
        $update['reason'] = $this->stringValue($update['reason'] ?? null)
            ?? $this->stringValue($update['rejection_reason'] ?? null)
            ?? $this->stringValue($update['failure_message'] ?? null)
            ?? $this->stringValue($update['outcome'] ?? null);

        $update = $this->withHistoryExportPayloadDetails($update, $command);
        $payload = $this->historyExportUpdatePayload($update, $command);
        $update['payload_available'] = $this->hasDetailValue($payload);
        $update['payload'] = $payload;
        $error = $this->hasDetailValue($update['error'] ?? null)
            ? $update['error']
            : $this->historyExportUpdateError($update);
        $update['error'] = $error;
        $update['error_available'] = $this->hasDetailValue($error);
        $update['history_events'] = $historyReferences;
        $update['history_event_ids'] = array_values(array_filter(array_map(
            fn (array $reference): ?string => $this->stringValue($reference['id'] ?? null),
            $historyReferences,
        )));
        $update['history_event_sequences'] = array_values(array_filter(array_map(
            fn (array $reference): ?int => $this->intValue($reference['sequence'] ?? null),
            $historyReferences,
        ), static fn (?int $sequence): bool => $sequence !== null));
        $update['history_event_types'] = array_values(array_unique(array_filter(array_map(
            fn (array $reference): ?string => $this->stringValue($reference['type'] ?? null),
            $historyReferences,
        ))));
        $update['request_identifiers'] = array_filter([
            'request_id' => $update['request_id'] ?? null,
            'correlation_id' => $update['correlation_id'] ?? null,
            'request_fingerprint' => $update['request_fingerprint'] ?? null,
            'command_id' => $update['command_id'] ?? null,
            'update_id' => $update['id'] ?? $update['update_id'] ?? null,
        ], fn (mixed $value): bool => $this->hasDetailValue($value));

        return $update;
    }

    /**
     * @param array<string, mixed> $update
     * @param array<string, mixed> $command
     */
    private function historyExportUpdatePayload(array $update, array $command): mixed
    {
        $codec = $this->stringValue($update['payload_codec'] ?? null)
            ?? $this->stringValue($command['payload_codec'] ?? null);

        if ($this->hasDetailValue($update['payload'] ?? null)) {
            return $this->historyExportDecodedPayload($update['payload'], $codec);
        }

        $commandPayload = $this->historyExportDecodedPayload(
            $command['payload'] ?? null,
            $this->stringValue($command['payload_codec'] ?? null),
        );
        $commandPayload = is_array($commandPayload) ? $commandPayload : [];
        $arguments = $this->hasDetailValue($update['arguments'] ?? null)
            ? $update['arguments']
            : ($commandPayload['arguments'] ?? null);
        $name = $update['name']
            ?? $update['update_name']
            ?? $command['target_name']
            ?? $commandPayload['name']
            ?? null;

        if (($update['arguments_available'] ?? false) === true
            || $this->hasDetailValue($arguments)
            || $this->hasDetailValue($name)) {
            $payload = array_filter([
                'name' => $name,
            ], fn (mixed $value): bool => $this->hasDetailValue($value));

            if (($update['arguments_available'] ?? false) === true
                || array_key_exists('arguments', $update)
                || array_key_exists('arguments', $commandPayload)
                || $this->hasDetailValue($arguments)) {
                $payload['arguments'] = $arguments ?? [];
            }

            return $payload;
        }

        return $this->hasDetailValue($commandPayload) ? $commandPayload : null;
    }

    /**
     * @param array<string, mixed> $update
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function withHistoryExportPayloadDetails(array $update, array $command): array
    {
        $codec = $this->stringValue($update['payload_codec'] ?? null)
            ?? $this->stringValue($command['payload_codec'] ?? null);

        foreach (['arguments', 'result'] as $field) {
            if (! array_key_exists($field, $update)) {
                continue;
            }

            $availableField = $field.'_available';
            $wasAvailable = ($update[$availableField] ?? false) === true
                || $this->hasDetailValue($update[$field]);
            $update[$field] = $this->historyExportDecodedPayload($update[$field], $codec);
            $update[$availableField] = $wasAvailable || $this->hasDetailValue($update[$field]);
        }

        return $update;
    }

    private function historyExportDecodedPayload(mixed $payload, ?string $codec): mixed
    {
        if (! $this->hasDetailValue($payload)) {
            return null;
        }

        if (! is_string($payload)) {
            return $payload;
        }

        $decoded = CommandPayloadPreview::previewWithCodec($payload, $codec);

        if (is_string($decoded) && hash_equals($payload, $decoded)) {
            return array_filter([
                'decode_status' => 'unavailable',
                'payload_codec' => $codec,
                'byte_length' => strlen($payload),
                'sha256' => hash('sha256', $payload),
            ], fn (mixed $value): bool => $this->hasDetailValue($value));
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $update
     * @return array<string, mixed>|null
     */
    private function historyExportUpdateError(array $update): ?array
    {
        $error = array_filter([
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
        ], fn (mixed $value): bool => $this->hasDetailValue($value));

        return $error === [] ? null : $error;
    }

    /**
     * @param array<string, mixed> $export
     * @return array{
     *     0: array<string, list<array<string, mixed>>>,
     *     1: array<string, list<array<string, mixed>>>,
     *     2: list<array<string, mixed>>
     * }
     */
    private function historyExportUpdateReferenceMaps(array $export): array
    {
        $byUpdateId = [];
        $byCommandId = [];
        $all = [];

        foreach ($this->listOfMaps($export['history_events'] ?? null) as $event) {
            $type = $this->stringValue($event['type'] ?? $event['event_type'] ?? null);

            if (! in_array($type, ['UpdateAccepted', 'UpdateRejected', 'UpdateApplied', 'UpdateCompleted'], true)) {
                continue;
            }

            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $reference = array_filter([
                'id' => $event['id'] ?? null,
                'sequence' => $this->intValue($event['sequence'] ?? null),
                'type' => $type,
                'event_type' => $type,
                'recorded_at' => $event['recorded_at'] ?? null,
                'workflow_command_id' => $event['workflow_command_id'] ?? $payload['workflow_command_id'] ?? null,
                'update_id' => $event['update_id'] ?? $payload['update_id'] ?? null,
                'update_name' => $event['update_name'] ?? $payload['update_name'] ?? null,
                'outcome' => $payload['outcome'] ?? null,
                'rejection_reason' => $payload['rejection_reason'] ?? null,
                'failure_id' => $payload['failure_id'] ?? null,
                'message' => $payload['message'] ?? null,
                'request_id' => $payload['request_id'] ?? null,
            ], fn (mixed $value): bool => $this->hasDetailValue($value));
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

    /**
     * @param mixed $rows
     * @return list<array<string, mixed>>
     */
    private function listOfMaps(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, static fn (mixed $row): bool => is_array($row)));
    }

    /**
     * @param mixed $rows
     * @return array<string, array<string, mixed>>
     */
    private function rowsById(mixed $rows): array
    {
        $indexed = [];

        foreach ($this->listOfMaps($rows) as $row) {
            $id = $this->stringValue($row['id'] ?? null);

            if ($id !== null) {
                $indexed[$id] = $row;
            }
        }

        return $indexed;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $historyByUpdateId
     * @param array<string, list<array<string, mixed>>> $historyByCommandId
     * @return list<array<string, mixed>>
     */
    private function referencesForUpdate(
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

            return $leftSequence === $rightSequence
                ? (string) ($left['id'] ?? '') <=> (string) ($right['id'] ?? '')
                : $leftSequence <=> $rightSequence;
        });

        return array_values($references);
    }

    /**
     * @param list<array<string, mixed>> $historyReferences
     */
    private function firstReferenceString(array $historyReferences, string $field): ?string
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
     * @param array<int|string, mixed> $arguments
     */
    private function queryResponse(
        V2WorkflowStub $workflow,
        string $query,
        array $arguments,
        string $targetScope,
        ?WorkflowRun $selectedRun = null,
    ) {
        $response = QueryResponse::execute($workflow, $query, $arguments, $targetScope);

        if ($selectedRun instanceof WorkflowRun) {
            $response = $this->withSelectedRunQueryDefinitionLimitation(
                $response,
                $selectedRun,
                $query,
                $targetScope,
            );
        }

        return response()->json($this->withOperatorScope($response['payload']), $response['status']);
    }

    /**
     * @param array{status: int, payload: array<string, mixed>} $response
     * @return array{status: int, payload: array<string, mixed>}
     */
    private function withSelectedRunQueryDefinitionLimitation(
        array $response,
        WorkflowRun $run,
        string $query,
        string $targetScope,
    ): array {
        if ((int) ($response['status'] ?? 0) !== 409) {
            return $response;
        }

        $payload = is_array($response['payload'] ?? null) ? $response['payload'] : [];

        if (
            $this->stringValue($payload['blocked_reason'] ?? null) !== null
            || $this->stringValue($payload['reason'] ?? null) !== null
        ) {
            return $response;
        }

        $message = $this->stringValue($payload['message'] ?? null);
        if ($message === null || ! str_contains($message, 'is not declared')) {
            return $response;
        }

        $target = $this->selectedRunDeclaredQueryTarget($run, $query);
        if ($target === null) {
            return $response;
        }

        $reason = WorkflowExecutionGate::BLOCKED_WORKFLOW_DEFINITION_UNAVAILABLE;
        $queryName = $target['name'];
        $declarationSource = $this->stringValue($target['source'] ?? null);
        $declarationLabel = $declarationSource === SelectedRunCommandContract::SOURCE_EXTERNAL_WORKER_REGISTRATION
            ? 'an active worker registration declares the query for this selected run'
            : 'the selected run declares the query';
        $limitationMessage = sprintf(
            'Workflow %s [%s] cannot execute query [%s] through Waterline because %s but the workflow definition that can execute it is not available in this Waterline process.',
            $run->id,
            $run->workflow_instance_id,
            $queryName,
            $declarationLabel,
        );

        $response['payload'] = array_merge($payload, [
            'query_name' => $queryName,
            'workflow_id' => $run->workflow_instance_id,
            'run_id' => $run->id,
            'target_scope' => $targetScope,
            'declaration_source' => $declarationSource,
            'blocked_reason' => $reason,
            'reason' => $reason,
            'message' => $limitationMessage,
            'limitation' => [
                'type' => 'waterline_selected_run_query_action_definition_unavailable',
                'reason' => $reason,
                'scope' => 'selected_run',
                'query_name' => $queryName,
                'declared_query' => true,
                'declaration_source' => $declarationSource,
                'message' => $limitationMessage,
            ],
        ]);

        return $response;
    }

    /**
     * @return array{name: string, parameters: list<array<string, mixed>>, has_contract: bool, source: string}|null
     */
    private function selectedRunDeclaredQueryTarget(WorkflowRun $run, string $query): ?array
    {
        return SelectedRunCommandContract::declaredQueryTarget($run, $query);
    }
}
