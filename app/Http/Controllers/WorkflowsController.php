<?php

namespace Waterline\Http\Controllers;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use LogicException;
use Workflow\V2\CommandContext;
use Workflow\V2\Contracts\OperatorObservabilityRepository;
use Workflow\V2\Support\CommandResponse;
use Workflow\V2\Support\QueryResponse;
use Workflow\V2\Support\UpdateWaitPolicy;
use Workflow\V2\Support\VisibilityFilters;
use Workflow\V2\UpdateResult;
use Workflow\V2\WorkflowStub as V2WorkflowStub;
use Waterline\Http\Resources\StoredWorkflowResource;
use Waterline\Http\Resources\V2StoredWorkflowResource;
use Waterline\Repositories\Workflow\Infrastructure\V2VisibilityFilterContext;
use Waterline\Repositories\Workflow\Interfaces\WorkflowRepositoryInterface;

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
        $flow = $repository->findFlowSelection($instanceId, $runId);

        return $repository->engineSource() === 'v2'
            ? V2StoredWorkflowResource::make($flow)
            : StoredWorkflowResource::make($flow);
    }

    public function historyExport(
        string $id,
        WorkflowRepositoryInterface $repository,
        OperatorObservabilityRepository $observability,
    )
    {
        abort_unless($repository->engineSource() === 'v2', 404);

        $flow = $repository->findFlow($id);

        return response()->json($observability->runHistoryExport($flow));
    }

    public function historyExportSelection(
        string $instanceId,
        string $runId,
        WorkflowRepositoryInterface $repository,
        OperatorObservabilityRepository $observability,
    ) {
        abort_unless($repository->engineSource() === 'v2', 404);

        $flow = $repository->findFlowSelection($instanceId, $runId);

        return response()->json($observability->runHistoryExport($flow));
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
            V2WorkflowStub::loadRun($flow->id),
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
            V2WorkflowStub::load($instanceId),
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
            V2WorkflowStub::loadSelection($instanceId, $runId),
            $query,
            $this->commandArguments($request),
            'run',
        );
    }

    public function cancel(string $id, WorkflowRepositoryInterface $repository)
    {
        abort_unless($repository->engineSource() === 'v2', 404);

        $flow = $repository->findFlow($id);
        $result = V2WorkflowStub::loadRun($flow->id)
            ->withCommandContext(CommandContext::waterline(request()))
            ->attemptCancel();

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
        $result = V2WorkflowStub::loadRun($flow->id)
            ->withCommandContext(CommandContext::waterline(request()))
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

        $result = V2WorkflowStub::load($instanceId)
            ->withCommandContext(CommandContext::waterline(request()))
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

        $result = V2WorkflowStub::loadSelection($instanceId, $runId)
            ->withCommandContext(CommandContext::waterline(request()))
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
            V2WorkflowStub::loadRun($flow->id)
                ->withCommandContext(CommandContext::waterline(request())),
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
            V2WorkflowStub::load($instanceId)
                ->withCommandContext(CommandContext::waterline(request())),
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
            V2WorkflowStub::loadSelection($instanceId, $runId)
                ->withCommandContext(CommandContext::waterline(request())),
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
            $result = V2WorkflowStub::loadRun($flow->id)->inspectUpdate($updateId);
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
            $result = V2WorkflowStub::load($instanceId)->inspectUpdate($updateId);
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
            $result = V2WorkflowStub::loadSelection($instanceId, $runId)->inspectUpdate($updateId);
        } catch (LogicException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 404);
        }

        return $this->updateLookupResponse($result);
    }

    public function cancelInstance(string $instanceId, WorkflowRepositoryInterface $repository)
    {
        abort_unless($repository->engineSource() === 'v2', 404);

        $result = V2WorkflowStub::load($instanceId)
            ->withCommandContext(CommandContext::waterline(request()))
            ->attemptCancel();

        return response()->json(
            CommandResponse::payload($result),
            $result->accepted() ? 200 : 409,
        );
    }

    public function cancelSelection(string $instanceId, string $runId, WorkflowRepositoryInterface $repository)
    {
        abort_unless($repository->engineSource() === 'v2', 404);

        $result = V2WorkflowStub::loadSelection($instanceId, $runId)
            ->withCommandContext(CommandContext::waterline(request()))
            ->attemptCancel();

        return response()->json(
            CommandResponse::payload($result),
            $result->accepted() ? 200 : 409,
        );
    }

    public function repair(string $id, WorkflowRepositoryInterface $repository)
    {
        abort_unless($repository->engineSource() === 'v2', 404);

        $flow = $repository->findFlow($id);
        $result = V2WorkflowStub::loadRun($flow->id)
            ->withCommandContext(CommandContext::waterline(request()))
            ->attemptRepair();

        return response()->json(
            CommandResponse::payload($result),
            $result->accepted() ? 200 : 409,
        );
    }

    public function repairInstance(string $instanceId, WorkflowRepositoryInterface $repository)
    {
        abort_unless($repository->engineSource() === 'v2', 404);

        $result = V2WorkflowStub::load($instanceId)
            ->withCommandContext(CommandContext::waterline(request()))
            ->attemptRepair();

        return response()->json(
            CommandResponse::payload($result),
            $result->accepted() ? 200 : 409,
        );
    }

    public function repairSelection(string $instanceId, string $runId, WorkflowRepositoryInterface $repository)
    {
        abort_unless($repository->engineSource() === 'v2', 404);

        $result = V2WorkflowStub::loadSelection($instanceId, $runId)
            ->withCommandContext(CommandContext::waterline(request()))
            ->attemptRepair();

        return response()->json(
            CommandResponse::payload($result),
            $result->accepted() ? 200 : 409,
        );
    }

    public function terminate(string $id, WorkflowRepositoryInterface $repository)
    {
        abort_unless($repository->engineSource() === 'v2', 404);

        $flow = $repository->findFlow($id);
        $result = V2WorkflowStub::loadRun($flow->id)
            ->withCommandContext(CommandContext::waterline(request()))
            ->attemptTerminate();

        return response()->json(
            CommandResponse::payload($result),
            $result->accepted() ? 200 : 409,
        );
    }

    public function terminateInstance(string $instanceId, WorkflowRepositoryInterface $repository)
    {
        abort_unless($repository->engineSource() === 'v2', 404);

        $result = V2WorkflowStub::load($instanceId)
            ->withCommandContext(CommandContext::waterline(request()))
            ->attemptTerminate();

        return response()->json(
            CommandResponse::payload($result),
            $result->accepted() ? 200 : 409,
        );
    }

    public function terminateSelection(string $instanceId, string $runId, WorkflowRepositoryInterface $repository)
    {
        abort_unless($repository->engineSource() === 'v2', 404);

        $result = V2WorkflowStub::loadSelection($instanceId, $runId)
            ->withCommandContext(CommandContext::waterline(request()))
            ->attemptTerminate();

        return response()->json(
            CommandResponse::payload($result),
            $result->accepted() ? 200 : 409,
        );
    }

    public function archive(string $id, Request $request, WorkflowRepositoryInterface $repository)
    {
        abort_unless($repository->engineSource() === 'v2', 404);

        $flow = $repository->findFlow($id);
        $result = V2WorkflowStub::loadRun($flow->id)
            ->withCommandContext(CommandContext::waterline(request()))
            ->attemptArchive($this->archiveReason($request));

        return $this->commandResponse($result);
    }

    public function archiveInstance(string $instanceId, Request $request, WorkflowRepositoryInterface $repository)
    {
        abort_unless($repository->engineSource() === 'v2', 404);

        $result = V2WorkflowStub::load($instanceId)
            ->withCommandContext(CommandContext::waterline(request()))
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

        $result = V2WorkflowStub::loadSelection($instanceId, $runId)
            ->withCommandContext(CommandContext::waterline(request()))
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

    private function shouldSubmitUpdate(Request $request): bool
    {
        return $request->input('wait_for') === 'accepted';
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
        $payload['visibility_filters'] = [
            'version' => VisibilityFilters::VERSION,
            'bucket' => $bucket,
            'definition' => $context['definition'],
            'applied' => $context['applied_filters'],
            'saved_view' => $context['saved_view'],
        ];

        return response()->json($payload);
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
