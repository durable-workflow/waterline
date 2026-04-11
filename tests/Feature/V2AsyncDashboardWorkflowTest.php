<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Waterline\Tests\Fixtures\V2\TestAsyncWorkflow;
use Waterline\Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

final class V2AsyncDashboardWorkflowTest extends TestCase
{
    public function testShowReturnsAsyncChildWorkflowWaitPayloadForSelectedRunAfterCallbackSchedulesWork(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestAsyncWorkflow::class, 'waterline-async-child');
        $workflow->start('Taylor');

        $this->runReadyWorkflowTask($workflow->runId());

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $workflow->runId())
            ->where('link_type', 'child_workflow')
            ->firstOrFail();

        $this->runReadyWorkflowTask($link->child_workflow_run_id);

        $response = $this->get('/waterline/api/flows/' . $workflow->runId());

        $response
            ->assertOk()
            ->assertJsonPath('wait_kind', 'child')
            ->assertJsonPath('open_wait_count', 1)
            ->assertJsonPath('waits.0.kind', 'child')
            ->assertJsonPath('waits.0.status', 'open')
            ->assertJsonPath('waits.0.target_type', 'durable-workflow.async')
            ->assertJsonPath('waits.0.summary', 'Waiting for child workflow durable-workflow.async.')
            ->assertJsonPath('continuedWorkflows.0.link_type', 'child_workflow')
            ->assertJsonPath('continuedWorkflows.0.workflow_type', 'durable-workflow.async')
            ->assertJsonPath('continuedWorkflows.0.status', 'waiting');
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
