<?php

namespace Waterline\Http\Controllers;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;
use Waterline\Models\WorkerBuildIdRollout;
use Waterline\Models\WorkerRegistration;
use Waterline\Support\OperatorScope;
use Waterline\Support\WorkflowEngineSourceResolver;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Support\HealthCheck;
use Workflow\V2\Support\StandaloneWorkerVisibility;
use Workflow\V2\Support\TaskRepairPolicy;
use Workflow\V2\Models\WorkflowTask;

class V2HealthController extends Controller
{
    public function show()
    {
        $engineSource = WorkflowEngineSourceResolver::status();
        $namespace = $this->namespace();
        $routingDrains = $this->routingDrains($namespace);

        if (($engineSource['uses_v2'] ?? false) !== true) {
            $payload = [
                'namespace' => $namespace,
                'operator_scope' => OperatorScope::payload(),
                'queue_visibility' => $this->emptyQueueVisibility(
                    $namespace,
                    'Queue visibility is unavailable until Waterline uses the v2 operator bridge.',
                ),
                'routing_drains' => $routingDrains,
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
                $routingDrains,
            );

            return response()->json($payload, 503);
        }

        try {
            $snapshot = $this->snapshotForConfiguredNamespace();
        } catch (Throwable) {
            $snapshot = $this->degradedSnapshotForConfiguredNamespace();
        }
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
        $snapshot['operator_scope'] = OperatorScope::payload();
        $snapshot['queue_visibility'] = $this->queueVisibility($namespace);
        $snapshot['routing_drains'] = $routingDrains;
        $snapshot['worker_versioning'] = $this->workerVersioningVisibility(
            $namespace,
            $snapshot['queue_visibility'],
            $routingDrains,
        );
        $snapshot['engine_source'] = $engineSource;
        $snapshot['readiness_contract'] = $engineSource['readiness_contract'] ?? null;
        $snapshot = $this->annotateWorkerRegistrations($snapshot, $namespace);
        $snapshot['coordination_alerts'] = $this->coordinationAlerts(
            $snapshot['checks'],
            $snapshot['queue_visibility'],
            $routingDrains,
        );

        return response()->json($snapshot, HealthCheck::httpStatus($snapshot));
    }

    /**
     * @return array<string, mixed>
     */
    private function degradedSnapshotForConfiguredNamespace(): array
    {
        return [
            'generated_at' => now()->toJSON(),
            'status' => 'warning',
            'healthy' => true,
            'checks' => [
                [
                    'name' => 'operator_snapshot',
                    'status' => 'warning',
                    'category' => 'correctness',
                    'message' => 'Waterline health metrics were unavailable; durable selected-run views remain readable when core v2 tables are present.',
                    'data' => [
                        'reason' => 'operator_metrics_unavailable',
                    ],
                ],
            ],
            'categories' => [
                'correctness' => [
                    'status' => 'warning',
                    'check_count' => 1,
                ],
                'acceleration' => [
                    'status' => 'ok',
                    'check_count' => 0,
                ],
            ],
            'operator_metrics' => [],
            'structural_limits' => [],
        ];
    }

    /**
     * Surface the per-worker registration roster (with task-slot availability
     * and basic process metrics) onto the snapshot so the operator Worker
     * Status view can answer "what workers are polling task queue X right
     * now, what's their slot capacity, when did each last check in" without
     * extra round-trips. Fleet entries from the workflow package's operator
     * metrics surface remain present as a fallback for older clients.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function annotateWorkerRegistrations(array $snapshot, ?string $namespace): array
    {
        if ($namespace === null) {
            return $snapshot;
        }

        if (! $this->modelTableExists(new WorkerRegistration())) {
            return $snapshot;
        }

        $staleAfter = $this->workerStaleAfterSeconds();
        $now = now();

        $registrations = WorkerRegistration::query()
            ->where('namespace', $namespace)
            ->orderByDesc('last_heartbeat_at')
            ->get();

        $registrationPayload = [];
        $staleRegistrationPayload = [];
        foreach ($registrations as $worker) {
            $isStale = ! ($worker->last_heartbeat_at instanceof CarbonInterface)
                || $worker->last_heartbeat_at->lt($now->copy()->subSeconds($staleAfter));

            $workerPayload = [
                'worker_id' => (string) $worker->worker_id,
                'namespace' => $worker->namespace,
                'task_queue' => $worker->task_queue,
                'runtime' => $worker->runtime,
                'sdk_version' => $worker->sdk_version,
                'build_id' => $worker->build_id,
                'supported_compatibility' => is_string($worker->build_id) && trim((string) $worker->build_id) !== ''
                    ? [trim((string) $worker->build_id)]
                    : [],
                'status' => $isStale ? 'stale' : ($worker->status ?? 'active'),
                'last_heartbeat_at' => $worker->last_heartbeat_at instanceof CarbonInterface
                    ? $worker->last_heartbeat_at->toJSON()
                    : null,
                'supported_workflow_types' => is_array($worker->supported_workflow_types ?? null)
                    ? array_values($worker->supported_workflow_types)
                    : [],
                'supported_activity_types' => is_array($worker->supported_activity_types ?? null)
                    ? array_values($worker->supported_activity_types)
                    : [],
                'max_concurrent_workflow_tasks' => $worker->max_concurrent_workflow_tasks,
                'max_concurrent_activity_tasks' => $worker->max_concurrent_activity_tasks,
                'max_concurrent_worker_sessions' => $worker->max_concurrent_worker_sessions ?? null,
                'task_slots' => [
                    'workflow_available' => $worker->available_workflow_slots ?? null,
                    'activity_available' => $worker->available_activity_slots ?? null,
                    'session_available' => $worker->available_session_slots ?? null,
                    'workflow_capacity' => $worker->max_concurrent_workflow_tasks,
                    'activity_capacity' => $worker->max_concurrent_activity_tasks,
                    'session_capacity' => $worker->max_concurrent_worker_sessions ?? null,
                ],
                'process_metrics' => is_array($worker->process_metrics ?? null) && $worker->process_metrics !== []
                    ? $worker->process_metrics
                    : null,
                'heartbeat_interval_seconds' => $worker->heartbeat_interval_seconds ?? null,
            ];

            if ($isStale) {
                $staleRegistrationPayload[] = $workerPayload;

                continue;
            }

            $registrationPayload[] = $workerPayload;
        }

        if ($registrationPayload === [] && $staleRegistrationPayload === []) {
            return $snapshot;
        }

        $operatorMetrics = is_array($snapshot['operator_metrics'] ?? null)
            ? $snapshot['operator_metrics']
            : [];
        $workersBlock = is_array($operatorMetrics['workers'] ?? null)
            ? $operatorMetrics['workers']
            : [];
        $workersBlock['registrations'] = $registrationPayload;
        $workersBlock['stale_registrations'] = $staleRegistrationPayload;
        $workersBlock['registration_count'] = count($registrationPayload) + count($staleRegistrationPayload);
        $workersBlock['stale_registration_count'] = count($staleRegistrationPayload);
        $workersBlock['stale_after_seconds'] = $staleAfter;
        $workersBlock['worker_versioning'] = $this->workerVersioningWorkersSummary(
            array_merge($registrationPayload, $staleRegistrationPayload),
        );
        $operatorMetrics['workers'] = $workersBlock;
        $snapshot['operator_metrics'] = $operatorMetrics;

        return $snapshot;
    }

    private function workerStaleAfterSeconds(): int
    {
        $configured = config('waterline.worker_stale_after_seconds');
        if (is_numeric($configured) && (int) $configured > 0) {
            return (int) $configured;
        }

        $serverConfigured = config('server.workers.stale_after_seconds');
        if (is_numeric($serverConfigured) && (int) $serverConfigured > 0) {
            return (int) $serverConfigured;
        }

        return 300;
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     * @param  array<string, mixed>  $queueVisibility
     * @param  array<string, mixed>  $routingDrains
     * @return array<int, array<string, mixed>>
     */
    private function coordinationAlerts(array $checks, array $queueVisibility, array $routingDrains): array
    {
        return array_values(array_merge(
            $this->healthCheckAlerts($checks, $routingDrains),
            $this->queueVisibilityAlerts($queueVisibility),
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     * @param  array<string, mixed>  $routingDrains
     * @return array<int, array<string, mixed>>
     */
    private function healthCheckAlerts(array $checks, array $routingDrains): array
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
            if ($key === 'routing_health') {
                $facts = array_merge($facts, [
                    'queues_with_drains' => $this->integerValue($routingDrains['queues_with_drains'] ?? 0),
                    'draining_build_id_count' => $this->integerValue($routingDrains['draining_build_id_count'] ?? 0),
                    'active_worker_count' => $this->integerValue($routingDrains['active_worker_count'] ?? 0),
                    'draining_worker_count' => $this->integerValue($routingDrains['draining_worker_count'] ?? 0),
                    'stale_worker_count' => $this->integerValue($routingDrains['stale_worker_count'] ?? 0),
                    'routing_drains' => $routingDrains,
                ]);
            }

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
            'activity_path' => $this->activityPathAlertDetails($facts),
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
    private function activityPathAlertDetails(array $facts): ?string
    {
        $timeoutOverdue = $this->integerValue($facts['timeout_overdue'] ?? 0);
        $retrying = $this->integerValue($facts['retrying'] ?? 0);
        $maxTimeoutOverdueAgeMs = $this->integerValue($facts['max_timeout_overdue_age_ms'] ?? 0);
        $maxRetryingAgeMs = $this->integerValue($facts['max_retrying_age_ms'] ?? 0);
        $maxAttemptCount = $this->integerValue($facts['max_attempt_count'] ?? 0);

        $parts = [];

        if ($timeoutOverdue > 0) {
            $parts[] = sprintf(
                '%d activity execution%s past a schedule-to-start, start-to-close, schedule-to-close, or heartbeat deadline without enforcement',
                $timeoutOverdue,
                $timeoutOverdue === 1 ? '' : 's',
            );
        }

        if ($maxTimeoutOverdueAgeMs > 0) {
            $parts[] = sprintf(
                'worst-case overdue age %s',
                $this->formatDurationMilliseconds($maxTimeoutOverdueAgeMs),
            );
        }

        if ($retrying > 0) {
            $parts[] = sprintf(
                '%d activity execution%s in the retry backlog',
                $retrying,
                $retrying === 1 ? '' : 's',
            );
        }

        if ($maxRetryingAgeMs > 0) {
            $parts[] = sprintf(
                'worst-case retry age %s',
                $this->formatDurationMilliseconds($maxRetryingAgeMs),
            );
        }

        if ($maxAttemptCount > 0) {
            $parts[] = sprintf('highest attempt count %d', $maxAttemptCount);
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

        $routingDrains = is_array($facts['routing_drains'] ?? null) ? $facts['routing_drains'] : [];
        $drainQueues = is_array($routingDrains['queues'] ?? null) ? $routingDrains['queues'] : [];
        $drainQueueLabels = [];

        foreach (array_slice($drainQueues, 0, 3) as $queue) {
            if (! is_array($queue)) {
                continue;
            }

            $taskQueue = is_string($queue['task_queue'] ?? null) && trim((string) $queue['task_queue']) !== ''
                ? trim((string) $queue['task_queue'])
                : 'default';
            $buildIds = is_array($queue['build_ids'] ?? null) ? $queue['build_ids'] : [];
            $buildLabels = [];

            foreach ($buildIds as $build) {
                if (! is_array($build)) {
                    continue;
                }

                $buildLabel = is_string($build['build_id'] ?? null) && trim((string) $build['build_id']) !== ''
                    ? trim((string) $build['build_id'])
                    : 'unversioned';
                $buildLabels[] = $buildLabel;
            }

            $buildLabels = array_values(array_unique($buildLabels));

            if ($buildLabels === []) {
                $drainQueueLabels[] = $taskQueue;
                continue;
            }

            $drainQueueLabels[] = sprintf('%s (%s)', $taskQueue, implode(', ', array_slice($buildLabels, 0, 2)));
        }

        if ($drainQueueLabels !== []) {
            $queueSuffix = count($drainQueues) > count($drainQueueLabels)
                ? sprintf(' and %d more queue%s', count($drainQueues) - count($drainQueueLabels), count($drainQueues) - count($drainQueueLabels) === 1 ? '' : 's')
                : '';
            $parts[] = sprintf('draining cohorts %s%s', implode('; ', $drainQueueLabels), $queueSuffix);
        }

        return $parts !== [] ? implode('; ', $parts).'.' : null;
    }

    /**
     * @return array{
     *     queues_with_drains: int,
     *     draining_build_id_count: int,
     *     active_worker_count: int,
     *     draining_worker_count: int,
     *     stale_worker_count: int,
     *     queues: array<int, array<string, mixed>>
     * }
     */
    private function routingDrains(?string $namespace): array
    {
        if ($namespace === null) {
            return $this->emptyRoutingDrains();
        }

        if (! $this->modelTableExists(new WorkerRegistration())
            || ! $this->modelTableExists(new WorkerBuildIdRollout())) {
            return $this->emptyRoutingDrains();
        }

        $rollouts = WorkerBuildIdRollout::query()
            ->where('namespace', $namespace)
            ->where('drain_intent', WorkerBuildIdRollout::DRAIN_INTENT_DRAINING)
            ->orderBy('task_queue')
            ->orderBy('build_id')
            ->get();

        if ($rollouts->isEmpty()) {
            return $this->emptyRoutingDrains();
        }

        $now = now();
        $staleAfterSeconds = StandaloneWorkerVisibility::staleAfterSeconds();
        $workers = WorkerRegistration::query()
            ->where('namespace', $namespace)
            ->orderBy('task_queue')
            ->orderByDesc('last_heartbeat_at')
            ->orderBy('worker_id')
            ->get();

        $workersByQueue = [];
        foreach ($workers as $worker) {
            $queueKey = $this->routingDrainQueueKey($worker->task_queue);
            $workersByQueue[$queueKey] ??= [];
            $workersByQueue[$queueKey][] = $worker;
        }

        $queues = [];

        foreach ($rollouts as $rollout) {
            $queueKey = $this->routingDrainQueueKey($rollout->task_queue);
            $queueWorkers = $workersByQueue[$queueKey] ?? [];

            if (! isset($queues[$queueKey])) {
                $queueCounts = $this->routingDrainWorkerCounts($queueWorkers, $now, $staleAfterSeconds);
                $queues[$queueKey] = [
                    'namespace' => $namespace,
                    'task_queue' => $queueKey,
                    'draining_build_id_count' => 0,
                    'active_worker_count' => $queueCounts['active_worker_count'],
                    'draining_worker_count' => $queueCounts['draining_worker_count'],
                    'stale_worker_count' => $queueCounts['stale_worker_count'],
                    'build_ids' => [],
                ];
            }

            $buildWorkers = array_values(array_filter(
                $queueWorkers,
                fn (WorkerRegistration $worker): bool => $this->routingDrainBuildIdKey($worker->build_id) === (string) $rollout->build_id,
            ));
            $buildCounts = $this->routingDrainWorkerCounts($buildWorkers, $now, $staleAfterSeconds);

            $queues[$queueKey]['draining_build_id_count']++;
            $queues[$queueKey]['build_ids'][] = [
                'build_id' => $rollout->publicBuildId(),
                'drain_intent' => (string) $rollout->drain_intent,
                'drained_at' => $rollout->drained_at?->toJSON(),
                'active_worker_count' => $buildCounts['active_worker_count'],
                'draining_worker_count' => $buildCounts['draining_worker_count'],
                'stale_worker_count' => $buildCounts['stale_worker_count'],
                'total_worker_count' => $buildCounts['total_worker_count'],
            ];
        }

        ksort($queues);

        $queueSummaries = array_values(array_map(function (array $queue): array {
            usort($queue['build_ids'], function (array $left, array $right): int {
                return strcmp(
                    $left['build_id'] ?? '',
                    $right['build_id'] ?? '',
                );
            });

            return $queue;
        }, $queues));

        return [
            'queues_with_drains' => count($queueSummaries),
            'draining_build_id_count' => array_sum(array_map(
                fn (array $queue): int => $this->integerValue($queue['draining_build_id_count'] ?? 0),
                $queueSummaries,
            )),
            'active_worker_count' => array_sum(array_map(
                fn (array $queue): int => $this->integerValue($queue['active_worker_count'] ?? 0),
                $queueSummaries,
            )),
            'draining_worker_count' => array_sum(array_map(
                fn (array $queue): int => $this->integerValue($queue['draining_worker_count'] ?? 0),
                $queueSummaries,
            )),
            'stale_worker_count' => array_sum(array_map(
                fn (array $queue): int => $this->integerValue($queue['stale_worker_count'] ?? 0),
                $queueSummaries,
            )),
            'queues' => $queueSummaries,
        ];
    }

    /**
     * @param  array<int, WorkerRegistration>  $workers
     * @return array{
     *     active_worker_count: int,
     *     draining_worker_count: int,
     *     stale_worker_count: int,
     *     total_worker_count: int
     * }
     */
    private function routingDrainWorkerCounts(array $workers, CarbonInterface $now, int $staleAfterSeconds): array
    {
        $counts = [
            'active_worker_count' => 0,
            'draining_worker_count' => 0,
            'stale_worker_count' => 0,
            'total_worker_count' => count($workers),
        ];

        foreach ($workers as $worker) {
            $status = $this->routingDrainWorkerStatus($worker, $now, $staleAfterSeconds);

            if ($status === 'stale') {
                $counts['stale_worker_count']++;
            } elseif ($status === WorkerBuildIdRollout::DRAIN_INTENT_DRAINING) {
                $counts['draining_worker_count']++;
            } else {
                $counts['active_worker_count']++;
            }
        }

        return $counts;
    }

    private function routingDrainWorkerStatus(
        WorkerRegistration $worker,
        CarbonInterface $now,
        int $staleAfterSeconds,
    ): string {
        $heartbeat = $worker->last_heartbeat_at;

        if ($heartbeat instanceof CarbonInterface
            && $heartbeat->lt($now->copy()->subSeconds($staleAfterSeconds))) {
            return 'stale';
        }

        return is_string($worker->status) && trim($worker->status) !== ''
            ? trim($worker->status)
            : WorkerBuildIdRollout::DRAIN_INTENT_ACTIVE;
    }

    private function routingDrainQueueKey(mixed $taskQueue): string
    {
        return is_string($taskQueue) && trim($taskQueue) !== ''
            ? trim($taskQueue)
            : 'default';
    }

    private function routingDrainBuildIdKey(mixed $buildId): string
    {
        return WorkerBuildIdRollout::buildIdKey(is_string($buildId) ? $buildId : null);
    }

    /**
     * @return array{
     *     queues_with_drains: int,
     *     draining_build_id_count: int,
     *     active_worker_count: int,
     *     draining_worker_count: int,
     *     stale_worker_count: int,
     *     queues: array<int, array<string, mixed>>
     * }
     */
    private function emptyRoutingDrains(): array
    {
        return [
            'queues_with_drains' => 0,
            'draining_build_id_count' => 0,
            'active_worker_count' => 0,
            'draining_worker_count' => 0,
            'stale_worker_count' => 0,
            'queues' => [],
        ];
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
     * Waterline relies on the namespace-scoped v2 health snapshot contract.
     * WorkflowPackageApiFloor asserts the required signature at boot whenever
     * the resolved engine source is v2.
     *
     * @return array<string, mixed>
     */
    private function snapshotForConfiguredNamespace(): array
    {
        $taskDispatchMode = $this->healthTaskDispatchMode();

        if ($taskDispatchMode === null) {
            return HealthCheck::snapshot(now(), $this->namespace());
        }

        $originalTaskDispatchMode = config('workflows.v2.task_dispatch_mode');
        config(['workflows.v2.task_dispatch_mode' => $taskDispatchMode]);

        try {
            return HealthCheck::snapshot(now(), $this->namespace());
        } finally {
            config(['workflows.v2.task_dispatch_mode' => $originalTaskDispatchMode]);
        }
    }

    private function healthTaskDispatchMode(): ?string
    {
        $configured = config('waterline.health.task_dispatch_mode');

        if (! is_string($configured)) {
            return null;
        }

        $normalized = strtolower(trim($configured));

        return in_array($normalized, ['poll', 'queue'], true) ? $normalized : null;
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

        if (! $this->modelTableExists(new WorkerRegistration())) {
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

    private function modelTableExists(Model $model): bool
    {
        try {
            return DB::connection($model->getConnectionName())
                ->getSchemaBuilder()
                ->hasTable($model->getTable());
        } catch (Throwable) {
            return false;
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
            $payload['task_queues'] = [];
        }

        $payload['task_queues'] = $this->withWorkerVersioningTaskQueueScopes(
            $namespace,
            $payload['task_queues'],
        );
        $payload['task_queues'] = array_map(
            fn (array $taskQueue): array => $this->withWorkerVersioningQueueVisibility(
                $namespace,
                $this->withQueueVisibilityContract(
                    $this->withQueueRepairAgeFallback(
                        $namespace,
                        $this->withRecentTaskFlow($namespace, $taskQueue, $now),
                        $now,
                    ),
                ),
                $now,
            ),
            $payload['task_queues'],
        );

        return $payload;
    }

    /**
     * @param  array<int, mixed>  $taskQueues
     * @return array<int, array<string, mixed>>
     */
    private function withWorkerVersioningTaskQueueScopes(string $namespace, array $taskQueues): array
    {
        $normalized = array_values(array_filter($taskQueues, 'is_array'));
        $seen = [];

        foreach ($normalized as $taskQueue) {
            $seen[$this->queueName($taskQueue)] = true;
        }

        foreach ($this->workerVersioningTaskQueueNames($namespace) as $name) {
            if (isset($seen[$name])) {
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'task_queue' => $name,
                'stats' => [],
                'pollers' => [],
                'current_leases' => [],
                'repair' => [],
            ];
            $seen[$name] = true;
        }

        return $normalized;
    }

    /**
     * @return array<int, string>
     */
    private function workerVersioningTaskQueueNames(string $namespace): array
    {
        $names = [];

        foreach ([new WorkerRegistration(), new WorkerBuildIdRollout()] as $model) {
            if (! $this->modelTableExists($model)) {
                continue;
            }

            try {
                $modelClass = $model::class;
                $rows = $modelClass::query()
                    ->select('task_queue')
                    ->where('namespace', $namespace)
                    ->distinct()
                    ->get();
            } catch (Throwable) {
                continue;
            }

            foreach ($rows as $row) {
                if (is_string($row->task_queue ?? null) && trim((string) $row->task_queue) !== '') {
                    $names[trim((string) $row->task_queue)] = true;
                }
            }
        }

        $values = array_keys($names);
        sort($values);

        return $values;
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
        foreach (['task_queue', 'taskQueue', 'name'] as $key) {
            if (is_string($taskQueue[$key] ?? null) && trim((string) $taskQueue[$key]) !== '') {
                return trim((string) $taskQueue[$key]);
            }
        }

        return 'default';
    }

    /**
     * @param  array<string, mixed>  $taskQueue
     * @return array<string, mixed>
     */
    private function withWorkerVersioningQueueVisibility(
        string $namespace,
        array $taskQueue,
        CarbonInterface $now,
    ): array {
        $queueName = $this->queueName($taskQueue);
        $workers = $this->workerRowsForTaskQueue($namespace, $queueName, $now);
        $buildIds = $this->buildIdRowsForTaskQueue($namespace, $queueName, $workers);

        $taskQueue['task_queue'] = $queueName;
        $taskQueue['workers'] = $workers;
        $taskQueue['build_ids'] = $buildIds;
        $taskQueue['rollout_state'] = [
            'namespace' => $namespace,
            'task_queue' => $queueName,
            'build_ids' => $buildIds,
            'selected_new_start_build_id' => $this->selectedNewStartBuildId($buildIds),
            'cohort_count' => count($buildIds),
        ];

        return $taskQueue;
    }

    /**
     * @param  array<string, mixed>  $queueVisibility
     * @param  array<string, mixed>  $routingDrains
     * @return array<string, mixed>
     */
    private function workerVersioningVisibility(
        ?string $namespace,
        array $queueVisibility,
        array $routingDrains,
    ): array {
        $taskQueues = is_array($queueVisibility['task_queues'] ?? null)
            ? array_values(array_filter($queueVisibility['task_queues'], 'is_array'))
            : [];
        $buildIds = [];
        $workers = [];

        foreach ($taskQueues as $taskQueue) {
            foreach (is_array($taskQueue['build_ids'] ?? null) ? $taskQueue['build_ids'] : [] as $buildId) {
                if (is_array($buildId)) {
                    $buildIds[] = $buildId;
                }
            }

            foreach (is_array($taskQueue['workers'] ?? null) ? $taskQueue['workers'] : [] as $worker) {
                if (is_array($worker)) {
                    $workers[] = $worker;
                }
            }
        }

        return [
            'namespace' => $namespace,
            'available' => ($queueVisibility['available'] ?? false) === true,
            'task_queue_count' => count($taskQueues),
            'worker_count' => count($workers),
            'build_id_count' => count($buildIds),
            'worker_cohorts' => $this->workerVersioningBuildIds($buildIds),
            'workers' => $workers,
            'task_queues' => $taskQueues,
            'routing_drains' => $routingDrains,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $workers
     * @return array<string, mixed>
     */
    private function workerVersioningWorkersSummary(array $workers): array
    {
        $cohorts = array_values(array_unique(array_values(array_filter(array_map(
            fn (array $worker): ?string => is_string($worker['build_id'] ?? null) && trim((string) $worker['build_id']) !== ''
                ? trim((string) $worker['build_id'])
                : null,
            $workers,
        )))));
        sort($cohorts);

        return [
            'worker_count' => count($workers),
            'worker_cohorts' => $cohorts,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $buildIds
     * @return array<int, string>
     */
    private function workerVersioningBuildIds(array $buildIds): array
    {
        $values = array_values(array_unique(array_values(array_filter(array_map(
            fn (array $build): ?string => is_string($build['build_id'] ?? null) && trim((string) $build['build_id']) !== ''
                ? trim((string) $build['build_id'])
                : null,
            $buildIds,
        )))));

        sort($values);

        return $values;
    }

    /**
     * Fill queue-visibility defaults expected by published Waterline UI
     * surfaces when worker-versioning queues are synthesized from rollout rows
     * instead of returned directly by the workflow package.
     *
     * @param  array<string, mixed>  $taskQueue
     * @return array<string, mixed>
     */
    private function withQueueVisibilityContract(array $taskQueue): array
    {
        $stats = is_array($taskQueue['stats'] ?? null) ? $taskQueue['stats'] : [];
        $workflowTasks = is_array($stats['workflow_tasks'] ?? null) ? $stats['workflow_tasks'] : [];
        $activityTasks = is_array($stats['activity_tasks'] ?? null) ? $stats['activity_tasks'] : [];
        $pollers = is_array($stats['pollers'] ?? null) ? $stats['pollers'] : [];
        $defaultStats = $this->emptyQueueStats();

        $taskQueue['stats'] = array_replace($defaultStats, $stats);
        $taskQueue['stats']['workflow_tasks'] = array_replace(
            $defaultStats['workflow_tasks'],
            $workflowTasks,
        );
        $taskQueue['stats']['activity_tasks'] = array_replace(
            $defaultStats['activity_tasks'],
            $activityTasks,
        );
        $taskQueue['stats']['pollers'] = array_replace(
            $defaultStats['pollers'],
            $pollers,
        );

        if (! is_array($taskQueue['pollers'] ?? null)) {
            $taskQueue['pollers'] = [];
        }

        if (! is_array($taskQueue['current_leases'] ?? null)) {
            $taskQueue['current_leases'] = [];
        }

        $repair = is_array($taskQueue['repair'] ?? null) ? $taskQueue['repair'] : [];
        $repairPolicy = is_array($repair['policy'] ?? null) ? $repair['policy'] : [];
        $defaultRepair = $this->emptyQueueRepair();
        $taskQueue['repair'] = array_replace($defaultRepair, $repair);
        $taskQueue['repair']['policy'] = array_replace(
            $defaultRepair['policy'],
            $repairPolicy,
        );

        if (! array_key_exists('needs_attention', $repair)) {
            $taskQueue['repair']['needs_attention'] = ((int) ($taskQueue['repair']['candidates'] ?? 0)) > 0;
        }

        return $taskQueue;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyQueueStats(): array
    {
        $taskStats = [
            'ready_count' => 0,
            'leased_count' => 0,
            'expired_lease_count' => 0,
            'added_last_minute' => 0,
            'dispatched_last_minute' => 0,
        ];

        return [
            'approximate_backlog_count' => 0,
            'approximate_backlog_age' => null,
            'approximate_backlog_age_seconds' => null,
            'oldest_ready_task' => null,
            'workflow_tasks' => $taskStats,
            'activity_tasks' => $taskStats,
            'pollers' => [
                'active_count' => 0,
                'stale_count' => 0,
                'stale_after_seconds' => StandaloneWorkerVisibility::staleAfterSeconds(),
            ],
            'tasks_added_last_minute' => 0,
            'tasks_dispatched_last_minute' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyQueueRepair(): array
    {
        return [
            'candidates' => 0,
            'dispatch_failed' => 0,
            'oldest_dispatch_failed_at' => null,
            'max_dispatch_failed_age_ms' => 0,
            'expired_leases' => 0,
            'oldest_lease_expired_at' => null,
            'max_lease_expired_age_ms' => 0,
            'dispatch_overdue' => 0,
            'oldest_dispatch_overdue_since' => null,
            'max_dispatch_overdue_age_ms' => 0,
            'needs_attention' => false,
            'policy' => [
                'redispatch_after_seconds' => TaskRepairPolicy::redispatchAfterSeconds(),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function workerRowsForTaskQueue(string $namespace, string $taskQueue, CarbonInterface $now): array
    {
        if (! $this->modelTableExists(new WorkerRegistration())) {
            return [];
        }

        $staleAfter = $this->workerStaleAfterSeconds();

        try {
            return WorkerRegistration::query()
                ->where('namespace', $namespace)
                ->where('task_queue', $taskQueue)
                ->orderByDesc('last_heartbeat_at')
                ->orderBy('worker_id')
                ->get()
                ->map(fn (WorkerRegistration $worker): array => $this->workerVersioningWorkerRow($worker, $now, $staleAfter))
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function workerVersioningWorkerRow(
        WorkerRegistration $worker,
        CarbonInterface $now,
        int $staleAfter,
    ): array {
        $heartbeat = $worker->last_heartbeat_at;
        $isStale = ! ($heartbeat instanceof CarbonInterface)
            || $heartbeat->lt($now->copy()->subSeconds($staleAfter));

        return [
            'worker_id' => (string) $worker->worker_id,
            'namespace' => $worker->namespace,
            'task_queue' => $worker->task_queue,
            'runtime' => $worker->runtime,
            'sdk_version' => $worker->sdk_version,
            'build_id' => $worker->build_id,
            'supported_compatibility' => is_string($worker->build_id) && trim((string) $worker->build_id) !== ''
                ? [trim((string) $worker->build_id)]
                : [],
            'status' => $isStale ? 'stale' : ($worker->status ?? 'active'),
            'last_heartbeat_at' => $heartbeat instanceof CarbonInterface ? $heartbeat->toJSON() : null,
            'supported_workflow_types' => is_array($worker->supported_workflow_types ?? null)
                ? array_values($worker->supported_workflow_types)
                : [],
            'supported_activity_types' => is_array($worker->supported_activity_types ?? null)
                ? array_values($worker->supported_activity_types)
                : [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $workers
     * @return array<int, array<string, mixed>>
     */
    private function buildIdRowsForTaskQueue(string $namespace, string $taskQueue, array $workers): array
    {
        $groups = [];

        foreach ($workers as $worker) {
            $buildId = is_string($worker['build_id'] ?? null) && trim((string) $worker['build_id']) !== ''
                ? trim((string) $worker['build_id'])
                : null;
            $key = WorkerBuildIdRollout::buildIdKey($buildId);

            $groups[$key] ??= $this->emptyBuildIdGroup($buildId);
            $groups[$key]['total_worker_count']++;

            $status = is_string($worker['status'] ?? null) ? $worker['status'] : 'active';
            if ($status === 'stale') {
                $groups[$key]['stale_worker_count']++;
            } elseif ($status === WorkerBuildIdRollout::DRAIN_INTENT_DRAINING) {
                $groups[$key]['draining_worker_count']++;
            } else {
                $groups[$key]['active_worker_count']++;
            }

            foreach (['runtime' => 'runtimes', 'sdk_version' => 'sdk_versions'] as $workerKey => $groupKey) {
                if (is_string($worker[$workerKey] ?? null) && trim((string) $worker[$workerKey]) !== '') {
                    $groups[$key][$groupKey][trim((string) $worker[$workerKey])] = true;
                }
            }

            if (is_string($worker['last_heartbeat_at'] ?? null)) {
                $current = $groups[$key]['last_heartbeat_at'];
                if ($current === null || strcmp((string) $worker['last_heartbeat_at'], $current) > 0) {
                    $groups[$key]['last_heartbeat_at'] = (string) $worker['last_heartbeat_at'];
                }
            }
        }

        $rollouts = $this->rolloutRowsForTaskQueue($namespace, $taskQueue);
        foreach ($rollouts as $key => $rollout) {
            $groups[$key] ??= $this->emptyBuildIdGroup($rollout['build_id']);
            $groups[$key]['rollout'] = $rollout;
        }

        foreach ($this->pendingWorkflowTaskCountsForTaskQueue($namespace, $taskQueue) as $key => $pending) {
            $groups[$key] ??= $this->emptyBuildIdGroup($pending['build_id']);
            $groups[$key]['pending_workflow_tasks'] = $pending;
        }

        $selectedKey = $this->selectedNewStartBuildIdKey($rollouts);
        $buildIds = [];

        foreach ($groups as $key => $group) {
            $rollout = is_array($group['rollout'] ?? null) ? $group['rollout'] : [];
            $pending = is_array($group['pending_workflow_tasks'] ?? null)
                ? $group['pending_workflow_tasks']
                : $this->emptyPendingWorkflowTasks($group['build_id']);
            $buildIds[] = [
                'build_id' => $group['build_id'],
                'rollout_status' => $this->buildIdRolloutStatus(
                    $group['active_worker_count'],
                    $group['draining_worker_count'],
                    $group['stale_worker_count'],
                    is_string($rollout['drain_intent'] ?? null)
                        ? $rollout['drain_intent']
                        : WorkerBuildIdRollout::DRAIN_INTENT_ACTIVE,
                ),
                'drain_intent' => $rollout['drain_intent'] ?? WorkerBuildIdRollout::DRAIN_INTENT_ACTIVE,
                'drained_at' => $rollout['drained_at'] ?? null,
                'promoted_at' => $rollout['promoted_at'] ?? null,
                'rolled_back_at' => $rollout['rolled_back_at'] ?? null,
                'required_compatibility' => $rollout['required_compatibility'] ?? null,
                'compatibility_policy' => $rollout['compatibility_policy'] ?? null,
                'new_start_selected' => $key === $selectedKey,
                'active_worker_count' => $group['active_worker_count'],
                'draining_worker_count' => $group['draining_worker_count'],
                'stale_worker_count' => $group['stale_worker_count'],
                'total_worker_count' => $group['total_worker_count'],
                'runtimes' => $this->sortedKeys($group['runtimes']),
                'sdk_versions' => $this->sortedKeys($group['sdk_versions']),
                'last_heartbeat_at' => $group['last_heartbeat_at'],
                'pending_workflow_tasks' => $this->pendingWorkflowTaskDiagnostic(
                    $pending,
                    $group['active_worker_count'],
                ),
            ];
        }

        usort($buildIds, function (array $left, array $right): int {
            return strcmp((string) ($left['build_id'] ?? ''), (string) ($right['build_id'] ?? ''));
        });

        return $buildIds;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyBuildIdGroup(?string $buildId): array
    {
        return [
            'build_id' => $buildId,
            'active_worker_count' => 0,
            'stale_worker_count' => 0,
            'draining_worker_count' => 0,
            'total_worker_count' => 0,
            'runtimes' => [],
            'sdk_versions' => [],
            'last_heartbeat_at' => null,
            'rollout' => null,
            'pending_workflow_tasks' => $this->emptyPendingWorkflowTasks($buildId),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rolloutRowsForTaskQueue(string $namespace, string $taskQueue): array
    {
        if (! $this->modelTableExists(new WorkerBuildIdRollout())) {
            return [];
        }

        try {
            $query = WorkerBuildIdRollout::query()
                ->where('namespace', $namespace)
                ->where('task_queue', $taskQueue)
                ->orderBy('build_id');

            return $query->get()
                ->mapWithKeys(function (WorkerBuildIdRollout $rollout): array {
                    $key = (string) $rollout->build_id;

                    return [$key => [
                        'build_id' => $rollout->publicBuildId(),
                        'drain_intent' => is_string($rollout->drain_intent ?? null)
                            ? $rollout->drain_intent
                            : WorkerBuildIdRollout::DRAIN_INTENT_ACTIVE,
                        'drained_at' => $rollout->drained_at instanceof CarbonInterface
                            ? $rollout->drained_at->toJSON()
                            : null,
                        'promoted_at' => $rollout->promoted_at instanceof CarbonInterface
                            ? $rollout->promoted_at->toJSON()
                            : null,
                        'rolled_back_at' => $rollout->rolled_back_at instanceof CarbonInterface
                            ? $rollout->rolled_back_at->toJSON()
                            : null,
                        'required_compatibility' => is_string($rollout->required_compatibility ?? null)
                            ? $rollout->required_compatibility
                            : null,
                        'compatibility_policy' => is_string($rollout->compatibility_policy ?? null)
                            ? $rollout->compatibility_policy
                            : null,
                    ]];
                })
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $rollouts
     */
    private function selectedNewStartBuildIdKey(array $rollouts): ?string
    {
        $selectedKey = null;
        $selectedPromotedAt = null;

        foreach ($rollouts as $key => $rollout) {
            if (($rollout['drain_intent'] ?? null) !== WorkerBuildIdRollout::DRAIN_INTENT_ACTIVE) {
                continue;
            }

            if (($rollout['rolled_back_at'] ?? null) !== null) {
                continue;
            }

            $promotedAt = is_string($rollout['promoted_at'] ?? null) ? $rollout['promoted_at'] : null;
            if ($promotedAt === null) {
                continue;
            }

            if ($selectedPromotedAt === null || strcmp($promotedAt, $selectedPromotedAt) >= 0) {
                $selectedPromotedAt = $promotedAt;
                $selectedKey = $key;
            }
        }

        return $selectedKey;
    }

    /**
     * @param  array<int, array<string, mixed>>  $buildIds
     */
    private function selectedNewStartBuildId(array $buildIds): ?string
    {
        foreach ($buildIds as $buildId) {
            if (($buildId['new_start_selected'] ?? false) === true) {
                return is_string($buildId['build_id'] ?? null) ? $buildId['build_id'] : null;
            }
        }

        return null;
    }

    /**
     * @return array<string, array{build_id: string|null, total_count: int, ready_count: int, leased_count: int}>
     */
    private function pendingWorkflowTaskCountsForTaskQueue(string $namespace, string $taskQueue): array
    {
        try {
            if (! $this->modelTableExists(new WorkflowTask())) {
                return [];
            }

            $compatibilityExpression = "COALESCE(NULLIF(TRIM(workflow_tasks.compatibility), ''), "
                ."NULLIF(TRIM(workflow_runs.compatibility), ''))";

            $rows = WorkflowTask::query()
                ->toBase()
                ->select('workflow_tasks.status')
                ->selectRaw($compatibilityExpression.' as effective_compatibility')
                ->selectRaw('COUNT(*) as task_count')
                ->leftJoin('workflow_runs', 'workflow_runs.id', '=', 'workflow_tasks.workflow_run_id')
                ->where('workflow_tasks.namespace', $namespace)
                ->where('workflow_tasks.task_type', TaskType::Workflow->value)
                ->where('workflow_tasks.queue', $taskQueue)
                ->whereIn('workflow_tasks.status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
                ->groupBy('workflow_tasks.status')
                ->groupByRaw($compatibilityExpression)
                ->get();
        } catch (Throwable) {
            return [];
        }

        $counts = [];

        foreach ($rows as $row) {
            $compatibility = is_string($row->effective_compatibility ?? null)
                && trim((string) $row->effective_compatibility) !== ''
                ? trim((string) $row->effective_compatibility)
                : null;
            $key = WorkerBuildIdRollout::buildIdKey($compatibility);
            $counts[$key] ??= $this->emptyPendingWorkflowTasks($compatibility);

            $taskCount = max(0, (int) ($row->task_count ?? 0));
            if ($taskCount === 0) {
                continue;
            }

            $counts[$key]['total_count'] += $taskCount;

            $status = is_string($row->status ?? null) ? $row->status : null;
            if ($status === TaskStatus::Leased->value) {
                $counts[$key]['leased_count'] += $taskCount;
            } else {
                $counts[$key]['ready_count'] += $taskCount;
            }
        }

        return $counts;
    }

    /**
     * @return array{build_id: string|null, total_count: int, ready_count: int, leased_count: int}
     */
    private function emptyPendingWorkflowTasks(?string $buildId): array
    {
        return [
            'build_id' => $buildId,
            'total_count' => 0,
            'ready_count' => 0,
            'leased_count' => 0,
        ];
    }

    /**
     * @param  array{build_id: string|null, total_count: int, ready_count: int, leased_count: int}  $pending
     * @return array<string, mixed>
     */
    private function pendingWorkflowTaskDiagnostic(array $pending, int $activeWorkerCount): array
    {
        $total = max(0, (int) $pending['total_count']);
        $ready = max(0, (int) $pending['ready_count']);
        $leased = max(0, (int) $pending['leased_count']);
        $status = 'idle';
        $signal = null;
        $message = null;

        if ($total > 0) {
            $status = 'pending';

            if ($pending['build_id'] !== null && $activeWorkerCount === 0) {
                $status = 'no_compatible_worker';
                $signal = 'no_compatible_worker';
                $message = sprintf(
                    'This build id has %d pending workflow task%s but no active compatible worker.',
                    $total,
                    $total === 1 ? '' : 's',
                );
            }
        }

        return [
            'status' => $status,
            'operator_visible_signal' => $signal,
            'message' => $message,
            'total_count' => $total,
            'ready_count' => $ready,
            'leased_count' => $leased,
        ];
    }

    private function buildIdRolloutStatus(
        int $active,
        int $draining,
        int $stale,
        string $drainIntent,
    ): string {
        if ($active > 0) {
            return $drainIntent === WorkerBuildIdRollout::DRAIN_INTENT_DRAINING || $draining > 0
                ? 'active_with_draining'
                : 'active';
        }

        if ($draining > 0 || $drainIntent === WorkerBuildIdRollout::DRAIN_INTENT_DRAINING) {
            return 'draining';
        }

        return $stale > 0 ? 'stale_only' : 'no_workers';
    }

    /**
     * @param  array<string, true>  $map
     * @return array<int, string>
     */
    private function sortedKeys(array $map): array
    {
        $values = array_keys($map);
        sort($values);

        return $values;
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
