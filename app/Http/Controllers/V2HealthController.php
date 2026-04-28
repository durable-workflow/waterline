<?php

namespace Waterline\Http\Controllers;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;
use Waterline\Models\WorkerRegistration;
use Waterline\Support\WorkflowEngineSourceResolver;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Support\HealthCheck;
use Workflow\V2\Support\OperatorMetrics;
use Workflow\V2\Support\StandaloneWorkerVisibility;
use Workflow\V2\Support\StructuralLimits;
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
                ...$this->withRecentTaskFlowFallback(
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
     * Keep queue-flow facts visible on older workflow-package builds until the
     * shared queue-visibility snapshot always carries them directly.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withRecentTaskFlowFallback(string $namespace, array $payload, CarbonInterface $now): array
    {
        if (! is_array($payload['task_queues'] ?? null)) {
            return $payload;
        }

        $payload['task_queues'] = array_map(
            fn (array $taskQueue): array => $this->withRecentTaskFlow($namespace, $taskQueue, $now),
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

        $name = is_string($taskQueue['name'] ?? null) && trim((string) $taskQueue['name']) !== ''
            ? trim((string) $taskQueue['name'])
            : 'default';

        $taskQueue['stats'] = array_merge($stats, $this->recentTaskFlow($namespace, $name, $now));

        return $taskQueue;
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

    private function namespace(): ?string
    {
        $namespace = config('waterline.namespace');

        return is_string($namespace) && trim($namespace) !== '' ? trim($namespace) : null;
    }
}
