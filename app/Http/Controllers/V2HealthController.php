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
            $payload = [
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
            ];
            $payload['coordination_alerts'] = $this->coordinationAlerts(
                $payload['checks'],
                $payload['queue_visibility'],
            );

            return response()->json($payload, 503);
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
        $snapshot['coordination_alerts'] = $this->coordinationAlerts(
            $snapshot['checks'],
            $snapshot['queue_visibility'],
        );

        return response()->json($snapshot, HealthCheck::httpStatus($snapshot));
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     * @param  array<string, mixed>  $queueVisibility
     * @return array<int, array<string, mixed>>
     */
    private function coordinationAlerts(array $checks, array $queueVisibility): array
    {
        return array_values(array_merge(
            $this->healthCheckAlerts($checks),
            $this->queueVisibilityAlerts($queueVisibility),
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     * @return array<int, array<string, mixed>>
     */
    private function healthCheckAlerts(array $checks): array
    {
        $alerts = [];

        foreach ($checks as $check) {
            if (! is_array($check)) {
                continue;
            }

            $status = is_string($check['status'] ?? null) ? strtolower(trim((string) $check['status'])) : '';
            if (! in_array($status, ['warning', 'error'], true)) {
                continue;
            }

            $key = is_string($check['name'] ?? null) && trim((string) $check['name']) !== ''
                ? trim((string) $check['name'])
                : 'health_check';
            $title = $this->humanizeAlertKey($key);
            $summary = is_string($check['message'] ?? null) && trim((string) $check['message']) !== ''
                ? trim((string) $check['message'])
                : sprintf('%s reported %s.', $title, $status);
            $facts = is_array($check['data'] ?? null) ? $check['data'] : [];

            $alerts[] = [
                'key' => $key,
                'source' => 'health_check',
                'status' => $status,
                'title' => $title,
                'summary' => $summary,
                'details' => $this->healthCheckAlertDetails($key, $facts),
                'facts' => $facts !== [] ? $facts : null,
                'category' => is_string($check['category'] ?? null)
                    ? trim((string) $check['category'])
                    : null,
            ];
        }

        return $alerts;
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function healthCheckAlertDetails(string $key, array $facts): ?string
    {
        return match ($key) {
            'task_transport' => $this->taskTransportAlertDetails($facts),
            'routing_health' => $this->routingHealthAlertDetails($facts),
            'durable_resume_paths' => $this->durableResumePathAlertDetails($facts),
            'worker_compatibility' => $this->workerCompatibilityAlertDetails($facts),
            'command_contract_snapshots' => $this->commandContractAlertDetails($facts),
            'scheduler_role' => $this->schedulerRoleAlertDetails($facts),
            'long_poll_wake_acceleration' => $this->longPollWakeAlertDetails($facts),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function taskTransportAlertDetails(array $facts): ?string
    {
        $unhealthyTasks = $this->integerValue($facts['unhealthy_tasks'] ?? 0);
        $breakdown = [];

        foreach ([
            'dispatch_failed_tasks' => 'dispatch failed',
            'claim_failed_tasks' => 'claim failed',
            'dispatch_overdue_tasks' => 'dispatch overdue',
            'lease_expired_tasks' => 'lease expired',
        ] as $key => $label) {
            $count = $this->integerValue($facts[$key] ?? 0);

            if ($count > 0) {
                $breakdown[] = sprintf('%d %s', $count, $label);
            }
        }

        $maxAgeMs = max([
            $this->integerValue($facts['max_dispatch_failed_age_ms'] ?? 0),
            $this->integerValue($facts['max_claim_failed_age_ms'] ?? 0),
            $this->integerValue($facts['max_dispatch_overdue_age_ms'] ?? 0),
            $this->integerValue($facts['max_lease_expired_age_ms'] ?? 0),
        ]);

        $parts = [];

        if ($unhealthyTasks > 0) {
            $parts[] = sprintf(
                '%d unhealthy task%s are projected on the transport path',
                $unhealthyTasks,
                $unhealthyTasks === 1 ? '' : 's',
            );
        }

        if ($breakdown !== []) {
            $parts[] = sprintf('breakdown: %s', implode(', ', $breakdown));
        }

        if ($maxAgeMs > 0) {
            $parts[] = sprintf('worst-case age %s', $this->formatDurationMilliseconds($maxAgeMs));
        }

        return $parts !== [] ? ucfirst(implode('; ', $parts)).'.' : null;
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function routingHealthAlertDetails(array $facts): ?string
    {
        $compatibilityBlockedRuns = $this->integerValue($facts['compatibility_blocked_runs'] ?? 0);
        $dispatchOverdueTasks = $this->integerValue($facts['dispatch_overdue_tasks'] ?? 0);
        $claimFailedTasks = $this->integerValue($facts['claim_failed_tasks'] ?? 0);
        $activeWorkerScopes = $this->integerValue($facts['active_worker_scopes'] ?? 0);
        $matchingShape = is_string($facts['matching_shape'] ?? null)
            ? trim((string) $facts['matching_shape'])
            : 'in_worker';
        $taskDispatchMode = is_string($facts['task_dispatch_mode'] ?? null)
            ? trim((string) $facts['task_dispatch_mode'])
            : 'queue';
        $queueWakeEnabled = ($facts['queue_wake_enabled'] ?? false) === true;
        $maxAgeMs = max([
            $this->integerValue($facts['max_compatibility_blocked_age_ms'] ?? 0),
            $this->integerValue($facts['max_dispatch_overdue_age_ms'] ?? 0),
            $this->integerValue($facts['max_claim_failed_age_ms'] ?? 0),
        ]);

        $signals = [];

        if ($compatibilityBlockedRuns > 0) {
            $signals[] = sprintf(
                '%d compatibility-blocked run%s',
                $compatibilityBlockedRuns,
                $compatibilityBlockedRuns === 1 ? '' : 's',
            );
        }

        if ($dispatchOverdueTasks > 0) {
            $signals[] = sprintf(
                '%d dispatch-overdue task%s',
                $dispatchOverdueTasks,
                $dispatchOverdueTasks === 1 ? '' : 's',
            );
        }

        if ($claimFailedTasks > 0) {
            $signals[] = sprintf(
                '%d claim-failed task%s',
                $claimFailedTasks,
                $claimFailedTasks === 1 ? '' : 's',
            );
        }

        $parts = [];

        if ($signals !== []) {
            $parts[] = ucfirst(implode(', ', $signals));
        }

        $parts[] = sprintf(
            'matching role %s in %s mode with queue wake %s across %d active worker scope%s',
            $matchingShape,
            $taskDispatchMode,
            $queueWakeEnabled ? 'enabled' : 'disabled',
            $activeWorkerScopes,
            $activeWorkerScopes === 1 ? '' : 's',
        );

        if ($maxAgeMs > 0) {
            $parts[] = sprintf('worst-case age %s', $this->formatDurationMilliseconds($maxAgeMs));
        }

        return $parts !== [] ? implode('; ', $parts).'.' : null;
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function durableResumePathAlertDetails(array $facts): ?string
    {
        $repairNeededRuns = $this->integerValue($facts['repair_needed_runs'] ?? 0);
        $missingCandidates = $this->integerValue($facts['missing_task_candidates'] ?? 0);
        $selectedCandidates = $this->integerValue($facts['selected_missing_task_candidates'] ?? 0);
        $waitingRuns = $this->integerValue($facts['waiting_runs'] ?? 0);
        $maxRepairNeededAgeMs = $this->integerValue($facts['max_repair_needed_age_ms'] ?? 0);
        $maxMissingRunAgeMs = $this->integerValue($facts['max_missing_run_age_ms'] ?? 0);
        $maxWaitAgeMs = $this->integerValue($facts['max_wait_age_ms'] ?? 0);

        $parts = [];

        if ($repairNeededRuns > 0) {
            $parts[] = sprintf(
                '%d repair-needed run%s',
                $repairNeededRuns,
                $repairNeededRuns === 1 ? '' : 's',
            );
        }

        if ($maxRepairNeededAgeMs > 0) {
            $parts[] = sprintf(
                'oldest repair-needed run age %s',
                $this->formatDurationMilliseconds($maxRepairNeededAgeMs),
            );
        }

        if ($missingCandidates > 0) {
            $parts[] = sprintf(
                '%d missing-task candidate%s (%d selected this pass)',
                $missingCandidates,
                $missingCandidates === 1 ? '' : 's',
                $selectedCandidates,
            );
        }

        if ($maxMissingRunAgeMs > 0) {
            $parts[] = sprintf('oldest missing-run age %s', $this->formatDurationMilliseconds($maxMissingRunAgeMs));
        }

        if ($waitingRuns > 0) {
            $parts[] = sprintf(
                '%d waiting run%s with worst wait %s',
                $waitingRuns,
                $waitingRuns === 1 ? '' : 's',
                $this->formatDurationMilliseconds($maxWaitAgeMs),
            );
        }

        return $parts !== [] ? ucfirst(implode('; ', $parts)).'.' : null;
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function workerCompatibilityAlertDetails(array $facts): ?string
    {
        $requiredCompatibility = is_string($facts['required_compatibility'] ?? null)
            ? trim((string) $facts['required_compatibility'])
            : '';
        $activeWorkers = $this->integerValue($facts['active_workers'] ?? 0);
        $activeWorkerScopes = $this->integerValue($facts['active_worker_scopes'] ?? 0);
        $supportingWorkers = $this->integerValue($facts['active_workers_supporting_required'] ?? 0);
        $validationMode = is_string($facts['validation_mode'] ?? null)
            ? trim((string) $facts['validation_mode'])
            : 'warn';

        if ($requiredCompatibility === '') {
            return null;
        }

        return sprintf(
            'Required marker %s has %d supporting worker%s across %d active scope%s (%d active worker%s total); validation mode is %s.',
            $requiredCompatibility,
            $supportingWorkers,
            $supportingWorkers === 1 ? '' : 's',
            $activeWorkerScopes,
            $activeWorkerScopes === 1 ? '' : 's',
            $activeWorkers,
            $activeWorkers === 1 ? '' : 's',
            $validationMode,
        );
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function commandContractAlertDetails(array $facts): ?string
    {
        $needed = $this->integerValue($facts['backfill_needed_runs'] ?? 0);

        if ($needed <= 0) {
            return null;
        }

        return sprintf(
            '%d run%s need command-contract backfill; %d can be backfilled from history and %d still lack recoverable snapshots.',
            $needed,
            $needed === 1 ? '' : 's',
            $this->integerValue($facts['backfill_available_runs'] ?? 0),
            $this->integerValue($facts['backfill_unavailable_runs'] ?? 0),
        );
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function schedulerRoleAlertDetails(array $facts): ?string
    {
        $missed = $this->integerValue($facts['missed'] ?? 0);

        if ($missed <= 0) {
            return null;
        }

        return sprintf(
            '%d active schedule%s are overdue; worst overdue age %s (%d fires, %d failures recorded).',
            $missed,
            $missed === 1 ? '' : 's',
            $this->formatDurationMilliseconds($this->integerValue($facts['max_overdue_ms'] ?? 0)),
            $this->integerValue($facts['fires_total'] ?? 0),
            $this->integerValue($facts['failures_total'] ?? 0),
        );
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function longPollWakeAlertDetails(array $facts): ?string
    {
        $backend = is_string($facts['backend'] ?? null) ? trim((string) $facts['backend']) : 'unknown';
        $reason = is_string($facts['reason'] ?? null) ? trim((string) $facts['reason']) : '';

        if ($reason === '') {
            return sprintf(
                'Wake acceleration backend %s is not currently reporting a healthy multi-node posture.',
                $backend,
            );
        }

        return sprintf('Wake acceleration backend %s reports: %s', $backend, $reason);
    }

    /**
     * @param  array<string, mixed>  $queueVisibility
     * @return array<int, array<string, mixed>>
     */
    private function queueVisibilityAlerts(array $queueVisibility): array
    {
        $namespace = is_string($queueVisibility['namespace'] ?? null) && trim((string) $queueVisibility['namespace']) !== ''
            ? trim((string) $queueVisibility['namespace'])
            : null;

        if (($queueVisibility['available'] ?? false) !== true) {
            $reason = is_string($queueVisibility['reason'] ?? null)
                ? trim((string) $queueVisibility['reason'])
                : 'Queue visibility is unavailable for this scope.';

            if ($namespace === null) {
                return [];
            }

            return [[
                'key' => 'queue_visibility_unavailable',
                'source' => 'queue_visibility',
                'status' => 'warning',
                'title' => 'Queue visibility unavailable',
                'summary' => $reason,
                'details' => null,
                'namespace' => $namespace,
                'queue_count' => 0,
                'queues' => [],
            ]];
        }

        $taskQueues = array_values(array_filter(
            is_array($queueVisibility['task_queues'] ?? null) ? $queueVisibility['task_queues'] : [],
            static fn (mixed $taskQueue): bool => is_array($taskQueue),
        ));

        if ($taskQueues === []) {
            return [];
        }

        $alerts = [];

        $backlogWithoutPollers = array_values(array_filter(
            $taskQueues,
            fn (array $taskQueue): bool => $this->taskQueueBacklog($taskQueue) > 0
                && $this->taskQueueActivePollers($taskQueue) === 0,
        ));
        if ($backlogWithoutPollers !== []) {
            $queues = $this->taskQueueNames($backlogWithoutPollers);
            $backlogCount = array_sum(array_map(
                fn (array $taskQueue): int => $this->taskQueueBacklog($taskQueue),
                $backlogWithoutPollers,
            ));

            $alerts[] = [
                'key' => 'queue_backlog_without_pollers',
                'source' => 'queue_visibility',
                'status' => 'error',
                'title' => 'Queued work has no active pollers',
                'summary' => sprintf(
                    '%d queue%s have backlog but no active pollers.',
                    count($queues),
                    count($queues) === 1 ? '' : 's',
                ),
                'details' => sprintf(
                    '%d queued task%s currently wait on %s.',
                    $backlogCount,
                    $backlogCount === 1 ? '' : 's',
                    $this->queueListLabel($queues),
                ),
                'namespace' => $namespace,
                'queue_count' => count($queues),
                'queues' => $queues,
                'backlog_count' => $backlogCount,
            ];
        }

        $stalePollers = array_values(array_filter(
            $taskQueues,
            fn (array $taskQueue): bool => $this->taskQueueStalePollers($taskQueue) > 0,
        ));
        if ($stalePollers !== []) {
            $queues = $this->taskQueueNames($stalePollers);
            $stalePollerCount = array_sum(array_map(
                fn (array $taskQueue): int => $this->taskQueueStalePollers($taskQueue),
                $stalePollers,
            ));

            $alerts[] = [
                'key' => 'queue_stale_pollers',
                'source' => 'queue_visibility',
                'status' => 'warning',
                'title' => 'Stale pollers detected',
                'summary' => sprintf(
                    '%d queue%s report stale pollers.',
                    count($queues),
                    count($queues) === 1 ? '' : 's',
                ),
                'details' => sprintf(
                    '%d stale poller%s observed on %s.',
                    $stalePollerCount,
                    $stalePollerCount === 1 ? '' : 's',
                    $this->queueListLabel($queues),
                ),
                'namespace' => $namespace,
                'queue_count' => count($queues),
                'queues' => $queues,
                'stale_poller_count' => $stalePollerCount,
            ];
        }

        $repairCandidates = array_values(array_filter(
            $taskQueues,
            fn (array $taskQueue): bool => $this->taskQueueRepairCandidates($taskQueue) > 0,
        ));
        if ($repairCandidates !== []) {
            $queues = $this->taskQueueNames($repairCandidates);
            $candidateCount = array_sum(array_map(
                fn (array $taskQueue): int => $this->taskQueueRepairCandidates($taskQueue),
                $repairCandidates,
            ));
            $maxAgeMs = max(array_map(
                fn (array $taskQueue): int => $this->taskQueueMaxRepairAge($taskQueue),
                $repairCandidates,
            ));

            $alerts[] = [
                'key' => 'queue_repair_candidates',
                'source' => 'queue_visibility',
                'status' => 'warning',
                'title' => 'Repair candidates need attention',
                'summary' => sprintf(
                    '%d queue%s have repair candidates waiting.',
                    count($queues),
                    count($queues) === 1 ? '' : 's',
                ),
                'details' => sprintf(
                    '%d repair candidate%s visible on %s; worst age %s.',
                    $candidateCount,
                    $candidateCount === 1 ? '' : 's',
                    $this->queueListLabel($queues),
                    $this->formatDurationMilliseconds($maxAgeMs),
                ),
                'namespace' => $namespace,
                'queue_count' => count($queues),
                'queues' => $queues,
                'candidate_count' => $candidateCount,
                'max_age_ms' => $maxAgeMs,
            ];
        }

        return $alerts;
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
        ];

        if (self::legacyHealthCheckExists('routingHealthCheck')) {
            $checks[] = self::invokeLegacyHealthCheck(
                'routingHealthCheck',
                $metrics['tasks'] ?? [],
                $metrics['backlog'] ?? [],
                $metrics['matching_role'] ?? [],
                $metrics['workers'] ?? [],
            );
        }

        $checks[] = self::invokeLegacyHealthCheck(
            'durableResumePathCheck',
            $metrics['backlog'] ?? [],
            $metrics['repair'] ?? [],
            $metrics['runs'] ?? [],
        );
        $checks[] = self::invokeLegacyHealthCheck('workerCompatibilityCheck', $metrics['workers'] ?? []);
        $checks[] = self::invokeLegacyHealthCheck('schedulerRoleCheck', $metrics['schedules'] ?? []);
        $checks[] = self::invokeLegacyHealthCheck('longPollWakeAccelerationCheck');
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

    private static function legacyHealthCheckExists(string $method): bool
    {
        return method_exists(HealthCheck::class, $method);
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

    /**
     * @param  array<string, mixed>  $taskQueue
     */
    private function taskQueueBacklog(array $taskQueue): int
    {
        return $this->integerValue($taskQueue['stats']['approximate_backlog_count'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $taskQueue
     */
    private function taskQueueActivePollers(array $taskQueue): int
    {
        return $this->integerValue($taskQueue['stats']['pollers']['active_count'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $taskQueue
     */
    private function taskQueueStalePollers(array $taskQueue): int
    {
        return $this->integerValue($taskQueue['stats']['pollers']['stale_count'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $taskQueue
     */
    private function taskQueueRepairCandidates(array $taskQueue): int
    {
        return $this->integerValue($taskQueue['repair']['candidates'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $taskQueue
     */
    private function taskQueueMaxRepairAge(array $taskQueue): int
    {
        $repair = is_array($taskQueue['repair'] ?? null) ? $taskQueue['repair'] : [];

        return max([
            $this->integerValue($repair['max_dispatch_failed_age_ms'] ?? 0),
            $this->integerValue($repair['max_lease_expired_age_ms'] ?? 0),
            $this->integerValue($repair['max_dispatch_overdue_age_ms'] ?? 0),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $taskQueues
     * @return array<int, string>
     */
    private function taskQueueNames(array $taskQueues): array
    {
        $names = array_values(array_unique(array_map(
            fn (array $taskQueue): string => $this->queueName($taskQueue),
            $taskQueues,
        )));

        sort($names);

        return $names;
    }

    /**
     * @param  array<int, string>  $queues
     */
    private function queueListLabel(array $queues): string
    {
        $sample = array_slice($queues, 0, 3);
        $label = implode(', ', $sample);
        $remaining = count($queues) - count($sample);

        return $remaining > 0
            ? sprintf('%s +%d more', $label, $remaining)
            : $label;
    }

    private function integerValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function humanizeAlertKey(string $key): string
    {
        return ucwords(str_replace('_', ' ', trim($key)));
    }

    private function formatDurationMilliseconds(int $milliseconds): string
    {
        if ($milliseconds <= 0) {
            return 'fresh';
        }

        if ($milliseconds < 1000) {
            return '<1s';
        }

        $seconds = (int) floor($milliseconds / 1000);
        if ($seconds < 60) {
            return sprintf('%ds', $seconds);
        }

        if ($seconds < 3600) {
            return sprintf('%dm%02ds', (int) floor($seconds / 60), $seconds % 60);
        }

        return sprintf(
            '%dh%02dm%02ds',
            (int) floor($seconds / 3600),
            (int) floor(($seconds % 3600) / 60),
            $seconds % 60,
        );
    }

    private function namespace(): ?string
    {
        $namespace = config('waterline.namespace');

        return is_string($namespace) && trim($namespace) !== '' ? trim($namespace) : null;
    }
}
