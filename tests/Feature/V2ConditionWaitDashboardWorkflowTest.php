<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Waterline\Tests\Fixtures\V2\TestAwaitWithTimeoutWorkflow;
use Waterline\Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

final class V2ConditionWaitDashboardWorkflowTest extends TestCase
{
    public function testShowReturnsConditionWaitPayloadForAwaitWithTimeoutRun(): void
    {
        config()->set('waterline.engine_source', 'v2');
        Queue::fake();

        $workflow = WorkflowStub::make(TestAwaitWithTimeoutWorkflow::class, 'waterline-await-timeout');
        $workflow->start();

        $this->runReadyWorkflowTask($workflow->runId());

        $response = $this->get('/waterline/api/flows/' . $workflow->runId());

        $response
            ->assertOk()
            ->assertJsonPath('wait_kind', 'condition')
            ->assertJsonPath('wait_reason', 'Waiting for condition or timeout')
            ->assertJsonPath('liveness_state', 'waiting_for_condition')
            ->assertJsonPath('resume_source_kind', 'timer')
            ->assertJsonPath('waits.0.kind', 'condition')
            ->assertJsonPath('waits.0.status', 'open')
            ->assertJsonPath('waits.0.source_status', 'waiting')
            ->assertJsonPath('waits.0.external_only', true)
            ->assertJsonPath('waits.0.task_backed', true)
            ->assertJsonPath('waits.0.timeout_seconds', 5)
            ->assertJsonPath('tasks.0.type', 'timer')
            ->assertJsonPath('tasks.0.condition_wait_id', $response->json('waits.0.condition_wait_id'))
            ->assertJsonPath('tasks.0.timer_sequence', 1);

        $this->assertStringContainsString(
            'Condition timeout for 5 seconds',
            (string) $response->json('tasks.0.summary')
        );
    }

    private function runReadyWorkflowTask(string $runId): void
    {
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
