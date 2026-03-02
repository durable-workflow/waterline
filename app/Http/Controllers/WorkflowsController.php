<?php

namespace Waterline\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Waterline\Http\Resources\StoredWorkflowResource;
use Workflow\Models\StoredWorkflow;

class WorkflowsController extends Controller
{
    public function completed() {
        return $this->orderedFlowsQuery()->whereIn('status', [
                'completed',
                'continued',
            ])
            ->paginate(50);
    }

    public function failed() {
        return $this->orderedFlowsQuery()->whereStatus('failed')
            ->paginate(50);
    }

    public function running() {
        return $this->orderedFlowsQuery()->whereIn('status', [
                'created',
                'pending',
                'running',
                'waiting',
            ])
            ->paginate(50);
    }

    public function show($id) {
        $flow = config('workflows.stored_workflow_model', StoredWorkflow::class)::with([
            'continuedWorkflows',
            'exceptions',
            'logs',
            'parents'
        ])->find($id);

        return StoredWorkflowResource::make($flow);
    }

    protected function orderedFlowsQuery(): Builder
    {
        return config('workflows.stored_workflow_model', StoredWorkflow::class)::query()
            ->orderByDesc(config('waterline.workflow_sort_column', 'id'));
    }
}
