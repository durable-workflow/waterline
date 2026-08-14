<?php

declare(strict_types=1);

namespace Waterline\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;

final class EmbeddedCapacityEvidenceCollector
{
    /**
     * Collect only fixed, aggregate dimensions from v2 durable tables. No row
     * identity, workflow type, queue name, or customer-defined label leaves
     * this class.
     *
     * @return array<string, mixed>
     */
    public function collect(
        CarbonInterface $windowStart,
        CarbonInterface $windowEnd,
        ?string $namespace,
    ): array {
        if (! class_exists(WorkflowRun::class) || ! $this->runtimeTablesAvailable()) {
            return [];
        }

        try {
            $runs = $this->scopedRunQuery($namespace);
            $tasks = $this->scopedTaskQuery($namespace);
            $activities = $this->scopedRelatedQuery($this->activityExecutionModel(), $namespace);
            $attempts = $this->scopedRelatedQuery($this->activityAttemptModel(), $namespace);
            $commands = $this->scopedRelatedQuery($this->commandModel(), $namespace);
            $history = $this->scopedRelatedQuery($this->historyEventModel(), $namespace);
            $timers = $this->scopedRelatedQuery($this->timerModel(), $namespace);

            $workflowStarts = $this->countBetween($runs, 'started_at', $windowStart, $windowEnd);
            $workflowCompletions = $this->countBetween(
                (clone $runs)->where('status', 'completed'),
                'closed_at',
                $windowStart,
                $windowEnd,
            );
            $activityDispatches = $this->countBetween($attempts, 'started_at', $windowStart, $windowEnd);
            $activityCompletions = $this->countBetween(
                (clone $activities)->where('status', 'completed'),
                'closed_at',
                $windowStart,
                $windowEnd,
            );
            $timersScheduled = $this->countBetween($timers, 'created_at', $windowStart, $windowEnd);
            $timersFired = $this->countBetween($timers, 'fired_at', $windowStart, $windowEnd);
            $signals = $this->countBetween(
                (clone $commands)->where('command_type', 'signal'),
                'accepted_at',
                $windowStart,
                $windowEnd,
            );
            $updates = $this->countBetween(
                (clone $commands)->where('command_type', 'update'),
                'accepted_at',
                $windowStart,
                $windowEnd,
            );
            $historyEvents = $this->countBetween($history, 'recorded_at', $windowStart, $windowEnd);
            $historyBytes = $this->sumBytesBetween(
                $history,
                ['payload'],
                'recorded_at',
                $windowStart,
                $windowEnd,
            );

            return [
                'throughput' => [
                    'workflow_starts' => $this->windowCount($workflowStarts),
                    'workflow_completions' => $this->windowCount($workflowCompletions),
                    'activity_dispatches' => $this->windowCount($activityDispatches),
                    'activity_completions' => $this->windowCount($activityCompletions),
                    'timers_scheduled' => $this->windowCount($timersScheduled),
                    'timers_fired' => $this->windowCount($timersFired),
                    'signals' => $this->windowCount($signals),
                    'queries' => $this->unavailableWindowCount('query_telemetry_unavailable'),
                    'updates' => $this->windowCount($updates),
                ],
                'latency' => [
                    'schedule_to_start' => $this->durationDistribution(
                        $activities,
                        'created_at',
                        'started_at',
                        'started_at',
                        $windowStart,
                        $windowEnd,
                    ),
                    'execution' => $this->durationDistribution(
                        $runs,
                        'started_at',
                        'closed_at',
                        'closed_at',
                        $windowStart,
                        $windowEnd,
                    ),
                    'replay' => $this->durationDistribution(
                        (clone $tasks)->where('task_type', 'workflow')->where('status', 'completed'),
                        'leased_at',
                        'updated_at',
                        'updated_at',
                        $windowStart,
                        $windowEnd,
                    ),
                    'inspection' => [
                        'available' => false,
                        'reason' => 'inspection_telemetry_unavailable',
                        'source' => 'not_available',
                    ],
                ],
                'growth' => [
                    'history_events' => $this->windowCount($historyEvents),
                    'history_payload_bytes' => $this->windowBytes($historyBytes),
                    'durable_payload_bytes' => $this->windowBytes($this->durablePayloadBytes(
                        $runs,
                        $tasks,
                        $activities,
                        $commands,
                        $history,
                        $windowStart,
                        $windowEnd,
                    )),
                ],
                'reliability' => [
                    'retries' => $this->windowCount($this->countBetween(
                        (clone $attempts)->where('attempt_number', '>', 1),
                        'started_at',
                        $windowStart,
                        $windowEnd,
                    )),
                    'timeouts' => $this->windowCount($this->countBetween(
                        (clone $attempts)->where('status', 'expired'),
                        'closed_at',
                        $windowStart,
                        $windowEnd,
                    )),
                    'failures' => $this->windowCount(
                        $this->countBetween(
                            (clone $runs)->where('status', 'failed'),
                            'closed_at',
                            $windowStart,
                            $windowEnd,
                        ) + $this->countBetween(
                            (clone $attempts)->where('status', 'failed'),
                            'closed_at',
                            $windowStart,
                            $windowEnd,
                        ) + $this->countBetween(
                            (clone $tasks)->where('status', 'failed'),
                            'updated_at',
                            $windowStart,
                            $windowEnd,
                        ),
                    ),
                    'stale_heartbeats' => $this->gauge(
                        (clone $activities)
                            ->where('status', 'running')
                            ->whereNotNull('heartbeat_deadline_at')
                            ->where('heartbeat_deadline_at', '<=', $windowEnd)
                            ->count(),
                    ),
                    'overload_or_throttling' => $this->windowCount(
                        $this->countBetween(
                            (clone $tasks)->whereNotNull('last_dispatch_error'),
                            'last_dispatch_attempt_at',
                            $windowStart,
                            $windowEnd,
                        ) + $this->countBetween(
                            (clone $tasks)->whereNotNull('last_claim_error'),
                            'last_claim_failed_at',
                            $windowStart,
                            $windowEnd,
                        ),
                    ),
                ],
            ];
        } catch (Throwable) {
            return [];
        }
    }

    private function runtimeTablesAvailable(): bool
    {
        $model = $this->newModel($this->runModel());
        $connection = $model->getConnectionName();

        return Schema::connection($connection)->hasTable($model->getTable());
    }

    private function scopedRunQuery(?string $namespace): Builder
    {
        $query = $this->modelQuery($this->runModel());

        if ($namespace !== null) {
            $query->where($query->getModel()->qualifyColumn('namespace'), $namespace);
        }

        return $query;
    }

    private function scopedTaskQuery(?string $namespace): Builder
    {
        $query = $this->modelQuery($this->taskModel());

        if ($namespace !== null) {
            $query->where($query->getModel()->qualifyColumn('namespace'), $namespace);
        }

        return $query;
    }

    /** @param class-string<Model> $model */
    private function scopedRelatedQuery(string $model, ?string $namespace): Builder
    {
        $query = $this->modelQuery($model);

        if ($namespace !== null) {
            $runIds = $this->scopedRunQuery($namespace)->select(
                $this->modelQuery($this->runModel())->getModel()->qualifyColumn('id'),
            );
            $query->whereIn($query->getModel()->qualifyColumn('workflow_run_id'), $runIds);
        }

        return $query;
    }

    private function countBetween(
        Builder $query,
        string $column,
        CarbonInterface $start,
        CarbonInterface $end,
    ): int {
        return (int) (clone $query)
            ->whereBetween($query->getModel()->qualifyColumn($column), [$start, $end])
            ->count();
    }

    /**
     * Build a bounded systematic sample over the complete, chronologically
     * ordered population. Each populated distribution uses exactly two
     * queries: one count and one ordered rank pass. The database returns only
     * midpoint rows, and the primary key makes equal timestamps stable on
     * every supported database.
     *
     * @return array{available: bool, samples_ms?: list<float>, population_count?: int, sampling_method?: string, sampling_population?: string, source: string, reason?: string}
     */
    private function durationDistribution(
        Builder $query,
        string $startedColumn,
        string $endedColumn,
        string $windowColumn,
        CarbonInterface $windowStart,
        CarbonInterface $windowEnd,
    ): array {
        $model = $query->getModel();
        $started = $model->qualifyColumn($startedColumn);
        $ended = $model->qualifyColumn($endedColumn);
        $boundary = $model->qualifyColumn($windowColumn);
        $eligible = (clone $query)
            ->whereNotNull($started)
            ->whereNotNull($ended)
            ->whereBetween($boundary, [$windowStart, $windowEnd]);
        $population = (int) (clone $eligible)->toBase()->count();

        if ($population === 0) {
            return [
                'available' => false,
                'source' => 'not_available',
                'reason' => 'insufficient_samples',
            ];
        }

        $limit = max(1, min(50000, (int) config('waterline.capacity_evidence.latency_sample_limit', 10000)));
        $sampleCount = min($population, $limit);
        $connection = $model->getConnection();
        $grammar = $connection->getQueryGrammar();
        $primaryKey = $model->qualifyColumn($model->getKeyName());
        $rankAlias = 'waterline_population_rank';
        $startedAlias = 'waterline_started_at';
        $endedAlias = 'waterline_ended_at';
        $rankedAlias = 'waterline_latency_population';
        $ranked = (clone $eligible)
            ->toBase()
            ->select([
                "{$started} as {$startedAlias}",
                "{$ended} as {$endedAlias}",
            ])
            ->selectRaw(sprintf(
                'ROW_NUMBER() OVER (ORDER BY %s, %s) AS %s',
                $grammar->wrap($boundary),
                $grammar->wrap($primaryKey),
                $grammar->wrap($rankAlias),
            ));
        $qualifiedRank = $grammar->wrap("{$rankedAlias}.{$rankAlias}");

        // A row is a midpoint exactly when its half-stratum crossing count is
        // one. This predicate avoids a database-specific values table or a
        // potentially 50,000-element IN list while retaining the exact
        // systematic population midpoint ranks.
        $integerDivision = $connection->getDriverName() === 'mysql' ? 'DIV' : '/';
        $midpointCrossing = sprintf(
            '((2 * ? * %1$s + ? - 1) %2$s (2 * ?)) > '
            .'((2 * ? * (%1$s - 1) + ? - 1) %2$s (2 * ?))',
            $qualifiedRank,
            $integerDivision,
        );
        $rows = $connection
            ->query()
            ->fromSub($ranked, $rankedAlias)
            ->whereRaw($midpointCrossing, [
                $sampleCount,
                $population,
                $population,
                $sampleCount,
                $population,
                $population,
            ])
            ->orderBy($rankAlias)
            ->get([$startedAlias, $endedAlias]);
        $samples = [];

        foreach ($rows as $row) {
            $from = $row->{$startedAlias};
            $to = $row->{$endedAlias};

            try {
                $from = $from instanceof CarbonInterface ? $from : Carbon::parse((string) $from);
                $to = $to instanceof CarbonInterface ? $to : Carbon::parse((string) $to);
            } catch (Throwable) {
                continue;
            }

            $samples[] = max(0.0, (float) $from->diffInMilliseconds($to));
        }

        $samplingMethod = $population > $limit
            ? 'systematic_population_rank_midpoint'
            : 'full_population';

        return count($samples) !== $sampleCount
            ? [
                'available' => false,
                'samples_ms' => $samples,
                'population_count' => (int) $population,
                'sampling_method' => $samplingMethod,
                'sampling_population' => 'eligible_rows_in_observation_window',
                'source' => 'not_available',
                'reason' => 'incomplete_representative_sample',
            ]
            : [
                'available' => true,
                'samples_ms' => $samples,
                'population_count' => (int) $population,
                'sampling_method' => $samplingMethod,
                'sampling_population' => 'eligible_rows_in_observation_window',
                'source' => 'waterline_embedded_store',
            ];
    }

    private function durablePayloadBytes(
        Builder $runs,
        Builder $tasks,
        Builder $activities,
        Builder $commands,
        Builder $history,
        CarbonInterface $windowStart,
        CarbonInterface $windowEnd,
    ): int {
        return $this->sumBytesBetween($history, ['payload'], 'recorded_at', $windowStart, $windowEnd)
            + $this->sumBytesBetween($tasks, ['payload'], 'created_at', $windowStart, $windowEnd)
            + $this->sumBytesBetween($commands, ['payload'], 'created_at', $windowStart, $windowEnd)
            + $this->sumBytesBetween($runs, ['arguments'], 'created_at', $windowStart, $windowEnd)
            + $this->sumBytesBetween($runs, ['output'], 'closed_at', $windowStart, $windowEnd)
            + $this->sumBytesBetween($activities, ['arguments'], 'created_at', $windowStart, $windowEnd)
            + $this->sumBytesBetween(
                $activities,
                ['result', 'exception'],
                'closed_at',
                $windowStart,
                $windowEnd,
            );
    }

    /** @param list<string> $columns */
    private function sumBytesBetween(
        Builder $query,
        array $columns,
        string $windowColumn,
        CarbonInterface $windowStart,
        CarbonInterface $windowEnd,
    ): int {
        $model = $query->getModel();
        $connection = $model->getConnection();
        $grammar = $connection->getQueryGrammar();
        $driver = $connection->getDriverName();
        $parts = [];

        foreach ($columns as $column) {
            $wrapped = $grammar->wrap($model->qualifyColumn($column));
            $parts[] = match ($driver) {
                'pgsql' => "OCTET_LENGTH(COALESCE(CAST({$wrapped} AS TEXT), ''))",
                'sqlsrv' => "DATALENGTH(COALESCE({$wrapped}, ''))",
                default => "LENGTH(COALESCE({$wrapped}, ''))",
            };
        }

        $boundary = $model->qualifyColumn($windowColumn);
        $value = (clone $query)
            ->whereBetween($boundary, [$windowStart, $windowEnd])
            ->selectRaw('COALESCE(SUM('.implode(' + ', $parts).'), 0) AS aggregate_bytes')
            ->value('aggregate_bytes');

        return max(0, (int) $value);
    }

    /** @return array{available: true, value: int, unit: string, kind: string, source: string} */
    private function windowCount(int $value): array
    {
        return [
            'available' => true,
            'value' => max(0, $value),
            'unit' => 'count',
            'kind' => 'window_count',
            'source' => 'waterline_embedded_store',
        ];
    }

    /** @return array{available: true, value: int, unit: string, kind: string, source: string} */
    private function windowBytes(int $value): array
    {
        return [
            'available' => true,
            'value' => max(0, $value),
            'unit' => 'bytes',
            'kind' => 'window_count',
            'source' => 'waterline_embedded_store',
        ];
    }

    /** @return array{available: true, value: int, unit: string, kind: string, source: string} */
    private function gauge(int $value): array
    {
        return [
            'available' => true,
            'value' => max(0, $value),
            'unit' => 'count',
            'kind' => 'gauge',
            'source' => 'waterline_embedded_store',
        ];
    }

    /** @return array{available: false, reason: string, source: string} */
    private function unavailableWindowCount(string $reason): array
    {
        return [
            'available' => false,
            'reason' => $reason,
            'source' => 'not_available',
        ];
    }

    /** @param class-string<Model> $model */
    private function modelQuery(string $model): Builder
    {
        return $model::query();
    }

    /** @param class-string<Model> $model */
    private function newModel(string $model): Model
    {
        return new $model();
    }

    /** @return class-string<Model> */
    private function runModel(): string
    {
        return $this->configuredModel('run_model', WorkflowRun::class);
    }

    /** @return class-string<Model> */
    private function taskModel(): string
    {
        return $this->configuredModel('task_model', WorkflowTask::class);
    }

    /** @return class-string<Model> */
    private function activityExecutionModel(): string
    {
        return $this->configuredModel('activity_execution_model', ActivityExecution::class);
    }

    /** @return class-string<Model> */
    private function activityAttemptModel(): string
    {
        return $this->configuredModel('activity_attempt_model', ActivityAttempt::class);
    }

    /** @return class-string<Model> */
    private function commandModel(): string
    {
        return $this->configuredModel('command_model', WorkflowCommand::class);
    }

    /** @return class-string<Model> */
    private function historyEventModel(): string
    {
        return $this->configuredModel('history_event_model', WorkflowHistoryEvent::class);
    }

    /** @return class-string<Model> */
    private function timerModel(): string
    {
        return $this->configuredModel('timer_model', WorkflowTimer::class);
    }

    /**
     * @param class-string<Model> $default
     * @return class-string<Model>
     */
    private function configuredModel(string $key, string $default): string
    {
        $configured = config("workflows.v2.{$key}", $default);

        return is_string($configured) && is_subclass_of($configured, Model::class)
            ? $configured
            : $default;
    }
}
