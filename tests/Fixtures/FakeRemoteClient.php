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

    public ?Throwable $failure = null;

    /** @var array<string, Throwable> */
    public array $failures = [];

    public function listWorkflows(
        ?string $workflowType = null,
        ?string $status = null,
        ?string $query = null,
        ?int $pageSize = null,
        ?string $nextPageToken = null,
    ): WorkflowPage {
        $this->called(__FUNCTION__, get_defined_vars());

        $raw = $this->workflowRaw($status ?? 'running');

        return new WorkflowPage([
            WorkflowExecution::fromArray($raw),
        ], null, 1, ['workflows' => [$raw]]);
    }

    public function describeWorkflow(string $workflowId, ?string $runId = null): WorkflowExecution
    {
        $this->called(__FUNCTION__, get_defined_vars());

        return WorkflowExecution::fromArray($this->workflowRaw('running'), $workflowId, $runId);
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
    public function workflowHistory(string $workflowId, string $runId): array
    {
        $this->called(__FUNCTION__, get_defined_vars());

        return ['events' => [[
            'id' => 'event-1',
            'sequence' => 1,
            'event_type' => 'WorkflowStarted',
            'payload' => [],
            'recorded_at' => '2026-07-22T12:00:00Z',
        ]]];
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

        return ['tasks' => [['id' => 'task-1', 'status' => 'ready']]];
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

        return ['operator_metrics' => ['runs' => ['total' => 1, 'running' => 1, 'failed' => 0]]];
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
            return ['workers' => [[
                'worker_id' => 'worker-stale',
                'namespace' => 'orders',
                'task_queue' => 'orders',
                'status' => 'stale',
                'last_heartbeat_at' => '2026-07-22T11:00:00Z',
            ]]];
        }

        return ['workers' => [[
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
        $raw = [
            'schedule_id' => 'nightly-orders',
            'status' => 'active',
            'spec' => ['cron' => '0 0 * * *'],
            'action' => ['workflow_type' => 'orders.process'],
            'next_fire_at' => '2026-07-23T00:00:00Z',
        ];

        return new SchedulePage([ScheduleDescription::fromArray($raw)], null, ['schedules' => [$raw]]);
    }

    public function describeSchedule(string $scheduleId): ScheduleDescription
    {
        $this->called(__FUNCTION__, get_defined_vars());

        return ScheduleDescription::fromArray([
            'schedule_id' => $scheduleId,
            'status' => 'active',
            'action' => ['workflow_type' => 'orders.process'],
        ]);
    }

    /** @return array<string, mixed> */
    public function scheduleHistory(string $scheduleId, ?int $limit = null, ?int $afterSequence = null): array
    {
        $this->called(__FUNCTION__, get_defined_vars());

        return ['schedule_id' => $scheduleId, 'events' => []];
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

        return ['schedule_id' => $scheduleId, 'triggered' => true];
    }

    /** @return array<string, mixed> */
    public function backfillSchedule(string $scheduleId, string $startTime, string $endTime, ?string $overlapPolicy = null): array
    {
        $this->called(__FUNCTION__, get_defined_vars());

        return ['schedule_id' => $scheduleId, 'results' => []];
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
