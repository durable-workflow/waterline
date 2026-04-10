<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Waterline\Tests\Fixtures\V2\TestAwaitWithTimeoutWorkflow;
use Waterline\Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\WorkflowStub;

final class V2ConditionWaitDashboardWorkflowTest extends TestCase
{
    public function testShowReturnsConditionWaitPayloadForAwaitWithTimeoutRun(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('queue.default', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestAwaitWithTimeoutWorkflow::class, 'waterline-await-timeout');
        $workflow->start();

        $this->runReadyWorkflowTask($workflow->runId());

        $response = $this->get('/waterline/api/flows/' . $workflow->runId());

        $response
            ->assertOk()
            ->assertJsonPath('wait_kind', 'condition')
            ->assertJsonPath('wait_reason', 'Waiting for condition approval.ready or timeout')
            ->assertJsonPath('liveness_state', 'waiting_for_condition')
            ->assertJsonPath('resume_source_kind', 'timer')
            ->assertJsonPath('waits.0.kind', 'condition')
            ->assertJsonPath('waits.0.status', 'open')
            ->assertJsonPath('waits.0.condition_key', 'approval.ready')
            ->assertJsonPath('waits.0.target_name', 'approval.ready')
            ->assertJsonPath('waits.0.source_status', 'waiting')
            ->assertJsonPath('waits.0.external_only', true)
            ->assertJsonPath('waits.0.task_backed', true)
            ->assertJsonPath('waits.0.timeout_seconds', 5)
            ->assertJsonPath('tasks.0.type', 'timer')
            ->assertJsonPath('tasks.0.condition_key', 'approval.ready')
            ->assertJsonPath('timers.0.condition_key', 'approval.ready')
            ->assertJsonPath('tasks.0.condition_wait_id', $response->json('waits.0.condition_wait_id'))
            ->assertJsonPath('tasks.0.timer_sequence', 1);

        $this->assertStringContainsString(
            'Condition timeout for 5 seconds',
            (string) $response->json('tasks.0.summary')
        );
    }

    public function testCanonicalDetailKeepsConditionWaitProjectionWhenTimerRowDrifts(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('queue.default', 'redis');
        Queue::fake();

        Carbon::setTestNow(Carbon::parse('2026-04-08 14:00:00'));

        try {
            $workflow = WorkflowStub::make(TestAwaitWithTimeoutWorkflow::class, 'waterline-await-timeout-drift');
            $workflow->start();

            $this->runReadyWorkflowTask($workflow->runId());

            /** @var WorkflowTimer $timer */
            $timer = WorkflowTimer::query()
                ->where('workflow_run_id', $workflow->runId())
                ->firstOrFail();

            $timerId = $timer->id;
            $deadlineAt = $timer->fire_at?->toJSON();

            $timer->delete();

            RunSummaryProjector::project(WorkflowRun::query()->findOrFail($workflow->runId()));

            $response = $this->get('/waterline/api/instances/waterline-await-timeout-drift');

            $response
                ->assertOk()
                ->assertJsonPath('wait_kind', 'condition')
                ->assertJsonPath('wait_reason', 'Waiting for condition approval.ready or timeout')
                ->assertJsonPath('liveness_state', 'waiting_for_condition')
                ->assertJsonPath('resume_source_kind', 'timer')
                ->assertJsonPath('resume_source_id', $timerId)
                ->assertJsonPath('wait_deadline_at', $deadlineAt)
                ->assertJsonPath('waits.0.kind', 'condition')
                ->assertJsonPath('waits.0.status', 'open')
                ->assertJsonPath('waits.0.condition_key', 'approval.ready')
                ->assertJsonPath('waits.0.resume_source_kind', 'timer')
                ->assertJsonPath('waits.0.resume_source_id', $timerId)
                ->assertJsonPath('waits.0.deadline_at', $deadlineAt)
                ->assertJsonPath('waits.0.timeout_seconds', 5)
                ->assertJsonPath('waits.0.task_backed', true)
                ->assertJsonPath('tasks.0.type', 'timer')
                ->assertJsonPath('tasks.0.timer_id', $timerId)
                ->assertJsonPath('tasks.0.condition_key', 'approval.ready')
                ->assertJsonPath('tasks.0.condition_wait_id', $response->json('waits.0.condition_wait_id'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testCanonicalDetailKeepsCancelledConditionTimeoutTimerFromTypedHistoryWhenRowsDrift(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('queue.default', 'redis');
        Queue::fake();

        Carbon::setTestNow(Carbon::parse('2026-04-08 14:30:00'));

        try {
            $workflow = WorkflowStub::make(TestAwaitWithTimeoutWorkflow::class, 'waterline-await-timeout-cancelled');
            $workflow->start();

            $this->runReadyWorkflowTask($workflow->runId());

            /** @var WorkflowTimer $timer */
            $timer = WorkflowTimer::query()
                ->where('workflow_run_id', $workflow->runId())
                ->firstOrFail();
            /** @var WorkflowTask $timerTask */
            $timerTask = WorkflowTask::query()
                ->where('workflow_run_id', $workflow->runId())
                ->where('task_type', TaskType::Timer->value)
                ->where('status', TaskStatus::Ready->value)
                ->sole();

            $timerId = $timer->id;
            $deadlineAt = $timer->fire_at?->toJSON();

            $workflow->attemptUpdate('approve', true);

            $this->assertTrue($workflow->refresh()->completed());

            $response = $this->get('/waterline/api/instances/waterline-await-timeout-cancelled');
            $conditionWaitId = $response->json('timers.0.condition_wait_id');

            $response
                ->assertOk()
                ->assertJsonPath('status', 'completed')
                ->assertJsonPath('timers.0.id', $timerId)
                ->assertJsonPath('timers.0.status', 'cancelled')
                ->assertJsonPath('timers.0.fire_at', $deadlineAt)
                ->assertJsonPath('timers.0.timer_kind', 'condition_timeout')
                ->assertJsonPath('waits.0.status', 'resolved')
                ->assertJsonPath('waits.0.source_status', 'satisfied');

            $this->assertIsString($conditionWaitId);
            $this->assertNotNull($response->json('timers.0.cancelled_at'));

            $timerTask->delete();
            $timer->delete();

            $response = $this->get('/waterline/api/instances/waterline-await-timeout-cancelled');

            $response
                ->assertOk()
                ->assertJsonPath('timers.0.id', $timerId)
                ->assertJsonPath('timers.0.status', 'cancelled')
                ->assertJsonPath('timers.0.fire_at', $deadlineAt)
                ->assertJsonPath('timers.0.timer_kind', 'condition_timeout')
                ->assertJsonPath('timers.0.condition_wait_id', $conditionWaitId);

            $this->assertNotNull($response->json('timers.0.cancelled_at'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testRepairRestoresTimeoutTimerTransportWhenTimerRowAndTaskDrift(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('queue.default', 'redis');
        Queue::fake();

        Carbon::setTestNow(Carbon::parse('2026-04-08 15:30:00'));

        try {
            $workflow = WorkflowStub::make(TestAwaitWithTimeoutWorkflow::class, 'waterline-await-timeout-repair');
            $workflow->start();

            $runId = $workflow->runId();

            $this->assertNotNull($runId);

            $this->runReadyWorkflowTask($runId);

            /** @var WorkflowTimer $timer */
            $timer = WorkflowTimer::query()
                ->where('workflow_run_id', $runId)
                ->firstOrFail();
            /** @var WorkflowTask $timerTask */
            $timerTask = WorkflowTask::query()
                ->where('workflow_run_id', $runId)
                ->where('task_type', TaskType::Timer->value)
                ->where('status', TaskStatus::Ready->value)
                ->sole();

            $timerId = $timer->id;
            $deadlineAt = $timer->fire_at?->toJSON();

            $show = $this->get('/waterline/api/instances/waterline-await-timeout-repair');
            $conditionWaitId = $show->json('waits.0.condition_wait_id');

            $this->assertIsString($conditionWaitId);
            $this->assertNotNull($deadlineAt);

            $timerTask->delete();
            $timer->delete();

            RunSummaryProjector::project(WorkflowRun::query()->findOrFail($runId));

            $this->get('/waterline/api/instances/waterline-await-timeout-repair')
                ->assertOk()
                ->assertJsonPath('wait_kind', 'condition')
                ->assertJsonPath('resume_source_kind', 'timer')
                ->assertJsonPath('resume_source_id', $timerId)
                ->assertJsonPath('liveness_state', 'repair_needed')
                ->assertJsonPath('can_repair', true)
                ->assertJsonPath('waits.0.kind', 'condition')
                ->assertJsonPath('waits.0.status', 'open')
                ->assertJsonPath('waits.0.task_backed', false)
                ->assertJsonPath('waits.0.condition_wait_id', $conditionWaitId)
                ->assertJsonPath('waits.0.resume_source_kind', 'timer')
                ->assertJsonPath('waits.0.resume_source_id', $timerId)
                ->assertJsonPath('waits.0.deadline_at', $deadlineAt)
                ->assertJsonPath('tasks.0.type', 'workflow')
                ->assertJsonMissingPath('tasks.1');

            $this->post('/waterline/api/instances/waterline-await-timeout-repair/repair')
                ->assertStatus(200)
                ->assertJsonPath('outcome', 'repair_dispatched')
                ->assertJsonPath('workflow_id', 'waterline-await-timeout-repair')
                ->assertJsonPath('run_id', $runId)
                ->assertJsonPath('target_scope', 'instance')
                ->assertJsonPath('command_status', 'accepted');

            /** @var WorkflowTimer $restoredTimer */
            $restoredTimer = WorkflowTimer::query()->findOrFail($timerId);
            /** @var WorkflowTask $repairedTask */
            $repairedTask = WorkflowTask::query()
                ->where('workflow_run_id', $runId)
                ->where('task_type', TaskType::Timer->value)
                ->where('status', TaskStatus::Ready->value)
                ->sole();

            $this->assertSame('pending', $restoredTimer->status->value);
            $this->assertSame($deadlineAt, $restoredTimer->fire_at?->toJSON());
            $this->assertSame([
                'timer_id' => $timerId,
                'condition_wait_id' => $conditionWaitId,
                'condition_key' => 'approval.ready',
            ], $repairedTask->payload);
            $this->assertSame($deadlineAt, $repairedTask->available_at?->toJSON());
            $this->assertSame(1, $repairedTask->repair_count);

            Queue::assertPushed(
                RunTimerTask::class,
                static fn (RunTimerTask $job): bool => $job->taskId === $repairedTask->id,
            );

            $this->get('/waterline/api/instances/waterline-await-timeout-repair')
                ->assertOk()
                ->assertJsonPath('wait_kind', 'condition')
                ->assertJsonPath('liveness_state', 'waiting_for_condition')
                ->assertJsonPath('can_repair', false)
                ->assertJsonPath('waits.0.kind', 'condition')
                ->assertJsonPath('waits.0.task_backed', true)
                ->assertJsonPath('waits.0.condition_wait_id', $conditionWaitId)
                ->assertJsonPath('tasks.0.id', $repairedTask->id)
                ->assertJsonPath('tasks.0.type', 'timer')
                ->assertJsonPath('tasks.0.timer_id', $timerId)
                ->assertJsonPath('tasks.0.condition_wait_id', $conditionWaitId);
        } finally {
            Carbon::setTestNow();
        }
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
