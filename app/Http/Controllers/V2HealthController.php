<?php

namespace Waterline\Http\Controllers;

use Illuminate\Support\Facades\Schema;
use Waterline\Models\WorkerRegistration;
use Waterline\Support\WorkflowEngineSourceResolver;
use Workflow\V2\Support\HealthCheck;
use Workflow\V2\Support\OperatorMetrics;
use Workflow\V2\Support\StandaloneWorkerVisibility;
use Workflow\V2\Support\StructuralLimits;

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
            return [
                'available' => true,
                ...StandaloneWorkerVisibility::queueSnapshot($namespace, WorkerRegistration::class)->toArray(),
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

    private function namespace(): ?string
    {
        $namespace = config('waterline.namespace');

        return is_string($namespace) && trim($namespace) !== '' ? trim($namespace) : null;
    }
}
