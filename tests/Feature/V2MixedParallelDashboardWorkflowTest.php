<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Waterline\Tests\Fixtures\V2\TestMixedParallelWorkflow;
use Waterline\Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

final class V2MixedParallelDashboardWorkflowTest extends TestCase
{
    public function testShowReturnsMixedParallelWaitGroupPayloadForSelectedRun(): void
    {
        config()->set('waterline.engine_source', 'v2');
        Queue::fake();

        $workflow = WorkflowStub::make(TestMixedParallelWorkflow::class, 'waterline-mixed-parallel');
        $workflow->start('Taylor', 60);

        $this->runReadyWorkflowTask($workflow->runId());

        $response = $this->get('/waterline/api/flows/' . $workflow->runId());

        $response
            ->assertOk()
            ->assertJsonPath('wait_kind', 'activity')
            ->assertJsonPath('open_wait_count', 2);

        $waits = collect($response->json('waits'))
            ->where('status', 'open')
            ->values();

        $this->assertCount(2, $waits);
        $this->assertSame(['activity', 'child'], $waits->pluck('kind')->all());
        $this->assertSame(['mixed', 'mixed'], $waits->pluck('parallel_group_kind')->all());
        $this->assertSame(['parallel-calls:1:2', 'parallel-calls:1:2'], $waits->pluck('parallel_group_id')->all());
        $this->assertSame([0, 1], $waits->pluck('parallel_group_index')->all());
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
