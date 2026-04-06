<?php

namespace Waterline\Tests\Feature;

use Waterline\Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\RunSummaryProjector;

class V2CompatibilityDashboardWorkflowTest extends TestCase
{
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
            ->assertJsonPath('compatibility_reason', 'Requires compatibility [build-a]; this worker supports [build-b].')
            ->assertJsonPath('tasks.0.compatibility', 'build-a')
            ->assertJsonPath('tasks.0.compatibility_supported', false)
            ->assertJsonPath('tasks.0.compatibility_reason', 'Requires compatibility [build-a]; this worker supports [build-b].')
            ->assertJsonPath('tasks.0.summary', 'Workflow task is waiting for a compatible worker.');
    }
}
