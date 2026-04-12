<?php

namespace Waterline\Tests\Feature;

use Waterline\Tests\TestCase;
use Waterline\Tests\Fixtures\V2\TestCommandContractWorkflow;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;

class V2HealthControllerTest extends TestCase
{
    public function testHealthEndpointReturnsV2HealthSnapshot(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        config()->set('cache.default', 'array');
        config()->set('cache.stores.array.driver', 'array');

        $this->get('/waterline/api/v2/health')
            ->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('healthy', true)
            ->assertJsonPath('checks.0.name', 'engine_source')
            ->assertJsonPath('checks.0.status', 'ok')
            ->assertJsonPath('checks.1.name', 'backend_capabilities')
            ->assertJsonPath('checks.1.status', 'ok')
            ->assertJsonPath('engine_source.resolved', 'v2')
            ->assertJsonPath('operator_metrics.backend.supported', true);
    }

    public function testHealthEndpointReturnsUnavailableForBlockingBackendIssues(): void
    {
        config()->set('queue.default', 'sync');
        config()->set('queue.connections.sync.driver', 'sync');

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
        config()->set('cache.default', 'array');
        config()->set('cache.stores.array.driver', 'array');

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
        config()->set('cache.default', 'array');
        config()->set('cache.stores.array.driver', 'array');

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
}
