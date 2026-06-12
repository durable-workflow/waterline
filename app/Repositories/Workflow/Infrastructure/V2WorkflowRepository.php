<?php

namespace Waterline\Repositories\Workflow\Infrastructure;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Waterline\Repositories\Workflow\Interfaces\WorkflowRepositoryInterface;
use Waterline\Support\ActionabilityVisibilityFilters;
use Workflow\V2\Contracts\OperatorObservabilityRepository;
use Workflow\V2\Support\RunSummarySortKey;
use Workflow\V2\Support\SelectedRunLocator;
use Workflow\V2\Support\WorkerCompatibility;
use Workflow\V2\Support\WorkerCompatibilityFleet;

class V2WorkflowRepository implements WorkflowRepositoryInterface
{
    protected $instanceModel;
    protected $runModel;
    protected $runSummaryModel;
    protected $failureModel;

    /**
     * @var array<string, bool>
     */
    private array $databaseColumnExistsCache = [];

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
        try {
            return SelectedRunLocator::forInstanceIdOrFail(
                $instanceId,
                $runId,
                $this->detailRelations(),
                $this->namespace(),
            );
        } catch (ModelNotFoundException $exception) {
            if ($runId === null || $this->namespace() === null) {
                throw $exception;
            }

            return $this->findFlowSelectionByRunScope($instanceId, $runId, $exception);
        }
    }

    public function dashboardStats(): array
    {
        $namespace = $this->namespace();
        $now = now();
        $summary = app(OperatorObservabilityRepository::class)->dashboardSummary($now, $namespace);
        $summary['operator_metrics'] = $this->annotateOperatorMetrics(
            $summary['operator_metrics'] ?? null,
            $namespace,
        );

        return $summary;
    }

    public function flowsPastHour(): int
    {
        $cutoff = now()->subHour();

        return $this->runSummaryQuery()->where(static function ($query) use ($cutoff): void {
            $query->where('sort_timestamp', '>=', $cutoff)
                ->orWhere(static function ($fallback) use ($cutoff): void {
                    $fallback->whereNull('sort_timestamp')
                        ->where('created_at', '>=', $cutoff);
                });
        })->count();
    }

    public function exceptionsPastHour(): int
    {
        return $this->failureQuery()
            ->where('created_at', '>=', now()->subHour())
            ->count();
    }

    public function failedFlowsPastWeek(): int
    {
        return $this->runSummaryQuery()
            ->where('status', 'failed')
            ->where('updated_at', '>=', now()->subDays(7))
            ->count();
    }

    public function maxWaitTimeWorkflow()
    {
        return $this->runSummaryQuery()
            ->where('status_bucket', 'running')
            ->whereNotNull('wait_started_at')
            ->orderBy('wait_started_at')
            ->orderBy('sort_timestamp')
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }

    public function maxDurationWorkflow()
    {
        return $this->runSummaryQuery()
            ->whereNotNull('duration_ms')
            ->orderByDesc('duration_ms')
            ->orderByDesc('sort_timestamp')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    public function maxExceptionsWorkflow()
    {
        return $this->runSummaryQuery()
            ->where('exception_count', '>', 0)
            ->orderByDesc('exception_count')
            ->orderByDesc('sort_timestamp')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    public function totalFlows(): int
    {
        return $this->runSummaryQuery()->count();
    }

    public function operatorMetrics()
    {
        $namespace = $this->namespace();
        $metrics = app(OperatorObservabilityRepository::class)->metrics(null, $namespace);
        $metrics = $this->annotateOperatorMetrics($metrics, $namespace);

        return $metrics;
    }

    /**
     * Keep Waterline compatible with older workflow alphas that omit
     * namespace-scoped worker snapshots or the expanded matching-role
     * routing contract on the operator metrics surface.
     *
     * @param mixed $metrics
     * @return mixed
     */
    private function annotateOperatorMetrics(mixed $metrics, ?string $namespace): mixed
    {
        if (! is_array($metrics)) {
            return $metrics;
        }

        $metrics['workers'] = $this->scopedWorkerMetrics($metrics['workers'] ?? null, $namespace);

        return $metrics;
    }

    /**
     * Keep Waterline compatible with older workflow alphas that still expose
     * a fleet-global workers snapshot even when the rest of the operator
     * metrics payload is namespace-scoped.
     *
     * @param mixed $workers
     * @return mixed
     */
    private function scopedWorkerMetrics(mixed $workers, ?string $namespace): mixed
    {
        if (! is_array($workers) || $namespace === null) {
            return $workers;
        }

        if (($workers['compatibility_namespace'] ?? null) === $namespace) {
            return $workers;
        }

        $required = WorkerCompatibility::current();
        $snapshots = WorkerCompatibilityFleet::detailsForNamespace($namespace, $required);
        $workerIds = [];
        $supportingWorkerIds = [];
        $fleet = [];

        foreach ($snapshots as $snapshot) {
            $workerId = is_string($snapshot['worker_id'] ?? null)
                ? $snapshot['worker_id']
                : null;

            if ($workerId === null) {
                continue;
            }

            $workerIds[$workerId] = true;

            if (($snapshot['supports_required'] ?? false) === true) {
                $supportingWorkerIds[$workerId] = true;
            }

            $fleet[] = $this->fleetEntry($snapshot);
        }

        $workers['compatibility_namespace'] = $namespace;
        $workers['required_compatibility'] = $required;
        $workers['active_workers'] = count($workerIds);
        $workers['active_worker_scopes'] = count($snapshots);
        $workers['active_workers_supporting_required'] = count($supportingWorkerIds);
        $workers['fleet'] = $fleet;

        return $workers;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function fleetEntry(array $snapshot): array
    {
        $recordedAt = $snapshot['recorded_at'] ?? null;
        $expiresAt = $snapshot['expires_at'] ?? null;
        $supported = is_array($snapshot['supported'] ?? null)
            ? array_values(array_filter($snapshot['supported'], static fn ($value): bool => is_string($value)))
            : [];

        return [
            'worker_id' => (string) ($snapshot['worker_id'] ?? ''),
            'namespace' => $this->stringOrNull($snapshot['namespace'] ?? null),
            'host' => $this->stringOrNull($snapshot['host'] ?? null),
            'process_id' => $this->stringOrNull($snapshot['process_id'] ?? null),
            'connection' => $this->stringOrNull($snapshot['connection'] ?? null),
            'queue' => $this->stringOrNull($snapshot['queue'] ?? null),
            'supported' => $supported,
            'supports_required' => ($snapshot['supports_required'] ?? false) === true,
            'recorded_at' => $recordedAt instanceof CarbonInterface ? $recordedAt->toJSON() : $this->stringOrNull(
                $recordedAt
            ),
            'expires_at' => $expiresAt instanceof CarbonInterface ? $expiresAt->toJSON() : $this->stringOrNull(
                $expiresAt
            ),
            'source' => is_string($snapshot['source'] ?? null) ? $snapshot['source'] : '',
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    protected function orderedRunsQuery(?string $bucket = null)
    {
        $query = $this->filteredRunsQuery($bucket);

        if ($this->sortDirection() === 'asc') {
            return $query
                ->orderByRaw('case when sort_timestamp is null then 1 else 0 end asc')
                ->orderBy('sort_timestamp')
                ->orderBy('id');
        }

        return RunSummarySortKey::applyDescending($query);
    }

    protected function statusFlows(string $status)
    {
        return $this->orderedRunsQuery($status)
            ->where('status', $status)
            ->paginate(50);
    }

    protected function filteredRunsQuery(?string $bucket = null)
    {
        $query = $this->runSummaryModel::query()
            ->with([$this->runRelationSelect()]);
        $query = $this->applySummaryNamespaceScope($query);
        $context = V2VisibilityFilterContext::resolve(request(), $bucket);

        ActionabilityVisibilityFilters::apply(
            $query,
            $this->visibilityFiltersForQuery($context['applied_filters']),
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

    private function runSummaryQuery()
    {
        $query = $this->runSummaryModel::query();

        return $this->applySummaryNamespaceScope($query);
    }

    private function failureQuery()
    {
        $query = $this->failureModel::query();
        $namespace = $this->namespace();

        return $namespace === null
            ? $query
            : $query->whereIn(
                'workflow_run_id',
                $this->runModel::query()
                    ->select('id')
                    ->where('namespace', $namespace),
            );
    }

    private function sortDirection(): string
    {
        $direction = request()->query('sort_direction', request()->query('sort'));
        $direction = is_string($direction) ? strtolower(trim($direction)) : '';

        return $direction === 'asc' ? 'asc' : 'desc';
    }

    private function runRelationSelect(): string
    {
        $columns = ['id'];

        if ($this->runColumnExists('namespace')) {
            $columns[] = 'namespace';
        }

        if ($this->runColumnExists('search_attributes')) {
            $columns[] = 'search_attributes';
        }

        return 'run:'.implode(',', $columns);
    }

    private function runColumnExists(string $column): bool
    {
        $modelClass = $this->runModel;
        $model = new $modelClass();
        $connection = DB::connection($model->getConnectionName());
        $cacheKey = sprintf(
            '%s|%s|%s',
            $connection->getName(),
            $model->getTable(),
            $column,
        );

        if (! array_key_exists($cacheKey, $this->databaseColumnExistsCache)) {
            $this->databaseColumnExistsCache[$cacheKey] = $connection->getSchemaBuilder()->hasColumn(
                $model->getTable(),
                $column,
            );
        }

        return $this->databaseColumnExistsCache[$cacheKey];
    }

    private function findFlowSelectionByRunScope(
        string $instanceId,
        string $runId,
        ModelNotFoundException $previous,
    ) {
        $query = $this->runModel::query()
            ->with($this->detailRelations())
            ->where('workflow_instance_id', $instanceId)
            ->whereKey($runId);

        $this->applyRunNamespaceScope($query);

        $run = $query->first();

        if ($run !== null) {
            return $run;
        }

        throw $previous;
    }

    private function applySummaryNamespaceScope($query)
    {
        $namespace = $this->namespace();

        if ($namespace === null) {
            return $query;
        }

        return $query->where(static function ($scope) use ($namespace): void {
            $scope->where('namespace', $namespace)
                ->orWhereHas('run', static function ($runQuery) use ($namespace): void {
                    $runQuery->where('namespace', $namespace);
                });
        });
    }

    private function applyRunNamespaceScope($query): void
    {
        $namespace = $this->namespace();

        if ($namespace === null) {
            return;
        }

        $query->where(static function ($scope) use ($namespace): void {
            $scope->where('namespace', $namespace)
                ->orWhereHas('instance', static function ($instanceQuery) use ($namespace): void {
                    $instanceQuery->where('namespace', $namespace);
                });
        });
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function visibilityFiltersForQuery(array $filters): array
    {
        if ($this->namespace() === null) {
            return $filters;
        }

        unset($filters['namespace']);

        return $filters;
    }
}
