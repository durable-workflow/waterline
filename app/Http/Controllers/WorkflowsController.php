<?php

namespace Waterline\Http\Controllers;

use Illuminate\Http\Request;
use Workflow\V2\CommandContext;
use Workflow\V2\Support\CommandResponse;
use Workflow\V2\WorkflowStub as V2WorkflowStub;
use Waterline\Http\Resources\StoredWorkflowResource;
use Waterline\Http\Resources\V2StoredWorkflowResource;
use Waterline\Repositories\Workflow\Interfaces\WorkflowRepositoryInterface;

class WorkflowsController extends Controller
{
    public function completed(WorkflowRepositoryInterface $repository)
    {
        return $repository->completedFlows();
    }

    public function failed(WorkflowRepositoryInterface $repository)
    {
        return $repository->failedFlows();
    }

    public function running(WorkflowRepositoryInterface $repository)
    {
        return $repository->runningFlows();
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
        $result = V2WorkflowStub::loadRun($flow->id)
            ->withCommandContext(CommandContext::waterline(request()))
            ->attemptUpdateWithArguments($update, $this->commandArguments($request));

        return $this->commandResponse($result);
    }

    public function updateInstance(
        string $instanceId,
        string $update,
        Request $request,
        WorkflowRepositoryInterface $repository,
    ) {
        abort_unless($repository->engineSource() === 'v2', 404);

        $result = V2WorkflowStub::load($instanceId)
            ->withCommandContext(CommandContext::waterline(request()))
            ->attemptUpdateWithArguments($update, $this->commandArguments($request));

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

        $result = V2WorkflowStub::loadSelection($instanceId, $runId)
            ->withCommandContext(CommandContext::waterline(request()))
            ->attemptUpdateWithArguments($update, $this->commandArguments($request));

        return $this->commandResponse($result);
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

    private function commandResponse($result)
    {
        return response()->json(
            CommandResponse::payload($result),
            $result->accepted() ? 200 : 409,
        );
    }
}
