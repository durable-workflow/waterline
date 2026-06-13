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
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Support\CommandResponse;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\HistoryBudget;
use Workflow\V2\Support\QueryResponse;
use Workflow\V2\Support\RunListItemView;
use Workflow\V2\Support\UpdateWaitPolicy;
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

        return $this->queryResponse(
            V2WorkflowStub::loadSelection($instanceId, $runId, $this->commandNamespace()),
            $query,
            $this->commandArguments($request),
            'run',
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

        return $this->updateLookupResponse($result);
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

        return $this->updateLookupResponse($result);
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

        return $this->updateLookupResponse($result);
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
            'commands' => [],
            'signals' => [],
            'updates' => [],
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

        $export['redaction'] = [
            'applied' => true,
            'policy' => $this->fallbackRedactorName($redactor),
            'paths' => array_values(array_unique($paths)),
        ];

        return $export;
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

    private function updateLookupResponse(UpdateResult $result)
    {
        return response()->json(
            $this->withOperatorScope(CommandResponse::payload($result)),
            $result->updateStatus() === 'accepted' ? 202 : 200,
        );
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    private function queryResponse(
        V2WorkflowStub $workflow,
        string $query,
        array $arguments,
        string $targetScope,
    ) {
        $response = QueryResponse::execute($workflow, $query, $arguments, $targetScope);

        return response()->json($this->withOperatorScope($response['payload']), $response['status']);
    }
}
