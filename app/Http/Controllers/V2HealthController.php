<?php

namespace Waterline\Http\Controllers;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;
use Waterline\Models\WorkerRegistration;
use Waterline\Support\WorkflowEngineSourceResolver;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Support\HealthCheck;
use Workflow\V2\Support\OperatorMetrics;
use Workflow\V2\Support\StandaloneWorkerVisibility;
use Workflow\V2\Support\StructuralLimits;
use Workflow\V2\Support\TaskRepairPolicy;
use Workflow\V2\Models\WorkflowTask;

class V2HealthController extends Controller
{
    public function show()
    {
        $engineSource = WorkflowEngineSourceResolver::status();
        $namespace = $this->namespace();

        if (($engineSource['uses_v2'] ?? false) !== true) {
            return response()->json([
                'namespace' => $namespace,
                'queue_visibility' => $this->emptyQueueVisibility(
                    $namespace,
                    'Queue visibility is unavailable until Waterline uses the v2 operator bridge.',
                ),
                'generated_at' => now()->toJSON(),
                'status' => 'error',
                'healthy' => false,
                'checks' => [
                    [
                        'name' => 'engine_source',
                        'status' => 'error',
                        'message' => $engineSource['message'] ?? 'Waterline is not currently using the v2 operator bridge.',
                        'meta' => [
                            'configured' => $engineSource['configured'] ?? null,
                            'resolved' => $engineSource['resolved'] ?? null,
                            'uses_v2' => $engineSource['uses_v2'] ?? false,
                            'v2_operator_surface_available' => $engineSource['v2_operator_surface_available'] ?? false,
                            'issue_count' => count(is_array($engineSource['issues'] ?? null) ? $engineSource['issues'] : []),
                        ],
                    ],
                ],
                'engine_source' => $engineSource,
                'readiness_contract' => $engineSource['readiness_contract'] ?? null,
            ], 503);
        }

        $snapshot = $this->snapshotForConfiguredNamespace();
        array_unshift($snapshot['checks'], [
            'name' => 'engine_source',
            'status' => 'ok',
            'message' => $engineSource['message'] ?? 'Waterline is using the v2 operator bridge.',
            'meta' => [
                'configured' => $engineSource['configured'] ?? null,
                'resolved' => $engineSource['resolved'] ?? null,
                'uses_v2' => $engineSource['uses_v2'] ?? false,
                'v2_operator_surface_available' => $engineSource['v2_operator_surface_available'] ?? false,
                'issue_count' => count(is_array($engineSource['issues'] ?? null) ? $engineSource['issues'] : []),
            ],
        ]);
        $snapshot['namespace'] = $namespace;
        $snapshot['queue_visibility'] = $this->queueVisibility($namespace);
        $snapshot['engine_source'] = $engineSource;
        $snapshot['readiness_contract'] = $engineSource['readiness_contract'] ?? null;

        return response()->json($snapshot, HealthCheck::httpStatus($snapshot));
    }

    /**
     * Keep Waterline compatible with both the released alpha package and the
     * newer workflow branch while health snapshots gain namespace scoping.
     *
     * @return array<string, mixed>
     */
    private function snapshotForConfiguredNamespace(): array
    {
        $namespace = $this->namespace();
        $now = now();

        if ((new \ReflectionMethod(HealthCheck::class, 'snapshot'))->getNumberOfParameters() >= 2) {
            return HealthCheck::snapshot($now, $namespace);
        }

        $metrics = OperatorMetrics::snapshot($now, $namespace);
        $checks = [
            self::invokeLegacyHealthCheck('backendCheck', $metrics['backend'] ?? []),
            self::invokeLegacyHealthCheck('runSummaryProjectionCheck', $metrics['projections']['run_summaries'] ?? []),
            self::invokeLegacyHealthCheck('selectedRunProjectionCheck', $metrics['projections'] ?? []),
            self::invokeLegacyHealthCheck('historyRetentionInvariantCheck', $metrics['history'] ?? []),
            self::invokeLegacyHealthCheck('commandContractCheck', $metrics['command_contracts'] ?? []),
            self::invokeLegacyHealthCheck('taskTransportCheck', $metrics['tasks'] ?? [], $metrics['backlog'] ?? []),
            self::invokeLegacyHealthCheck(
                'durableResumePathCheck',
                $metrics['backlog'] ?? [],
                $metrics['repair'] ?? [],
                $metrics['runs'] ?? [],
            ),
            self::invokeLegacyHealthCheck('workerCompatibilityCheck', $metrics['workers'] ?? []),
            self::invokeLegacyHealthCheck('schedulerRoleCheck', $metrics['schedules'] ?? []),
            self::invokeLegacyHealthCheck('longPollWakeAccelerationCheck'),
        ];
        $status = self::invokeLegacyHealthCheck('status', $checks);

        return [
            'generated_at' => $metrics['generated_at'] ?? $now->toJSON(),
            'status' => $status,
            'healthy' => $status !== 'error',
            'checks' => $checks,
            'categories' => self::invokeLegacyHealthCheck('categorySummary', $checks),
            'operator_metrics' => $metrics,
            'structural_limits' => StructuralLimits::snapshot(),
        ];
    }

    private static function invokeLegacyHealthCheck(string $method, mixed ...$args): mixed
    {
        return \Closure::bind(
            static fn (string $method, array $args): mixed => HealthCheck::$method(...$args),
            null,
            HealthCheck::class,
        )($method, $args);
    }

    /**
     * @return array<string, mixed>
     */
    private function queueVisibility(?string $namespace): array
    {
        if ($namespace === null) {
            return $this->emptyQueueVisibility(
                null,
                'Configure waterline.namespace to scope queue visibility to one task-queue fleet.',
            );
        }

        if (! class_exists(StandaloneWorkerVisibility::class)) {
            return $this->emptyQueueVisibility(
                $namespace,
                'The installed workflow package does not expose the queue-visibility contract yet.',
            );
        }

        if (! Schema::hasTable((new WorkerRegistration())->getTable())) {
            return $this->emptyQueueVisibility(
                $namespace,
                'Queue visibility requires the workflow_worker_registrations table from the standalone server schema.',
            );
        }

        try {
            $now = now();

            return [
                'available' => true,
                ...$this->withQueueVisibilityFallback(
                    $namespace,
                    StandaloneWorkerVisibility::queueSnapshot($namespace, WorkerRegistration::class, $now)->toArray(),
                    $now,
                ),
            ];
        } catch (\Throwable) {
            return $this->emptyQueueVisibility(
                $namespace,
                'Queue visibility could not be loaded from the current worker registration schema.',
            );
        }
    }

    /**
     * @return array{available: bool, namespace: string|null, task_queues: array<int, array<string, mixed>>, reason: string}
     */
    private function emptyQueueVisibility(?string $namespace, string $reason): array
    {
        return [
            'available' => false,
            'namespace' => $namespace,
            'task_queues' => [],
            'reason' => $reason,
        ];
    }

    /**
     * Keep queue-visibility facts visible on older workflow-package builds
     * until the shared queue-visibility snapshot always carries them directly.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withQueueVisibilityFallback(string $namespace, array $payload, CarbonInterface $now): array
    {
        if (! is_array($payload['task_queues'] ?? null)) {
            return $payload;
        }

        $payload['task_queues'] = array_map(
            fn (array $taskQueue): array => $this->withQueueRepairAgeFallback(
                $namespace,
                $this->withRecentTaskFlow($namespace, $taskQueue, $now),
                $now,
            ),
            $payload['task_queues'],
        );

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $taskQueue
     * @return array<string, mixed>
     */
    private function withRecentTaskFlow(string $namespace, array $taskQueue, CarbonInterface $now): array
    {
        $stats = is_array($taskQueue['stats'] ?? null) ? $taskQueue['stats'] : [];

        if (array_key_exists('tasks_added_last_minute', $stats)
            && array_key_exists('tasks_dispatched_last_minute', $stats)) {
            return $taskQueue;
        }

        $name = $this->queueName($taskQueue);

        $taskQueue['stats'] = array_merge($stats, $this->recentTaskFlow($namespace, $name, $now));

        return $taskQueue;
    }

    /**
     * @param  array<string, mixed>  $taskQueue
     * @return array<string, mixed>
     */
    private function withQueueRepairAgeFallback(string $namespace, array $taskQueue, CarbonInterface $now): array
    {
        $repair = is_array($taskQueue['repair'] ?? null) ? $taskQueue['repair'] : [];

        if ($this->hasQueueRepairAges($repair)) {
            return $taskQueue;
        }

        $taskQueue['repair'] = array_merge(
            $repair,
            $this->queueRepairAges($namespace, $this->queueName($taskQueue), $repair, $now),
        );

        return $taskQueue;
    }

    /**
     * @param  array<string, mixed>  $repair
     * @return array<string, mixed>
     */
    private function queueRepairAges(
        string $namespace,
        string $taskQueue,
        array $repair,
        CarbonInterface $now,
    ): array {
        $redispatchCutoff = $now->copy()
            ->subSeconds($this->redispatchAfterSeconds($repair));

        $baseQuery = WorkflowTask::query()
            ->where('namespace', $namespace)
            ->where('queue', $taskQueue)
            ->whereIn('task_type', [TaskType::Workflow->value, TaskType::Activity->value]);

        $dispatchFailedQuery = (clone $baseQuery)
            ->where('status', TaskStatus::Ready->value)
            ->whereNotNull('last_dispatch_attempt_at')
            ->whereNotNull('last_dispatch_error')
            ->where('last_dispatch_error', '!=', '')
            ->where(static function ($query): void {
                $query->whereNull('last_dispatched_at')
                    ->orWhereColumn('last_dispatch_attempt_at', '>', 'last_dispatched_at');
            });
        $oldestDispatchFailedTask = (clone $dispatchFailedQuery)
            ->orderBy('last_dispatch_attempt_at')
            ->first();
        $oldestDispatchFailedAt = $oldestDispatchFailedTask instanceof WorkflowTask
            ? $oldestDispatchFailedTask->last_dispatch_attempt_at
            : null;

        $expiredLeaseQuery = (clone $baseQuery)
            ->where('status', TaskStatus::Leased->value)
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<=', $now);
        $oldestExpiredLeaseTask = (clone $expiredLeaseQuery)
            ->orderBy('lease_expires_at')
            ->first();
        $oldestLeaseExpiredAt = $oldestExpiredLeaseTask instanceof WorkflowTask
            ? $oldestExpiredLeaseTask->lease_expires_at
            : null;

        $dispatchOverdueQuery = (clone $baseQuery)
            ->where('status', TaskStatus::Ready->value)
            ->where(static function ($query) use ($now): void {
                $query->whereNull('available_at')
                    ->orWhere('available_at', '<=', $now);
            })
            ->where(static function ($query) use ($redispatchCutoff): void {
                $query->where(static function ($dispatched) use ($redispatchCutoff): void {
                    $dispatched->whereNotNull('last_dispatched_at')
                        ->where('last_dispatched_at', '<=', $redispatchCutoff);
                })->orWhere(static function ($neverDispatched) use ($redispatchCutoff): void {
                    $neverDispatched->whereNull('last_dispatched_at')
                        ->where('created_at', '<=', $redispatchCutoff);
                });
            })
            ->where(static function ($query): void {
                $query->whereNull('last_dispatch_error')
                    ->orWhere('last_dispatch_error', '');
            });
        $oldestDispatchOverdueTask = (clone $dispatchOverdueQuery)
            ->orderByRaw('COALESCE(last_dispatched_at, created_at) asc')
            ->first();
        $oldestDispatchOverdueSince = $oldestDispatchOverdueTask instanceof WorkflowTask
            ? ($oldestDispatchOverdueTask->last_dispatched_at ?? $oldestDispatchOverdueTask->created_at)
            : null;

        return [
            'oldest_dispatch_failed_at' => $oldestDispatchFailedAt?->toJSON(),
            'max_dispatch_failed_age_ms' => $this->ageMilliseconds($oldestDispatchFailedAt, $now),
            'oldest_lease_expired_at' => $oldestLeaseExpiredAt?->toJSON(),
            'max_lease_expired_age_ms' => $this->ageMilliseconds($oldestLeaseExpiredAt, $now),
            'oldest_dispatch_overdue_since' => $oldestDispatchOverdueSince?->toJSON(),
            'max_dispatch_overdue_age_ms' => $this->ageMilliseconds($oldestDispatchOverdueSince, $now),
        ];
    }

    /**
     * @param  array<string, mixed>  $repair
     */
    private function hasQueueRepairAges(array $repair): bool
    {
        foreach ([
            'oldest_dispatch_failed_at',
            'max_dispatch_failed_age_ms',
            'oldest_lease_expired_at',
            'max_lease_expired_age_ms',
            'oldest_dispatch_overdue_since',
            'max_dispatch_overdue_age_ms',
        ] as $key) {
            if (! array_key_exists($key, $repair)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{tasks_added_last_minute: int, tasks_dispatched_last_minute: int}
     */
    private function recentTaskFlow(string $namespace, string $taskQueue, CarbonInterface $now): array
    {
        $windowStart = $now->copy()->subMinute();

        $query = WorkflowTask::query()
            ->where('namespace', $namespace)
            ->where('queue', $taskQueue)
            ->whereIn('task_type', [TaskType::Workflow->value, TaskType::Activity->value]);

        return [
            'tasks_added_last_minute' => (clone $query)
                ->where('created_at', '>=', $windowStart)
                ->count(),
            'tasks_dispatched_last_minute' => (clone $query)
                ->whereNotNull('last_dispatched_at')
                ->where('last_dispatched_at', '>=', $windowStart)
                ->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $taskQueue
     */
    private function queueName(array $taskQueue): string
    {
        return is_string($taskQueue['name'] ?? null) && trim((string) $taskQueue['name']) !== ''
            ? trim((string) $taskQueue['name'])
            : 'default';
    }

    /**
     * @param  array<string, mixed>  $repair
     */
    private function redispatchAfterSeconds(array $repair): int
    {
        $configured = $repair['policy']['redispatch_after_seconds'] ?? null;

        if (is_numeric($configured)) {
            return max(0, (int) $configured);
        }

        return TaskRepairPolicy::redispatchAfterSeconds();
    }

    private function ageMilliseconds(mixed $timestamp, CarbonInterface $now): int
    {
        if (! $timestamp instanceof CarbonInterface) {
            return 0;
        }

        return (int) $timestamp->diffInMilliseconds($now);
    }

    private function namespace(): ?string
    {
        $namespace = config('waterline.namespace');

        return is_string($namespace) && trim($namespace) !== '' ? trim($namespace) : null;
    }
}
