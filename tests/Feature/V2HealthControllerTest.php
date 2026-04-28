<?php

namespace Waterline\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
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
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('healthy', true)
            ->assertJsonPath('checks.0.name', 'engine_source')
            ->assertJsonPath('checks.0.status', 'ok')
            ->assertJsonPath('checks.1.name', 'backend_capabilities')
            ->assertJsonPath('checks.1.status', 'ok')
            ->assertJsonPath('engine_source.resolved', 'v2')
            ->assertJsonPath('readiness_contract.version', 1)
            ->assertJsonPath('readiness_contract.effective_states.health.state', 'delegates_to_v2_health_check')
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
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('operator_metrics.runs.total', 1)
            ->assertJsonPath('operator_metrics.tasks.ready_due', 1)
            ->assertJsonPath('operator_metrics.tasks.oldest_ready_due_at', now()->subSecond()->toJSON());
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

        $this->assertCount(1, $wakeChecks, 'Waterline health must surface the long_poll_wake_acceleration check so operators can read acceleration-layer health.');
        $this->assertSame('acceleration', $wakeChecks[0]['category']);

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
    }

    private function createRunSummaryWithReadyTask(string $namespace, int $availableSecondsAgo): void
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
    }
}
