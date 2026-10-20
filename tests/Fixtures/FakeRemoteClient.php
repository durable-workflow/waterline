<?php

declare(strict_types=1);

namespace Waterline\Tests\Fixtures;

use DurableWorkflow\Model\ScheduleDescription;
use DurableWorkflow\Model\SchedulePage;
use DurableWorkflow\Model\WorkflowExecution;
use DurableWorkflow\Model\WorkflowPage;
use DurableWorkflow\Model\WorkflowRun;
use Throwable;

final class FakeRemoteClient
{
    /** @var list<array{method: string, arguments: array<int|string, mixed>}> */
    public array $calls = [];

    /** @var array<string, array<string, mixed>> */
    public array $catalogResponses = [];

    public ?Throwable $failure = null;

    /** @var array<string, Throwable> */
    public array $failures = [];

    /** @var list<array<string, mixed>>|null */
    public ?array $workflowRows = null;

    /** @var array<string, mixed>|null */
    public ?array $workflowDescription = null;

    /** @var array<string, mixed>|null */
    public ?array $diagnostics = null;

    /** @var array<string, mixed>|null */
    public ?array $observation = null;

    /** @var array<string, array<string, mixed>> */
    public array $observationPages = [];

    /** @var list<array<string, mixed>>|null */
    public ?array $workflowStreams = null;

    /** @var list<array<string, mixed>>|null */
    public ?array $activeWorkerRows = null;

    /** @var list<array<string, mixed>>|null */
    public ?array $staleWorkerRows = null;

    /** @var array<string, mixed>|null */
    public ?array $capacityEvidence = null;

    /** @var array<string, mixed>|null */
    public ?array $history = null;

    /** @var array<string, array<string, mixed>> */
    public array $historyPages = [];

    /** @var list<array<string, mixed>>|null */
    public ?array $activities = null;

    /** @var list<array<string, mixed>>|null Server list items; null uses the default fixture. */
    public ?array $scheduleListItems = null;

    /** @var array<string, array<string, mixed>> Server detail payloads keyed by schedule ID. */
    public array $scheduleDetails = [];

    /** @var array<string, Throwable> describeSchedule failures keyed by schedule ID. */
    public array $describeScheduleFailures = [];

    /** @var array<string, mixed>|null */
    public ?array $triggerScheduleResponse = null;

    /** @var array<string, mixed>|null */
    public ?array $backfillScheduleResponse = null;

    public function listWorkflows(
        ?string $workflowType = null,
        ?string $status = null,
        ?string $query = null,
        ?int $pageSize = null,
        ?string $nextPageToken = null,
    ): WorkflowPage {
        $this->called(__FUNCTION__, get_defined_vars());

        $rows = $this->workflowRows ?? [$this->workflowRaw($status ?? 'running')];
        $rows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($workflowType === null || ($row['workflow_type'] ?? null) === $workflowType)
                && ($status === null
                    || ($row['status'] ?? null) === $status
                    || ($row['status_bucket'] ?? null) === $status),
        ));

        return new WorkflowPage([
            ...array_map(static fn (array $row): WorkflowExecution => WorkflowExecution::fromArray($row), $rows),
        ], null, count($rows), ['workflows' => $rows]);
    }

    public function listServiceCatalogEndpoints(array $filters = []): array
    {
        return $this->catalogRead(__FUNCTION__, get_defined_vars());
    }

    public function describeServiceCatalogEndpoint(string $endpointId): array
    {
        return $this->catalogRead(__FUNCTION__, get_defined_vars());
    }

    public function listServiceCatalogServices(array $filters = []): array
    {
        return $this->catalogRead(__FUNCTION__, get_defined_vars());
    }

    public function describeServiceCatalogService(string $serviceId): array
    {
        return $this->catalogRead(__FUNCTION__, get_defined_vars());
    }

    public function listServiceCatalogOperations(array $filters = []): array
    {
        return $this->catalogRead(__FUNCTION__, get_defined_vars());
    }

    public function describeServiceCatalogOperation(string $operationId): array
    {
        return $this->catalogRead(__FUNCTION__, get_defined_vars());
    }

    public function listServiceCatalogCalls(array $filters = []): array
    {
        return $this->catalogRead(__FUNCTION__, get_defined_vars());
    }

    public function describeServiceCatalogCall(string $callId): array
    {
        return $this->catalogRead(__FUNCTION__, get_defined_vars());
    }

    private function catalogRead(string $method, array $arguments): array
    {
        $this->called($method, $arguments);

        return $this->catalogResponses[$method] ?? [
            'data' => [], 'current_page' => 1, 'per_page' => 50, 'total' => 0, 'last_page' => 1,
            'namespace' => 'orders',
        ];
    }

    public function describeWorkflow(string $workflowId, ?string $runId = null): WorkflowExecution
    {
        $this->called(__FUNCTION__, get_defined_vars());

        return WorkflowExecution::fromArray($this->workflowDescription ?? $this->workflowRaw('running'), $workflowId, $runId);
    }

    /** @return list<WorkflowRun> */
    public function listWorkflowRuns(string $workflowId): array
    {
        $this->called(__FUNCTION__, get_defined_vars());

        return [new WorkflowRun($workflowId, 'run-1', 'orders.process', 'running', true, [
            'run_id' => 'run-1',
            'run_number' => 1,
            'status' => 'running',
            'status_bucket' => 'running',
        ])];
    }

    /** @return array<string, mixed> */
    public function workflowHistory(string $workflowId, string $runId, ?int $pageSize = null, ?string $nextPageToken = null): array
    {
        $this->called(__FUNCTION__, get_defined_vars());

        return $this->historyPages[$nextPageToken ?? ''] ?? $this->history ?? ['events' => [[
            'sequence' => 1,
            'event_type' => 'WorkflowStarted',
            'payload' => [],
            'timestamp' => '2026-07-22T12:00:00Z',
            'principal' => null,
        ]]];
    }

    /** @return array<string, mixed> */
    public function workflowActivities(string $workflowId, string $runId): array
    {
        $this->called(__FUNCTION__, get_defined_vars());

        return ['workflow_id' => $workflowId, 'run_id' => $runId, 'activities' => $this->activities ?? []];
    }

    /** @return array<string, mixed> */
    public function exportWorkflowHistory(string $workflowId, string $runId): array
    {
        $this->called(__FUNCTION__, get_defined_vars());

        return ['schema' => 'durable-workflow.history', 'history_events' => []];
    }

    /** @return array<string, mixed> */
    public function workflowDiagnostics(string $workflowId, ?string $runId = null): array
    {
        $this->called(__FUNCTION__, get_defined_vars());

        return $this->diagnostics ?? ['tasks' => [['id' => 'task-1', 'status' => 'ready']]];
    }

    /** @return array<string, mixed> */
    public function workflowObservation(
        string $workflowId,
        ?string $runId = null,
        array $searchAttributeKeys = [],
        ?int $historyPageSize = null,
        ?string $historyPageToken = null,
    ): array {
        $this->called(__FUNCTION__, get_defined_vars());
        $response = $this->observationPages[$historyPageToken ?? ''] ?? $this->observation;
        if ($response === null) {
            throw new \LogicException('The bounded observation fixture is not configured.');
        }
        $response['search_attributes'] = array_intersect_key($response['search_attributes'] ?? [], array_flip($searchAttributeKeys));

        return $response;
    }

    /** @return list<array<string, mixed>> */
    public function listWorkflowStreams(string $workflowId, string $runId): array
    {
        $this->called(__FUNCTION__, get_defined_vars());

        return $this->workflowStreams ?? [[
            'stream_name' => 'receipts',
            'status' => 'errored',
            'last_offset' => 4,
            'total_items' => 5,
            'pending_items' => 2,
            'error_reason' => 'producer_failed',
        ]];
    }

    /** @return array<string, mixed> */
    public function systemHealth(): array
    {
        $this->called(__FUNCTION__, []);

        return ['health' => ['status' => 'healthy', 'checks' => []]];
    }

    /** @return array<string, mixed> */
    public function operatorMetrics(): array
    {
        $this->called(__FUNCTION__, []);

        return ['operator_metrics' => array_filter([
            'runs' => ['total' => 1, 'running' => 1, 'failed' => 0],
            'capacity_evidence' => $this->capacityEvidence,
        ], static fn (mixed $value): bool => $value !== null)];
    }

    /** @return array<string, mixed> */
    public function operatorDashboard(): array
    {
        $this->called(__FUNCTION__, []);

        return ['dashboard' => [
            'flows' => 1,
            'flows_past_hour' => 1,
            'operator_metrics' => ['runs' => ['total' => 1, 'running' => 1, 'failed' => 0]],
            'fleet_overview' => ['current' => ['running' => 1, 'failed' => 0]],
            'needs_attention' => ['total_alerts' => 0, 'has_critical' => false, 'alerts' => []],
            'workflow_type_health' => [],
        ]];
    }

    /** @return array<string, mixed> */
    public function listWorkers(?string $taskQueue = null, ?string $status = null): array
    {
        $this->called(__FUNCTION__, get_defined_vars());

        if ($status === 'stale') {
            return ['workers' => $this->staleWorkerRows ?? [[
                'worker_id' => 'worker-stale',
                'namespace' => 'orders',
                'task_queue' => 'orders',
                'status' => 'stale',
                'last_heartbeat_at' => '2026-07-22T11:00:00Z',
            ]]];
        }

        return ['workers' => $this->activeWorkerRows ?? [[
            'worker_id' => 'worker-1',
            'namespace' => 'orders',
            'task_queue' => 'orders',
            'status' => 'active',
            'last_heartbeat_at' => '2026-07-22T12:00:00Z',
        ]]];
    }

    /** @return array<string, mixed> */
    public function listTaskQueues(): array
    {
        $this->called(__FUNCTION__, []);

        return ['task_queues' => [[
            'name' => 'orders',
            'stats' => ['approximate_backlog_count' => 0],
        ]]];
    }

    public function listSchedules(
        ?string $status = null,
        ?string $workflowType = null,
        ?string $query = null,
        ?int $pageSize = null,
        ?string $nextPageToken = null,
    ): SchedulePage {
        $this->called(__FUNCTION__, get_defined_vars());

        $items = array_values(array_filter(
            $this->scheduleListItems ?? self::defaultScheduleListItems(),
            static fn (array $item): bool => ($status === null || ($item['status'] ?? null) === $status)
                && ($workflowType === null || ($item['workflow_type'] ?? null) === $workflowType),
        ));

        return new SchedulePage(
            array_map(static fn (array $item): ScheduleDescription => ScheduleDescription::fromArray($item), $items),
            null,
            ['schedules' => $items, 'next_page_token' => null],
        );
    }

    public function describeSchedule(string $scheduleId): ScheduleDescription
    {
        $this->called(__FUNCTION__, get_defined_vars());

        if (isset($this->describeScheduleFailures[$scheduleId])) {
            throw $this->describeScheduleFailures[$scheduleId];
        }

        return ScheduleDescription::fromArray(
            $this->scheduleDetails[$scheduleId] ?? self::defaultScheduleDetail($scheduleId),
            $scheduleId,
        );
    }

    /** @return array<string, mixed> */
    public function scheduleHistory(string $scheduleId, ?int $limit = null, ?int $afterSequence = null): array
    {
        $this->called(__FUNCTION__, get_defined_vars());

        return [
            'schedule_id' => $scheduleId,
            'namespace' => 'orders',
            'events' => [[
                'id' => '01JAHX3Q8V5T6W7Y8Z9A0B1C2D',
                'sequence' => 1,
                'event_type' => 'ScheduleCreated',
                'payload' => ['overlap_policy' => 'skip'],
                'workflow_instance_id' => null,
                'workflow_run_id' => null,
                'recorded_at' => '2026-07-01T09:00:00+00:00',
            ]],
            'next_cursor' => null,
            'has_more' => false,
        ];
    }

    /**
     * Server 2.9.8 ScheduleController::formatListItem output. List items carry
     * no recent actions, failure counts or skip facts.
     *
     * @return list<array<string, mixed>>
     */
    public static function defaultScheduleListItems(): array
    {
        return [
            [
                'schedule_id' => 'nightly-orders',
                'workflow_type' => 'orders.process',
                'status' => 'active',
                'paused' => false,
                'next_fire' => '2026-08-13T00:00:00+00:00',
                'last_fire' => '2026-08-12T00:00:00+00:00',
                'overlap_policy' => 'skip',
                'note' => null,
                'namespace' => 'orders',
                'spec' => ['cron_expressions' => ['0 0 * * *'], 'timezone' => 'UTC'],
                'action' => ['workflow_type' => 'orders.process', 'task_queue' => 'orders'],
                'next_fire_at' => '2026-08-13T00:00:00+00:00',
                'last_fired_at' => '2026-08-12T00:00:00+00:00',
                'search_attributes' => ['region' => 'eu'],
                'created_at' => '2026-07-01T09:00:00+00:00',
                'updated_at' => '2026-08-12T00:00:01+00:00',
                'fires_count' => 42,
            ],
            [
                'schedule_id' => 'hourly-reconcile',
                'workflow_type' => 'orders.reconcile',
                'status' => 'paused',
                'paused' => true,
                'next_fire' => '2026-08-12T12:05:00+00:00',
                'last_fire' => '2026-08-12T11:05:00+00:00',
                'overlap_policy' => 'buffer_one',
                'note' => 'Paused during ledger migration.',
                'namespace' => 'orders',
                'spec' => ['intervals' => [['every' => 'PT1H', 'offset' => 'PT5M']], 'timezone' => 'UTC'],
                'action' => ['workflow_type' => 'orders.reconcile', 'task_queue' => 'orders'],
                'next_fire_at' => '2026-08-12T12:05:00+00:00',
                'last_fired_at' => '2026-08-12T11:05:00+00:00',
                'search_attributes' => null,
                'created_at' => '2026-07-15T09:00:00+00:00',
                'updated_at' => '2026-08-12T11:30:00+00:00',
                'fires_count' => 7,
                'max_runs' => 10,
                'remaining_actions' => 3,
            ],
        ];
    }

    /**
     * Server 2.9.8 ScheduleController::formatDetail output: Workflow's
     * toDetail() with `state`/`info` groups plus the server's flat additions.
     *
     * @return array<string, mixed>
     */
    public static function defaultScheduleDetail(string $scheduleId): array
    {
        $item = [];
        foreach (self::defaultScheduleListItems() as $candidate) {
            if ($candidate['schedule_id'] === $scheduleId) {
                $item = $candidate;
            }
        }

        $status = $item['status'] ?? 'active';
        $recentActions = match ($scheduleId) {
            'nightly-orders' => [
                ['outcome' => 'failed', 'reason' => 'Task queue [orders] has no workers.', 'fired_at' => '2026-08-11T00:00:00+00:00'],
                [
                    'workflow_id' => 'schedule:nightly-orders:1786492800000',
                    'run_id' => '01JAHX5M2N3P4Q5R6S7T8V9W0X',
                    'outcome' => 'scheduled',
                    'fired_at' => '2026-08-12T00:00:00+00:00',
                    'occurrence_time' => '2026-08-12T00:00:00.000000+00:00',
                    'fire_lag_ms' => 412,
                ],
            ],
            'hourly-reconcile' => [[
                'workflow_id' => 'schedule:hourly-reconcile:1786532700000',
                'run_id' => '01JAHX6A1B2C3D4E5F6G7H8J9K',
                'outcome' => 'scheduled',
                'fired_at' => '2026-08-12T11:05:00+00:00',
            ]],
            default => [],
        };
        $skip = $scheduleId === 'hourly-reconcile'
            ? ['count' => 2, 'reason' => 'overlap_policy_buffer_one', 'at' => '2026-08-12T11:30:00+00:00']
            : ['count' => 0, 'reason' => null, 'at' => null];
        $latest = $recentActions === [] ? null : $recentActions[array_key_last($recentActions)];

        return [
            'schedule_id' => $scheduleId,
            'spec' => $item['spec'] ?? ['cron_expressions' => ['0 * * * *'], 'timezone' => 'UTC'],
            'action' => $item['action'] ?? ['workflow_type' => 'orders.process', 'task_queue' => 'orders'],
            'overlap_policy' => $item['overlap_policy'] ?? 'skip',
            'state' => ['status' => $status, 'paused' => $status === 'paused', 'note' => $item['note'] ?? null],
            'info' => [
                'next_fire' => $item['next_fire_at'] ?? null,
                'last_fire' => $item['last_fired_at'] ?? null,
                'fires_count' => $item['fires_count'] ?? 0,
                'failures_count' => $scheduleId === 'nightly-orders' ? 1 : 0,
                'recent_actions' => $recentActions,
                'buffered_actions' => [],
                'skipped_trigger_count' => $skip['count'],
                'last_skip_reason' => $skip['reason'],
                'last_skipped_at' => $skip['at'],
            ],
            'memo' => null,
            'search_attributes' => $item['search_attributes'] ?? null,
            'created_at' => $item['created_at'] ?? null,
            'updated_at' => $item['updated_at'] ?? null,
            'namespace' => 'orders',
            'status' => $status,
            'paused' => $status === 'paused',
            'note' => $item['note'] ?? null,
            'fires_count' => $item['fires_count'] ?? 0,
            'failures_count' => $scheduleId === 'nightly-orders' ? 1 : 0,
            'next_fire_at' => $item['next_fire_at'] ?? null,
            'last_fired_at' => $item['last_fired_at'] ?? null,
            'paused_at' => $status === 'paused' ? '2026-08-12T11:20:00+00:00' : null,
            'jitter_seconds' => 0,
            'max_runs' => $item['max_runs'] ?? null,
            'remaining_actions' => $item['remaining_actions'] ?? null,
            'latest_workflow_instance_id' => $latest['workflow_id'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    public function signalWorkflow(string $workflowId, string $signalName, array $arguments = [], ?string $runId = null): array
    {
        $this->called(__FUNCTION__, get_defined_vars());

        return ['command_status' => 'accepted', 'signal_name' => $signalName];
    }

    public function queryWorkflow(string $workflowId, string $queryName, array $arguments = [], ?string $runId = null): mixed
    {
        $this->called(__FUNCTION__, get_defined_vars());

        return ['state' => 'running'];
    }

    public function updateWorkflow(
        string $workflowId,
        string $updateName,
        array $arguments = [],
        string $waitFor = 'completed',
        ?int $waitTimeoutSeconds = null,
        ?string $requestId = null,
        ?string $runId = null,
    ): mixed {
        $this->called(__FUNCTION__, get_defined_vars());

        return ['status' => 'completed'];
    }

    /** @return array<string, mixed> */
    public function cancelWorkflow(string $workflowId, ?string $reason = null, ?string $runId = null): array
    {
        $this->called(__FUNCTION__, get_defined_vars());

        return ['command_status' => 'accepted'];
    }

    /** @return array<string, mixed> */
    public function terminateWorkflow(string $workflowId, ?string $reason = null, ?string $runId = null): array
    {
        $this->called(__FUNCTION__, get_defined_vars());

        return ['command_status' => 'accepted'];
    }

    /** @return array<string, mixed> */
    public function repairWorkflow(string $workflowId, ?string $runId = null): array
    {
        $this->called(__FUNCTION__, get_defined_vars());

        return ['command_status' => 'accepted'];
    }

    /** @return array<string, mixed> */
    public function archiveWorkflow(string $workflowId, ?string $reason = null, ?string $runId = null): array
    {
        $this->called(__FUNCTION__, get_defined_vars());

        return ['command_status' => 'accepted'];
    }

    public function pauseSchedule(string $scheduleId, ?string $note = null): void
    {
        $this->called(__FUNCTION__, get_defined_vars());
    }

    public function resumeSchedule(string $scheduleId, ?string $note = null): void
    {
        $this->called(__FUNCTION__, get_defined_vars());
    }

    /** @return array<string, mixed> */
    public function triggerSchedule(string $scheduleId, ?string $overlapPolicy = null): array
    {
        $this->called(__FUNCTION__, get_defined_vars());

        return $this->triggerScheduleResponse ?? [
            'schedule_id' => $scheduleId,
            'outcome' => 'triggered',
            'workflow_id' => 'schedule:'.$scheduleId.':1786536015000',
            'run_id' => '01JAHX7B2C3D4E5F6G7H8J9K0M',
        ];
    }

    /** @return array<string, mixed> */
    public function backfillSchedule(string $scheduleId, string $startTime, string $endTime, ?string $overlapPolicy = null): array
    {
        $this->called(__FUNCTION__, get_defined_vars());

        return $this->backfillScheduleResponse ?? [
            'schedule_id' => $scheduleId,
            'outcome' => 'backfill_started',
            'fires_attempted' => 2,
            'results' => [
                [
                    'fire_time' => '2026-08-10T00:00:00+00:00',
                    'workflow_id' => 'schedule:'.$scheduleId.':backfill:1786320000',
                    'outcome' => 'started',
                ],
                [
                    'fire_time' => '2026-08-11T00:00:00+00:00',
                    'workflow_id' => 'schedule:'.$scheduleId.':backfill:1786406400',
                    'outcome' => 'started',
                ],
            ],
        ];
    }

    public function deleteSchedule(string $scheduleId): void
    {
        $this->called(__FUNCTION__, get_defined_vars());
    }

    /** @param array<int|string, mixed> $arguments */
    private function called(string $method, array $arguments): void
    {
        $this->calls[] = compact('method', 'arguments');

        if (isset($this->failures[$method])) {
            throw $this->failures[$method];
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }
    }

    /** @return array<string, mixed> */
    private function workflowRaw(string $status): array
    {
        return [
            'workflow_id' => 'order-1',
            'run_id' => 'run-1',
            'workflow_type' => 'orders.process',
            'namespace' => 'orders',
            'task_queue' => 'orders',
            'status' => $status,
            'status_bucket' => in_array($status, ['completed'], true) ? 'completed' : ($status === 'running' ? 'running' : 'failed'),
            'is_terminal' => $status !== 'running',
            'started_at' => '2026-07-22T12:00:00Z',
            'actions' => [
                'can_query' => true,
                'can_signal' => true,
                'can_update' => true,
                'can_cancel' => true,
                'can_terminate' => true,
                'can_repair' => true,
                'can_archive' => false,
            ],
            'updates' => [],
            'commands' => [],
        ];
    }
}
