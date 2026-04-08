<?php

namespace Waterline\Tests\Feature;

use Waterline\Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Support\WorkerCompatibilityFleet;

class V2CompatibilityDashboardWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        WorkerCompatibilityFleet::clear();
    }

    public function testShowIncludesRunAndTaskCompatibilityMetadata(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('workflows.v2.compatibility.supported', ['build-b']);

        $instance = WorkflowInstance::create([
            'id' => 'compat-waterline-instance',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNCOMPAT000001',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'compatibility' => 'build-a',
            'arguments' => Serializer::serialize(['name' => 'Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(10),
            'last_progress_at' => now()->subMinutes(1),
        ]);

        $instance->update(['current_run_id' => $run->id]);

        WorkflowTask::create([
            'id' => '01JTESTFLOWTASKCOMPAT00001',
            'workflow_run_id' => $run->id,
            'task_type' => 'workflow',
            'status' => 'ready',
            'available_at' => now()->subMinute(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->get('/waterline/api/flows/' . $run->id)
            ->assertStatus(200)
            ->assertJsonPath('compatibility', 'build-a')
            ->assertJsonPath('compatibility_supported', false)
            ->assertJsonPath('compatibility_supported_in_fleet', false)
            ->assertJsonPath('compatibility_reason', 'Requires compatibility [build-a]; this worker supports [build-b].')
            ->assertJsonPath('compatibility_fleet_reason', 'No active worker heartbeat for connection [redis] queue [default] advertises compatibility [build-a].')
            ->assertJsonPath('liveness_state', 'workflow_task_waiting_for_compatible_worker')
            ->assertJsonPath('wait_reason', 'Workflow task waiting for a compatible worker')
            ->assertJsonPath(
                'liveness_reason',
                'Workflow task 01JTESTFLOWTASKCOMPAT00001 is ready but waiting for a compatible worker. Requires compatibility [build-a]; this worker supports [build-b]. No active worker heartbeat for connection [redis] queue [default] advertises compatibility [build-a].'
            )
            ->assertJsonPath('can_repair', false)
            ->assertJsonPath('repair_blocked_reason', 'waiting_for_compatible_worker')
            ->assertJsonPath('tasks.0.compatibility', 'build-a')
            ->assertJsonPath('tasks.0.compatibility_supported', false)
            ->assertJsonPath('tasks.0.compatibility_supported_in_fleet', false)
            ->assertJsonPath('tasks.0.compatibility_reason', 'Requires compatibility [build-a]; this worker supports [build-b].')
            ->assertJsonPath('tasks.0.compatibility_fleet_reason', 'No active worker heartbeat for connection [redis] queue [default] advertises compatibility [build-a].')
            ->assertJsonPath('tasks.0.summary', 'Workflow task is waiting for a compatible worker.');
    }

    public function testShowFallsBackToRunCompatibilityForLegacyNullTaskMarker(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('workflows.v2.compatibility.supported', ['build-b']);

        $instance = WorkflowInstance::create([
            'id' => 'compat-waterline-null-task',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNNULLTASK0001',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'compatibility' => 'build-a',
            'arguments' => Serializer::serialize(['name' => 'Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(10),
            'last_progress_at' => now()->subMinute(),
        ]);

        $instance->update(['current_run_id' => $run->id]);

        WorkflowTask::create([
            'id' => '01JTESTFLOWTASKNULLTASK0001',
            'workflow_run_id' => $run->id,
            'task_type' => 'workflow',
            'status' => 'ready',
            'available_at' => now()->subMinute(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => null,
        ]);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->get('/waterline/api/flows/' . $run->id)
            ->assertStatus(200)
            ->assertJsonPath('compatibility', 'build-a')
            ->assertJsonPath('tasks.0.compatibility', 'build-a')
            ->assertJsonPath('tasks.0.compatibility_supported', false)
            ->assertJsonPath('tasks.0.compatibility_supported_in_fleet', false)
            ->assertJsonPath('tasks.0.compatibility_reason', 'Requires compatibility [build-a]; this worker supports [build-b].')
            ->assertJsonPath('tasks.0.compatibility_fleet_reason', 'No active worker heartbeat for connection [redis] queue [default] advertises compatibility [build-a].')
            ->assertJsonPath('tasks.0.summary', 'Workflow task is waiting for a compatible worker.');
    }

    public function testShowDistinguishesFleetSupportFromLocalClaimability(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('workflows.v2.compatibility.supported', ['build-b']);

        WorkerCompatibilityFleet::record(['build-a'], 'redis', 'default', 'worker-build-a');

        $instance = WorkflowInstance::create([
            'id' => 'compat-waterline-fleet',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNFLEETCOMPAT01',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'compatibility' => 'build-a',
            'arguments' => Serializer::serialize(['name' => 'Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(10),
            'last_progress_at' => now()->subMinute(),
        ]);

        $instance->update(['current_run_id' => $run->id]);

        WorkflowTask::create([
            'id' => '01JTESTFLOWTASKFLEETCOMPAT1',
            'workflow_run_id' => $run->id,
            'task_type' => 'workflow',
            'status' => 'ready',
            'available_at' => now()->subMinute(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->get('/waterline/api/flows/' . $run->id)
            ->assertStatus(200)
            ->assertJsonPath('compatibility_supported', false)
            ->assertJsonPath('compatibility_supported_in_fleet', true)
            ->assertJsonPath('compatibility_reason', 'Requires compatibility [build-a]; this worker supports [build-b].')
            ->assertJsonPath('compatibility_fleet_reason', null)
            ->assertJsonPath('liveness_state', 'workflow_task_ready')
            ->assertJsonPath('wait_reason', 'Workflow task ready')
            ->assertJsonPath('tasks.0.compatibility_supported', false)
            ->assertJsonPath('tasks.0.compatibility_supported_in_fleet', true)
            ->assertJsonPath('tasks.0.compatibility_fleet_reason', null)
            ->assertJsonPath('tasks.0.summary', 'Workflow task ready to resume the selected run.');
    }
}
