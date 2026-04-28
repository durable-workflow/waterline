<?php

namespace Waterline\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use LogicException;
use Workflow\V2\CommandContext;
use Workflow\V2\Contracts\OperatorObservabilityRepository;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Support\CommandResponse;
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
use Waterline\Support\VisibilityMetadataBridge;
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

        return response()->json(ActionabilityContract::annotateExport(
            $observability->runHistoryExport($flow),
        ));
    }

    public function historyExportInstance(
        string $instanceId,
        WorkflowRepositoryInterface $repository,
        OperatorObservabilityRepository $observability,
    ) {
        abort_unless($repository->engineSource() === 'v2', 404);

        $flow = $repository->findFlowSelection($instanceId);

        return response()->json(ActionabilityContract::annotateExport(
            $observability->runHistoryExport($flow),
        ));
    }

    public function historyExportSelection(
        string $instanceId,
        string $runId,
        WorkflowRepositoryInterface $repository,
        OperatorObservabilityRepository $observability,
    ) {
        abort_unless($repository->engineSource() === 'v2', 404);

        $flow = $repository->findFlowSelection($instanceId, $runId);

        return response()->json(ActionabilityContract::annotateExport(
            $observability->runHistoryExport($flow),
        ));
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
            CommandResponse::payload($result),
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
            CommandResponse::payload($result),
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
            CommandResponse::payload($result),
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
            CommandResponse::payload($result),
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
            CommandResponse::payload($result),
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
            CommandResponse::payload($result),
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
            CommandResponse::payload($result),
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
            CommandResponse::payload($result),
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
            CommandResponse::payload($result),
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
                ? $this->listItemView($item)
                : (is_array($item) ? $item : []),
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

        return response()->json($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function listItemView(WorkflowRunSummary $summary): array
    {
        $item = RunListItemView::fromSummary($summary);
        $item['search_attributes'] = VisibilityMetadataBridge::preserve(
            $item['search_attributes'] ?? null,
            $summary->getRawOriginal('search_attributes'),
        );
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
            CommandResponse::payload($result),
            $status,
        );
    }

    private function updateLookupResponse(UpdateResult $result)
    {
        return response()->json(
            CommandResponse::payload($result),
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

        return response()->json($response['payload'], $response['status']);
    }
}
