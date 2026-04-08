<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Waterline\Tests\Fixtures\V2\TestParallelActivityWorkflow;
use Waterline\Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

final class V2ParallelActivityDashboardWorkflowTest extends TestCase
{
    public function testShowReturnsParallelActivityWaitGroupPayloadForSelectedRun(): void
    {
        config()->set('waterline.engine_source', 'v2');
        Queue::fake();

        $workflow = WorkflowStub::make(TestParallelActivityWorkflow::class, 'waterline-parallel-activity');
        $workflow->start('Taylor', 'Abigail');

        $this->runReadyWorkflowTask($workflow->runId());

        $response = $this->get('/waterline/api/flows/' . $workflow->runId());

        $response
            ->assertOk()
            ->assertJsonPath('wait_kind', 'activity')
            ->assertJsonPath('open_wait_count', 2)
            ->assertJsonPath('waits.0.kind', 'activity')
            ->assertJsonPath('waits.0.status', 'open')
            ->assertJsonPath('waits.0.parallel_group_kind', 'activity')
            ->assertJsonPath('waits.0.parallel_group_id', 'parallel-activities:1:2')
            ->assertJsonPath('waits.0.parallel_group_size', 2)
            ->assertJsonPath('waits.0.parallel_group_index', 0)
            ->assertJsonPath('waits.1.parallel_group_kind', 'activity')
            ->assertJsonPath('waits.1.parallel_group_id', 'parallel-activities:1:2')
            ->assertJsonPath('waits.1.parallel_group_size', 2)
            ->assertJsonPath('waits.1.parallel_group_index', 1)
            ->assertJsonPath('waits.0.summary', 'Waiting for activity parallel-greeting.')
            ->assertJsonPath('waits.1.summary', 'Waiting for activity parallel-greeting.');
    }

    private function runReadyWorkflowTask(?string $runId): void
    {
        $this->assertIsString($runId);

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('created_at')
            ->firstOrFail();

        $this->app->call([new RunWorkflowTask($task->id), 'handle']);
    }
}
