<?php

namespace Waterline\Http\Controllers;

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

    public function cancel(string $id, WorkflowRepositoryInterface $repository)
    {
        abort_unless($repository->engineSource() === 'v2', 404);

        $flow = $repository->findFlow($id);
        $result = V2WorkflowStub::loadRun($flow->id)->attemptCancel();

        return response()->json(
            CommandResponse::payload($result),
            $result->accepted() ? 200 : 409,
        );
    }

    public function terminate(string $id, WorkflowRepositoryInterface $repository)
    {
        abort_unless($repository->engineSource() === 'v2', 404);

        $flow = $repository->findFlow($id);
        $result = V2WorkflowStub::loadRun($flow->id)->attemptTerminate();

        return response()->json(
            CommandResponse::payload($result),
            $result->accepted() ? 200 : 409,
        );
    }
}
