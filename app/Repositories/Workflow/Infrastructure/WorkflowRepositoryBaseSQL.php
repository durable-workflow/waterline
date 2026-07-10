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
        return $this->bucketFlows('completed');
    }

    public function failedFlows()
    {
        return $this->bucketFlows('failed');
    }

    public function cancelledFlows()
    {
        return $this->bucketFlows('cancelled');
    }

    public function terminatedFlows()
    {
        return $this->bucketFlows('terminated');
    }

    public function runningFlows()
    {
        return $this->bucketFlows('running');
    }

    public function bucketFlows(
        string $bucket,
        int $perPage = 50,
        ?int $page = null,
        bool $hybridOrder = false,
    )
    {
        $query = $hybridOrder
            ? $this->hybridOrderedFlowsQuery()
            : $this->orderedFlowsQuery();

        match ($bucket) {
            'completed' => $query->whereIn('status', ['completed', 'continued']),
            'failed' => $query->whereStatus('failed'),
            'running' => $query->whereIn('status', ['created', 'pending', 'running', 'waiting']),
            default => $query->whereRaw('1 = 0'),
        };

        return $query->paginate(max(1, $perPage), ['*'], 'page', $page);
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

    public function dashboardStats(): array
    {
        $flowsPastHour = $this->flowsPastHour();

        return [
            'flows' => $this->totalFlows(),
            'flows_per_minute' => $flowsPastHour / 60,
            'flows_past_hour' => $flowsPastHour,
            'exceptions_past_hour' => $this->exceptionsPastHour(),
            'failed_flows_past_week' => $this->failedFlowsPastWeek(),
            'max_wait_time_workflow' => $this->maxWaitTimeWorkflow(),
            'max_duration_workflow' => $this->maxDurationWorkflow(),
            'max_exceptions_workflow' => $this->maxExceptionsWorkflow(),
            'operator_metrics' => $this->operatorMetrics(),
        ];
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

    public function operatorMetrics()
    {
        return null;
    }

    protected function orderedFlowsQuery(): Builder
    {
        $direction = request()->query('sort_direction', request()->query('sort'));
        $direction = is_string($direction) && strtolower(trim($direction)) === 'asc'
            ? 'asc'
            : 'desc';

        return $this->workflowModel::query()
            ->orderBy(config('waterline.workflow_sort_column', 'id'), $direction);
    }

    protected function hybridOrderedFlowsQuery(): Builder
    {
        $direction = request()->query('sort_direction', request()->query('sort'));
        $direction = is_string($direction) && strtolower(trim($direction)) === 'asc'
            ? 'asc'
            : 'desc';

        return $this->workflowModel::query()
            ->orderBy('updated_at', $direction)
            ->orderBy('id', $direction);
    }
}
