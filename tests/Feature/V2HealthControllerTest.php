<?php

namespace Waterline\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Waterline\Models\WorkerRegistration;
use Waterline\Tests\TestCase;
use Waterline\Tests\Fixtures\V2\TestCommandContractWorkflow;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Support\WorkerCompatibilityFleet;

class V2HealthControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('waterline.engine_source', 'v2');
    }

    public function testHealthEndpointReturnsV2HealthSnapshot(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        config()->set('cache.default', 'file');

        $this->get('/waterline/api/v2/health')
            ->assertStatus(200)
            ->assertJsonPath('namespace', null)
            ->assertJsonPath('queue_visibility.available', false)
            ->assertJsonPath(
                'queue_visibility.reason',
                'Configure waterline.namespace to scope queue visibility to one task-queue fleet.',
            )
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('healthy', true)
            ->assertJsonPath('checks.0.name', 'engine_source')
            ->assertJsonPath('checks.0.status', 'ok')
            ->assertJsonPath('checks.1.name', 'backend_capabilities')
            ->assertJsonPath('checks.1.status', 'ok')
            ->assertJsonPath('engine_source.resolved', 'v2')
            ->assertJsonPath('readiness_contract.version', 1)
            ->assertJsonPath('readiness_contract.effective_states.health.state', 'delegates_to_v2_health_check')
            ->assertJsonCount(0, 'coordination_alerts')
            ->assertJsonPath('operator_metrics.backend.supported', true);
    }

    public function testHealthEndpointScopesSnapshotToConfiguredNamespace(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        config()->set('cache.default', 'file');
        config()->set('waterline.namespace', 'billing');

        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        $this->createRunSummaryWithReadyTask(namespace: 'billing', availableSecondsAgo: 1);
        $this->createRunSummaryWithReadyTask(namespace: 'shipping', availableSecondsAgo: 10);

        $this->get('/waterline/api/v2/health')
            ->assertStatus(200)
            ->assertJsonPath('namespace', 'billing')
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('operator_metrics.runs.total', 1)
            ->assertJsonPath('operator_metrics.tasks.ready_due', 1)
            ->assertJsonPath('operator_metrics.tasks.oldest_ready_due_at', now()->subSecond()->toJSON());
    }

    public function testHealthEndpointExposesNamespaceScopedQueueVisibility(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        config()->set('cache.default', 'file');
        config()->set('waterline.namespace', 'billing');

        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        $this->createWorkerRegistrationsTable();
        $run = $this->createRunSummaryWithReadyTask(
            namespace: 'billing',
            availableSecondsAgo: 30,
        );
        $this->createRunSummaryWithReadyTask(namespace: 'shipping', availableSecondsAgo: 45);

        WorkflowTask::create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'namespace' => 'billing',
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Leased->value,
            'queue' => 'default',
            'created_at' => now()->subMinutes(2),
            'lease_expires_at' => now()->addMinute(),
            'last_dispatched_at' => now()->subSeconds(15),
        ]);

        WorkerRegistration::create([
            'worker_id' => 'billing-worker-1',
            'namespace' => 'billing',
            'task_queue' => 'default',
            'runtime' => 'php',
            'sdk_version' => '1.0.0',
            'build_id' => 'build-billing',
            'supported_workflow_types' => ['workflow.billing'],
            'supported_activity_types' => ['activity.charge'],
            'max_concurrent_workflow_tasks' => 8,
            'max_concurrent_activity_tasks' => 4,
            'last_heartbeat_at' => now()->subSeconds(15),
            'status' => 'active',
        ]);

        $this->get('/waterline/api/v2/health')
            ->assertStatus(200)
            ->assertJsonPath('namespace', 'billing')
            ->assertJsonPath('queue_visibility.available', true)
            ->assertJsonPath('queue_visibility.namespace', 'billing')
            ->assertJsonCount(1, 'queue_visibility.task_queues')
            ->assertJsonPath('queue_visibility.task_queues.0.name', 'default')
            ->assertJsonPath('queue_visibility.task_queues.0.stats.approximate_backlog_count', 1)
            ->assertJsonPath('queue_visibility.task_queues.0.stats.workflow_tasks.ready_count', 1)
            ->assertJsonPath('queue_visibility.task_queues.0.stats.pollers.active_count', 1)
            ->assertJsonPath('queue_visibility.task_queues.0.stats.pollers.stale_count', 0)
            ->assertJsonPath('queue_visibility.task_queues.0.stats.tasks_added_last_minute', 1)
            ->assertJsonPath('queue_visibility.task_queues.0.stats.tasks_dispatched_last_minute', 1)
            ->assertJsonPath('queue_visibility.task_queues.0.repair.candidates', 0)
            ->assertJsonPath('queue_visibility.task_queues.0.repair.dispatch_failed', 0)
            ->assertJsonPath('queue_visibility.task_queues.0.repair.expired_leases', 0)
            ->assertJsonPath('queue_visibility.task_queues.0.repair.dispatch_overdue', 0)
            ->assertJsonPath('queue_visibility.task_queues.0.repair.oldest_dispatch_failed_at', null)
            ->assertJsonPath('queue_visibility.task_queues.0.repair.max_dispatch_failed_age_ms', 0)
            ->assertJsonPath('queue_visibility.task_queues.0.repair.oldest_lease_expired_at', null)
            ->assertJsonPath('queue_visibility.task_queues.0.repair.max_lease_expired_age_ms', 0)
            ->assertJsonPath('queue_visibility.task_queues.0.repair.oldest_dispatch_overdue_since', null)
            ->assertJsonPath('queue_visibility.task_queues.0.repair.max_dispatch_overdue_age_ms', 0);
    }

    public function testHealthEndpointBackfillsQueueRepairAges(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        config()->set('cache.default', 'file');
        config()->set('waterline.namespace', 'billing');

        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        $this->createWorkerRegistrationsTable();
        $run = $this->createRunSummaryWithReadyTask(
            namespace: 'billing',
            availableSecondsAgo: 15,
        );

        WorkflowTask::create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'namespace' => 'billing',
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Ready->value,
            'queue' => 'default',
            'created_at' => now()->subMinutes(4),
            'last_dispatch_attempt_at' => now()->subSeconds(45),
            'last_dispatch_error' => 'transport timeout',
        ]);

        WorkflowTask::create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'namespace' => 'billing',
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Leased->value,
            'queue' => 'default',
            'created_at' => now()->subMinutes(3),
            'lease_expires_at' => now()->subSeconds(90),
        ]);

        WorkflowTask::create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'namespace' => 'billing',
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Ready->value,
            'queue' => 'default',
            'created_at' => now()->subMinutes(2),
        ]);

        $this->get('/waterline/api/v2/health')
            ->assertStatus(200)
            ->assertJsonPath('queue_visibility.available', true)
            ->assertJsonPath('queue_visibility.task_queues.0.repair.dispatch_failed', 1)
            ->assertJsonPath('queue_visibility.task_queues.0.repair.expired_leases', 1)
            ->assertJsonPath('queue_visibility.task_queues.0.repair.dispatch_overdue', 1)
            ->assertJsonPath('queue_visibility.task_queues.0.repair.oldest_dispatch_failed_at', now()->subSeconds(45)->toJSON())
            ->assertJsonPath('queue_visibility.task_queues.0.repair.max_dispatch_failed_age_ms', 45 * 1000)
            ->assertJsonPath('queue_visibility.task_queues.0.repair.oldest_lease_expired_at', now()->subSeconds(90)->toJSON())
            ->assertJsonPath('queue_visibility.task_queues.0.repair.max_lease_expired_age_ms', 90 * 1000)
            ->assertJsonPath('queue_visibility.task_queues.0.repair.oldest_dispatch_overdue_since', now()->subMinutes(2)->toJSON())
            ->assertJsonPath('queue_visibility.task_queues.0.repair.max_dispatch_overdue_age_ms', 2 * 60 * 1000);
    }

    public function testHealthEndpointCategorizesEveryCheckAndExposesWakeAcceleration(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        config()->set('cache.default', 'file');

        $response = $this->get('/waterline/api/v2/health')
            ->assertStatus(200);

        $payload = $response->json();
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('checks', $payload);

        $workflowChecks = array_values(array_filter(
            $payload['checks'],
            static fn (array $check): bool => ($check['name'] ?? null) !== 'engine_source',
        ));

        $this->assertArrayHasKey('categories', $payload);
        $this->assertArrayHasKey('correctness', $payload['categories']);
        $this->assertArrayHasKey('acceleration', $payload['categories']);

        $wakeChecks = array_values(array_filter(
            $workflowChecks,
            static fn (array $check): bool => ($check['name'] ?? null) === 'long_poll_wake_acceleration',
        ));
        $routingChecks = array_values(array_filter(
            $workflowChecks,
            static fn (array $check): bool => ($check['name'] ?? null) === 'routing_health',
        ));

        $this->assertCount(1, $wakeChecks, 'Waterline health must surface the long_poll_wake_acceleration check so operators can read acceleration-layer health.');
        $this->assertSame('acceleration', $wakeChecks[0]['category']);
        $this->assertCount(1, $routingChecks, 'Waterline health must surface the routing_health check so operators can read routing drains without log archaeology.');
        $this->assertSame('correctness', $routingChecks[0]['category']);

        foreach ($workflowChecks as $check) {
            $this->assertArrayHasKey('category', $check, sprintf(
                'Waterline health check %s must carry a category field.',
                $check['name'] ?? 'unknown',
            ));
            $this->assertContains(
                $check['category'],
                ['correctness', 'acceleration'],
                sprintf(
                    'Waterline health check %s has invalid category %s.',
                    $check['name'] ?? 'unknown',
                    (string) ($check['category'] ?? ''),
                ),
            );
        }
    }

    public function testHealthEndpointExposesWorkerCompatibilityDataForWorkersPanel(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        config()->set('cache.default', 'file');
        config()->set('workflows.v2.compatibility.namespace', 'waterline-workers-panel');
        config()->set('workflows.v2.compatibility.current', 'build-alpha');

        WorkerCompatibilityFleet::clear();
        WorkerCompatibilityFleet::recordForNamespace(
            namespace: 'waterline-workers-panel',
            supported: ['build-alpha'],
            connection: 'redis',
            queue: 'default',
            workerId: 'waterline-worker-alpha',
        );

        $response = $this->get('/waterline/api/v2/health')->assertStatus(200);

        $payload = $response->json();
        $this->assertIsArray($payload);

        $workers = $payload['operator_metrics']['workers'] ?? null;
        $this->assertIsArray($workers, 'Waterline health endpoint must expose operator_metrics.workers for the workers panel.');
        $this->assertArrayHasKey('active_workers', $workers);
        $this->assertArrayHasKey('active_workers_supporting_required', $workers);

        $this->assertGreaterThanOrEqual(
            1,
            (int) ($workers['active_workers'] ?? 0),
            'Seeded compatibility heartbeat must surface as at least one active worker so the workers panel has something to render.'
        );
    }

    public function testHealthEndpointPublishesCompatibilityAlertFactsWhenFailClosedWorkersAreMissing(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        config()->set('cache.default', 'file');
        config()->set('waterline.namespace', 'waterline-workers-alerts');
        config()->set('workflows.v2.compatibility.namespace', 'waterline-workers-alerts');
        config()->set('workflows.v2.compatibility.current', 'build-beta');
        config()->set('workflows.v2.fleet.validation_mode', 'fail');

        WorkerCompatibilityFleet::clear();
        WorkerCompatibilityFleet::recordForNamespace(
            namespace: 'waterline-workers-alerts',
            supported: ['build-alpha'],
            connection: 'redis',
            queue: 'default',
            workerId: 'waterline-worker-alpha',
        );

        $payload = $this->get('/waterline/api/v2/health')
            ->assertStatus(503)
            ->json();

        $alert = $this->coordinationAlertByKey($payload, 'worker_compatibility');
        $this->assertNotNull($alert);
        $this->assertSame('health_check', $alert['source']);
        $this->assertSame('error', $alert['status']);
        $this->assertSame('build-beta', $alert['facts']['required_compatibility'] ?? null);
        $this->assertSame(1, $alert['facts']['active_workers'] ?? null);
        $this->assertSame(1, $alert['facts']['active_worker_scopes'] ?? null);
        $this->assertSame(0, $alert['facts']['active_workers_supporting_required'] ?? null);
        $this->assertSame('fail', $alert['facts']['validation_mode'] ?? null);
        $this->assertStringContainsString('build-beta', (string) ($alert['details'] ?? ''));
        $this->assertStringContainsString('0 supporting workers', (string) ($alert['details'] ?? ''));
    }

    public function testHealthEndpointReturnsUnavailableForBlockingBackendIssues(): void
    {
        config()->set('queue.default', 'sync');
        config()->set('queue.connections.sync.driver', 'sync');
        config()->set('workflows.v2.task_dispatch_mode', 'queue');

        $this->get('/waterline/api/v2/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('healthy', false)
            ->assertJsonPath('checks.0.name', 'engine_source')
            ->assertJsonPath('checks.1.name', 'backend_capabilities')
            ->assertJsonPath('checks.1.status', 'error')
            ->assertJsonFragment(['code' => 'queue_sync_unsupported']);
    }

    public function testHealthEndpointWarnsForRepairNeededResumePaths(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        config()->set('cache.default', 'file');

        $instance = WorkflowInstance::create([
            'id' => 'waterline-health-repair',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JWATERLINEHEALTHREPAIR1',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'started_at' => now()->subMinutes(10),
            'last_progress_at' => now()->subMinute(),
        ]);

        $instance->update(['current_run_id' => $run->id]);

        WorkflowRunSummary::create([
            'id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'status_bucket' => 'running',
            'started_at' => now()->subMinutes(10),
            'wait_started_at' => now()->subMinutes(5),
            'liveness_state' => 'repair_needed',
            'liveness_reason' => 'Run is non-terminal but has no durable next-resume source.',
            'created_at' => now()->subMinutes(10),
            'updated_at' => now(),
        ]);

        $this->get('/waterline/api/v2/health')
            ->assertStatus(200)
            ->assertJsonPath('status', 'warning')
            ->assertJsonPath('healthy', true)
            ->assertJsonFragment([
                'name' => 'durable_resume_paths',
                'status' => 'warning',
            ])
            ->assertJsonPath('operator_metrics.backlog.repair_needed_runs', 1)
            ->assertJsonPath('operator_metrics.repair.missing_task_candidates', 1);

        $payload = $this->get('/waterline/api/v2/health')->json();
        $alert = $this->coordinationAlertByKey($payload, 'durable_resume_paths');
        $this->assertNotNull($alert);
        $this->assertSame('health_check', $alert['source']);
        $this->assertSame('warning', $alert['status']);
        $this->assertSame('Durable Resume Paths', $alert['title']);
        $this->assertSame(1, $alert['facts']['repair_needed_runs'] ?? null);
        $this->assertSame(1, $alert['facts']['missing_task_candidates'] ?? null);
        $this->assertSame(1, $alert['facts']['waiting_runs'] ?? null);
        $this->assertStringContainsString('1 repair-needed run', (string) ($alert['details'] ?? ''));
        $this->assertStringContainsString('1 missing-task candidate', (string) ($alert['details'] ?? ''));
    }

    public function testHealthEndpointWarnsForCommandContractSnapshotsNeedingBackfill(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        config()->set('cache.default', 'file');

        $availableInstance = WorkflowInstance::create([
            'id' => 'waterline-health-contract-available',
            'workflow_class' => TestCommandContractWorkflow::class,
            'workflow_type' => 'workflow.command-contract',
            'run_count' => 1,
        ]);

        $availableRun = WorkflowRun::create([
            'id' => '01JWATERLINEHEALTHCONTRAV1',
            'workflow_instance_id' => $availableInstance->id,
            'run_number' => 1,
            'workflow_class' => TestCommandContractWorkflow::class,
            'workflow_type' => 'workflow.command-contract',
            'status' => 'waiting',
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subSecond(),
        ]);

        $availableInstance->update(['current_run_id' => $availableRun->id]);

        WorkflowHistoryEvent::record($availableRun, HistoryEventType::WorkflowStarted, [
            'workflow_class' => TestCommandContractWorkflow::class,
            'workflow_type' => 'workflow.command-contract',
            'declared_queries' => ['current-stage', 'stageMatches'],
            'declared_query_contracts' => [
                [
                    'name' => 'current-stage',
                    'parameters' => [],
                ],
            ],
            'declared_signals' => ['approved-by', 'rejected-by'],
            'declared_signal_contracts' => [
                [
                    'name' => 'approved-by',
                    'parameters' => [
                        [
                            'name' => 'actor',
                            'position' => 0,
                            'required' => true,
                            'variadic' => false,
                            'default_available' => false,
                            'default' => null,
                            'type' => 'string',
                            'allows_null' => false,
                        ],
                    ],
                ],
            ],
            'declared_updates' => ['mark-approved'],
            'declared_update_contracts' => [
                [
                    'name' => 'mark-approved',
                    'parameters' => [
                        [
                            'name' => 'approved',
                            'position' => 0,
                            'required' => true,
                            'variadic' => false,
                            'default_available' => false,
                            'default' => null,
                            'type' => 'bool',
                            'allows_null' => false,
                        ],
                    ],
                ],
            ],
        ]);

        $unavailableInstance = WorkflowInstance::create([
            'id' => 'waterline-health-contract-unavailable',
            'workflow_class' => 'Missing\\Workflow\\CommandContractWorkflow',
            'workflow_type' => 'missing-command-contract-workflow',
            'run_count' => 1,
        ]);

        $unavailableRun = WorkflowRun::create([
            'id' => '01JWATERLINEHEALTHCONTUNV1',
            'workflow_instance_id' => $unavailableInstance->id,
            'run_number' => 1,
            'workflow_class' => 'Missing\\Workflow\\CommandContractWorkflow',
            'workflow_type' => 'missing-command-contract-workflow',
            'status' => 'waiting',
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subSecond(),
        ]);

        $unavailableInstance->update(['current_run_id' => $unavailableRun->id]);

        WorkflowHistoryEvent::record($unavailableRun, HistoryEventType::WorkflowStarted, [
            'workflow_class' => 'Missing\\Workflow\\CommandContractWorkflow',
            'workflow_type' => 'missing-command-contract-workflow',
            'declared_signals' => ['approved-by', 'rejected-by'],
            'declared_updates' => ['mark-approved'],
        ]);

        $this->get('/waterline/api/v2/health')
            ->assertStatus(200)
            ->assertJsonPath('status', 'warning')
            ->assertJsonPath('healthy', true)
            ->assertJsonFragment([
                'name' => 'command_contract_snapshots',
                'status' => 'warning',
            ])
            ->assertJsonPath('operator_metrics.command_contracts.backfill_needed_runs', 2)
            ->assertJsonPath('operator_metrics.command_contracts.backfill_available_runs', 1)
            ->assertJsonPath('operator_metrics.command_contracts.backfill_unavailable_runs', 1);

        $payload = $this->get('/waterline/api/v2/health')->json();
        $alert = $this->coordinationAlertByKey($payload, 'command_contract_snapshots');
        $this->assertNotNull($alert);
        $this->assertSame('health_check', $alert['source']);
        $this->assertSame(2, $alert['facts']['backfill_needed_runs'] ?? null);
        $this->assertSame(1, $alert['facts']['backfill_available_runs'] ?? null);
        $this->assertSame(1, $alert['facts']['backfill_unavailable_runs'] ?? null);
        $this->assertStringContainsString('2 runs need command-contract backfill', (string) ($alert['details'] ?? ''));
    }

    public function testHealthEndpointPublishesQueueCoordinationAlerts(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        config()->set('cache.default', 'file');
        config()->set('waterline.namespace', 'billing');

        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        $this->createWorkerRegistrationsTable();
        $run = $this->createRunSummaryWithReadyTask(
            namespace: 'billing',
            availableSecondsAgo: 15,
        );

        WorkflowTask::create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'namespace' => 'billing',
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Ready->value,
            'queue' => 'default',
            'created_at' => now()->subMinutes(4),
            'last_dispatch_attempt_at' => now()->subSeconds(45),
            'last_dispatch_error' => 'transport timeout',
        ]);

        WorkflowTask::create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'namespace' => 'billing',
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Leased->value,
            'queue' => 'default',
            'created_at' => now()->subMinutes(3),
            'lease_expires_at' => now()->subSeconds(90),
        ]);

        WorkflowTask::create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'namespace' => 'billing',
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Ready->value,
            'queue' => 'default',
            'created_at' => now()->subMinutes(2),
        ]);

        WorkerRegistration::create([
            'worker_id' => 'billing-stale-worker',
            'namespace' => 'billing',
            'task_queue' => 'default',
            'runtime' => 'php',
            'sdk_version' => '1.0.0',
            'build_id' => 'build-billing',
            'supported_workflow_types' => ['workflow.billing'],
            'supported_activity_types' => ['activity.charge'],
            'max_concurrent_workflow_tasks' => 8,
            'max_concurrent_activity_tasks' => 4,
            'last_heartbeat_at' => now()->subMinutes(10),
            'status' => 'active',
        ]);

        $payload = $this->get('/waterline/api/v2/health')
            ->assertStatus(200)
            ->json();

        $repairAlert = $this->coordinationAlertByKey($payload, 'queue_repair_candidates');
        $this->assertNotNull($repairAlert);
        $this->assertSame('queue_visibility', $repairAlert['source']);
        $this->assertSame('warning', $repairAlert['status']);
        $this->assertSame(1, $repairAlert['queue_count']);
        $this->assertSame(['default'], $repairAlert['queues']);
        $this->assertGreaterThanOrEqual(3, (int) ($repairAlert['candidate_count'] ?? 0));
        $this->assertSame(2 * 60 * 1000, $repairAlert['max_age_ms']);

        $backlogAlert = $this->coordinationAlertByKey($payload, 'queue_backlog_without_pollers');
        $this->assertNotNull($backlogAlert);
        $this->assertSame('error', $backlogAlert['status']);
        $this->assertSame(1, $backlogAlert['queue_count']);
        $this->assertSame(['default'], $backlogAlert['queues']);
        $this->assertGreaterThanOrEqual(1, (int) ($backlogAlert['backlog_count'] ?? 0));

        $staleAlert = $this->coordinationAlertByKey($payload, 'queue_stale_pollers');
        $this->assertNotNull($staleAlert);
        $this->assertSame('warning', $staleAlert['status']);
        $this->assertSame(1, $staleAlert['queue_count']);
        $this->assertSame(['default'], $staleAlert['queues']);
        $this->assertSame(1, $staleAlert['stale_poller_count']);

        $taskTransportAlert = $this->coordinationAlertByKey($payload, 'task_transport');
        $this->assertNotNull($taskTransportAlert);
        $this->assertSame('health_check', $taskTransportAlert['source']);
        $this->assertSame('warning', $taskTransportAlert['status']);
        $this->assertSame(3, $taskTransportAlert['facts']['unhealthy_tasks'] ?? null);
        $this->assertSame(1, $taskTransportAlert['facts']['dispatch_failed_tasks'] ?? null);
        $this->assertSame(1, $taskTransportAlert['facts']['dispatch_overdue_tasks'] ?? null);
        $this->assertSame(1, $taskTransportAlert['facts']['lease_expired_tasks'] ?? null);
        $this->assertSame(2 * 60 * 1000, $taskTransportAlert['facts']['max_dispatch_overdue_age_ms'] ?? null);
        $this->assertStringContainsString('3 unhealthy tasks', (string) ($taskTransportAlert['details'] ?? ''));
        $this->assertStringContainsString('worst-case age 2m00s', (string) ($taskTransportAlert['details'] ?? ''));
    }

    private function createRunSummaryWithReadyTask(
        string $namespace,
        int $availableSecondsAgo,
    ): WorkflowRun
    {
        $instanceId = 'waterline-health-'.Str::lower(Str::random(12));
        $runId = (string) Str::ulid();
        $workflowType = sprintf('workflow.health.%s', $namespace);

        $instance = WorkflowInstance::create([
            'id' => $instanceId,
            'namespace' => $namespace,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => $workflowType,
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => $runId,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => $workflowType,
            'status' => 'running',
            'namespace' => $namespace,
            'started_at' => now()->subMinutes(10),
            'last_progress_at' => now()->subMinute(),
        ]);

        $instance->update(['current_run_id' => $run->id]);

        WorkflowRunSummary::create([
            'id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'WorkflowClass',
            'workflow_type' => $workflowType,
            'status' => 'running',
            'status_bucket' => 'running',
            'namespace' => $namespace,
            'started_at' => now()->subMinutes(10),
            'liveness_state' => 'running',
            'projection_schema_version' => RunSummaryProjector::SCHEMA_VERSION,
            'created_at' => now()->subMinutes(10),
            'updated_at' => now(),
        ]);

        WorkflowTask::create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'namespace' => $namespace,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'queue' => 'default',
            'available_at' => now()->subSeconds($availableSecondsAgo),
        ]);

        return $run;
    }

    private function createWorkerRegistrationsTable(): void
    {
        if (Schema::hasTable('workflow_worker_registrations')) {
            return;
        }

        Schema::create('workflow_worker_registrations', static function (Blueprint $table): void {
            $table->id();
            $table->string('worker_id', 255);
            $table->string('namespace', 128);
            $table->string('task_queue', 255);
            $table->string('runtime', 32);
            $table->string('sdk_version', 64)->nullable();
            $table->string('build_id', 255)->nullable();
            $table->json('supported_workflow_types')->nullable();
            $table->json('workflow_definition_fingerprints')->nullable();
            $table->json('supported_activity_types')->nullable();
            $table->unsignedInteger('max_concurrent_workflow_tasks')->default(100);
            $table->unsignedInteger('max_concurrent_activity_tasks')->default(100);
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['worker_id', 'namespace']);
            $table->index(['namespace', 'task_queue', 'status']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function coordinationAlertByKey(array $payload, string $key): ?array
    {
        $alerts = is_array($payload['coordination_alerts'] ?? null)
            ? $payload['coordination_alerts']
            : [];

        foreach ($alerts as $alert) {
            if (! is_array($alert)) {
                continue;
            }

            if (($alert['key'] ?? null) === $key) {
                return $alert;
            }
        }

        return null;
    }
}
