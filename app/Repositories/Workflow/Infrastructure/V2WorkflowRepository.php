<?php

namespace Waterline\Repositories\Workflow\Infrastructure;

use Workflow\V2\Contracts\OperatorObservabilityRepository;
use Workflow\V2\Support\RunSummarySortKey;
use Workflow\V2\Support\SelectedRunLocator;
use Workflow\V2\Support\VisibilityFilters;
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
        return $this->statusFlows('completed');
    }

    public function failedFlows()
    {
        return $this->statusFlows('failed');
    }

    public function cancelledFlows()
    {
        return $this->statusFlows('cancelled');
    }

    public function terminatedFlows()
    {
        return $this->statusFlows('terminated');
    }

    public function runningFlows()
    {
        return $this->orderedRunsQuery('running')
            ->where('status_bucket', 'running')
            ->paginate(50);
    }

    public function findFlow(string $id)
    {
        return SelectedRunLocator::forIdOrFail($id, $this->detailRelations(), $this->namespace());
    }

    public function findFlowSelection(string $instanceId, ?string $runId = null)
    {
        return SelectedRunLocator::forInstanceIdOrFail($instanceId, $runId, $this->detailRelations(), $this->namespace());
    }

    public function dashboardStats(): array
    {
        return app(OperatorObservabilityRepository::class)->dashboardSummary();
    }

    public function flowsPastHour(): int
    {
        $cutoff = now()->subHour();

        return $this->runSummaryModel::where(static function ($query) use ($cutoff): void {
            $query->where('sort_timestamp', '>=', $cutoff)
                ->orWhere(static function ($fallback) use ($cutoff): void {
                    $fallback->whereNull('sort_timestamp')
                        ->where('created_at', '>=', $cutoff);
                });
        })->count();
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
            ->orderBy('sort_timestamp')
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }

    public function maxDurationWorkflow()
    {
        return $this->runSummaryModel::whereNotNull('duration_ms')
            ->orderByDesc('duration_ms')
            ->orderByDesc('sort_timestamp')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    public function maxExceptionsWorkflow()
    {
        return $this->runSummaryModel::where('exception_count', '>', 0)
            ->orderByDesc('exception_count')
            ->orderByDesc('sort_timestamp')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    public function totalFlows(): int
    {
        return $this->runSummaryModel::count();
    }

    public function operatorMetrics()
    {
        return app(OperatorObservabilityRepository::class)->metrics();
    }

    protected function orderedRunsQuery(?string $bucket = null)
    {
        return RunSummarySortKey::applyDescending($this->filteredRunsQuery($bucket));
    }

    protected function statusFlows(string $status)
    {
        return $this->orderedRunsQuery($status)
            ->where('status', $status)
            ->paginate(50);
    }

    protected function filteredRunsQuery(?string $bucket = null)
    {
        $query = $this->runSummaryModel::query();
        $context = V2VisibilityFilterContext::resolve(request(), $bucket);

        VisibilityFilters::apply(
            $query,
            $context['applied_filters'],
        );

        return $query;
    }

    protected function detailRelations(): array
    {
        return [
            'summary',
            'commands',
            'signals.command',
            'updates.command',
            'updates.failure',
            'tasks',
            'activityExecutions',
            'timers',
            'failures',
            'historyEvents',
            'parentLinks.parentRun.summary',
            'childLinks.childRun.summary',
            'childLinks.childRun.historyEvents',
            'instance.runs.summary',
        ];
    }

    private function namespace(): ?string
    {
        $namespace = config('waterline.namespace');

        return is_string($namespace) && trim($namespace) !== '' ? trim($namespace) : null;
    }
}
