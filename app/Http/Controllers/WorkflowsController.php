<?php

namespace Waterline\Http\Controllers;

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
}
