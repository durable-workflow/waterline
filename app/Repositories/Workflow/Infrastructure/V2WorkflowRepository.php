<?php

namespace Waterline\Repositories\Workflow\Infrastructure;

use Waterline\Repositories\Workflow\Interfaces\WorkflowRepositoryInterface;

class V2WorkflowRepository implements WorkflowRepositoryInterface
{
    protected $instanceModel;
    protected $runModel;
    protected $runSummaryModel;
    protected $failureModel;

    public function __construct()
    {
        $this->instanceModel = config('workflows.v2.instance_model', \Workflow\V2\Models\WorkflowInstance::class);
        $this->runModel = config('workflows.v2.run_model', \Workflow\V2\Models\WorkflowRun::class);
        $this->runSummaryModel = config('workflows.v2.run_summary_model', \Workflow\V2\Models\WorkflowRunSummary::class);
        $this->failureModel = config('workflows.v2.failure_model', \Workflow\V2\Models\WorkflowFailure::class);
    }

    public function engineSource(): string
    {
        return 'v2';
    }

    public function completedFlows()
    {
        return $this->orderedRunsQuery()
            ->where('status_bucket', 'completed')
            ->paginate(50);
    }

    public function failedFlows()
    {
        return $this->orderedRunsQuery()
            ->where('status_bucket', 'failed')
            ->paginate(50);
    }

    public function runningFlows()
    {
        return $this->orderedRunsQuery()
            ->where('status_bucket', 'running')
            ->paginate(50);
    }

    public function findFlow(string $id)
    {
        $relations = ['summary', 'activityExecutions', 'failures', 'instance.currentRun.summary'];

        $run = $this->runModel::query()
            ->with($relations)
            ->find($id);

        if ($run !== null) {
            return $run;
        }

        $instance = $this->instanceModel::query()
            ->with('currentRun.summary')
            ->findOrFail($id);

        abort_if($instance->current_run_id === null, 404);

        return $this->runModel::query()
            ->with($relations)
            ->findOrFail($instance->current_run_id);
    }

    public function flowsPastHour(): int
    {
        return $this->runSummaryModel::where('created_at', '>=', now()->subHour())->count();
    }

    public function exceptionsPastHour(): int
    {
        return $this->failureModel::where('created_at', '>=', now()->subHour())->count();
    }

    public function failedFlowsPastWeek(): int
    {
        return $this->runSummaryModel::where('status', 'failed')
            ->where('updated_at', '>=', now()->subDays(7))
            ->count();
    }

    public function maxWaitTimeWorkflow()
    {
        return $this->runSummaryModel::where('status_bucket', 'running')
            ->whereNotNull('wait_started_at')
            ->orderBy('wait_started_at')
            ->first();
    }

    public function maxDurationWorkflow()
    {
        return $this->runSummaryModel::whereNotNull('duration_ms')
            ->orderByDesc('duration_ms')
            ->orderByDesc('updated_at')
            ->first();
    }

    public function maxExceptionsWorkflow()
    {
        return $this->runSummaryModel::where('exception_count', '>', 0)
            ->orderByDesc('exception_count')
            ->orderByDesc('updated_at')
            ->first();
    }

    public function totalFlows(): int
    {
        return $this->runSummaryModel::count();
    }

    protected function orderedRunsQuery()
    {
        return $this->runSummaryModel::query()
            ->orderByDesc(config('waterline.workflow_sort_column', 'id'));
    }
}
