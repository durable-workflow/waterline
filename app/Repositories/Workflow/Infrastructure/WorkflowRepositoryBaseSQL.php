<?php

namespace Waterline\Repositories\Workflow\Infrastructure;

use Illuminate\Database\Eloquent\Builder;
use Waterline\Repositories\Workflow\Interfaces\WorkflowRepositoryInterface;

abstract class WorkflowRepositoryBaseSQL implements WorkflowRepositoryInterface
{
    protected $workflowModel;
    protected $workflowExceptionModel;

    public function __construct()
    {
        $this->workflowModel = config('workflows.stored_workflow_model', \Workflow\Models\StoredWorkflow::class);
        $this->workflowExceptionModel = config('workflows.stored_workflow_exception_model', \Workflow\Models\StoredWorkflowException::class);
    }

    public function engineSource(): string
    {
        return 'v1';
    }

    public function completedFlows()
    {
        return $this->orderedFlowsQuery()->whereIn('status', [
            'completed',
            'continued',
        ])->paginate(50);
    }

    public function failedFlows()
    {
        return $this->orderedFlowsQuery()
            ->whereStatus('failed')
            ->paginate(50);
    }

    public function cancelledFlows()
    {
        return $this->orderedFlowsQuery()
            ->whereRaw('1 = 0')
            ->paginate(50);
    }

    public function terminatedFlows()
    {
        return $this->orderedFlowsQuery()
            ->whereRaw('1 = 0')
            ->paginate(50);
    }

    public function runningFlows()
    {
        return $this->orderedFlowsQuery()->whereIn('status', [
            'created',
            'pending',
            'running',
            'waiting',
        ])->paginate(50);
    }

    public function findFlow(string $id)
    {
        return $this->workflowModel::with([
            'continuedWorkflows',
            'exceptions',
            'logs',
            'parents',
        ])->findOrFail($id);
    }

    public function findFlowSelection(string $instanceId, ?string $runId = null)
    {
        return $this->findFlow($runId ?? $instanceId);
    }

    public function flowsPastHour(): int
    {
        return $this->workflowModel::where('updated_at', '>=', now()->subHour())->count();
    }

    public function exceptionsPastHour(): int
    {
        return $this->workflowExceptionModel::where('created_at', '>=', now()->subHour())->count();
    }

    public function failedFlowsPastWeek(): int
    {
        return $this->workflowModel::where('status', 'failed')
            ->where('updated_at', '>=', now()->subDays(7))
            ->count();
    }

    public function maxWaitTimeWorkflow()
    {
        return $this->workflowModel::where('status', 'pending')
            ->orderBy('updated_at')
            ->first();
    }

    public function maxExceptionsWorkflow()
    {
        return $this->workflowModel::withCount('exceptions')
            ->has('exceptions')
            ->orderByDesc('exceptions_count')
            ->orderByDesc('updated_at')
            ->first();
    }

    public function totalFlows(): int
    {
        return $this->workflowModel::count();
    }

    protected function orderedFlowsQuery(): Builder
    {
        return $this->workflowModel::query()
            ->orderByDesc(config('waterline.workflow_sort_column', 'id'));
    }
}
