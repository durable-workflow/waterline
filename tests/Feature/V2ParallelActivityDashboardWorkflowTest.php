<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Queue;
use Waterline\Tests\Fixtures\V2\TestNestedParallelActivityWorkflow;
use Waterline\Tests\Fixtures\V2\TestParallelActivityWorkflow;
use Waterline\Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\RunSummaryProjector;
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
            ->assertJsonPath('waits.0.parallel_group_path.0.parallel_group_id', 'parallel-activities:1:2')
            ->assertJsonPath('waits.1.parallel_group_kind', 'activity')
            ->assertJsonPath('waits.1.parallel_group_id', 'parallel-activities:1:2')
            ->assertJsonPath('waits.1.parallel_group_size', 2)
            ->assertJsonPath('waits.1.parallel_group_index', 1)
            ->assertJsonPath('waits.1.parallel_group_path.0.parallel_group_id', 'parallel-activities:1:2')
            ->assertJsonPath('waits.0.summary', 'Waiting for activity parallel-greeting.')
            ->assertJsonPath('waits.1.summary', 'Waiting for activity parallel-greeting.');
    }

    public function testShowReturnsNestedParallelActivityWaitPathsForSelectedRun(): void
    {
        config()->set('waterline.engine_source', 'v2');
        Queue::fake();

        $workflow = WorkflowStub::make(TestNestedParallelActivityWorkflow::class, 'waterline-nested-parallel-activity');
        $workflow->start('Taylor', 'Abigail', 'Selena');

        $this->runReadyWorkflowTask($workflow->runId());

        $response = $this->get('/waterline/api/flows/' . $workflow->runId());

        $response
            ->assertOk()
            ->assertJsonPath('wait_kind', 'activity')
            ->assertJsonPath('open_wait_count', 3);

        $waits = collect($response->json('waits'))
            ->where('status', 'open')
            ->values();

        $this->assertCount(3, $waits);
        $this->assertSame('parallel-activities:1:3', $waits[0]['parallel_group_id']);
        $this->assertEquals([
            [
                'parallel_group_id' => 'parallel-activities:1:3',
                'parallel_group_kind' => 'activity',
                'parallel_group_base_sequence' => 1,
                'parallel_group_size' => 3,
                'parallel_group_index' => 0,
            ],
        ], $waits[0]['parallel_group_path']);
        $this->assertSame('parallel-activities:2:2', $waits[1]['parallel_group_id']);
        $this->assertEquals([
            [
                'parallel_group_id' => 'parallel-activities:1:3',
                'parallel_group_kind' => 'activity',
                'parallel_group_base_sequence' => 1,
                'parallel_group_size' => 3,
                'parallel_group_index' => 1,
            ],
            [
                'parallel_group_id' => 'parallel-activities:2:2',
                'parallel_group_kind' => 'activity',
                'parallel_group_base_sequence' => 2,
                'parallel_group_size' => 2,
                'parallel_group_index' => 0,
            ],
        ], $waits[1]['parallel_group_path']);
        $this->assertSame('parallel-activities:2:2', $waits[2]['parallel_group_id']);
        $this->assertEquals([
            [
                'parallel_group_id' => 'parallel-activities:1:3',
                'parallel_group_kind' => 'activity',
                'parallel_group_base_sequence' => 1,
                'parallel_group_size' => 3,
                'parallel_group_index' => 2,
            ],
            [
                'parallel_group_id' => 'parallel-activities:2:2',
                'parallel_group_kind' => 'activity',
                'parallel_group_base_sequence' => 2,
                'parallel_group_size' => 2,
                'parallel_group_index' => 1,
            ],
        ], $waits[2]['parallel_group_path']);
    }

    public function testShowUsesActivityExecutionGroupSnapshotWhenOpenHistoryMetadataIsMissing(): void
    {
        config()->set('waterline.engine_source', 'v2');
        Queue::fake();

        $workflow = WorkflowStub::make(TestParallelActivityWorkflow::class, 'waterline-parallel-activity-row-snapshot');
        $workflow->start('Taylor', 'Abigail');
        $runId = $workflow->runId();

        $this->runReadyWorkflowTask($runId);
        $this->removeActivityHistoryParallelMetadata($runId, 1);

        RunSummaryProjector::project(
            WorkflowRun::query()->findOrFail($runId)
                ->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $response = $this->get('/waterline/api/flows/' . $runId);

        $response
            ->assertOk()
            ->assertJsonPath('waits.0.kind', 'activity')
            ->assertJsonPath('waits.0.sequence', 1)
            ->assertJsonPath('waits.0.parallel_group_kind', 'activity')
            ->assertJsonPath('waits.0.parallel_group_id', 'parallel-activities:1:2')
            ->assertJsonPath('waits.0.parallel_group_index', 0)
            ->assertJsonPath('waits.0.parallel_group_path.0.parallel_group_id', 'parallel-activities:1:2');
    }

    public function testShowSkipsLegacyVisibilityFallbackQueriesWhenWorkflowVisibilityColumnsAreAbsent(): void
    {
        config()->set('waterline.engine_source', 'v2');
        Queue::fake();

        $workflow = WorkflowStub::make(TestParallelActivityWorkflow::class, 'waterline-parallel-activity-no-visibility-columns');
        $workflow->start('Taylor', 'Abigail');
        $runId = $workflow->runId();

        $this->assertIsString($runId);
        $this->runReadyWorkflowTask($runId);

        $droppedRunMemo = Schema::hasColumn('workflow_runs', 'memo');
        $droppedRunSearchAttributes = Schema::hasColumn('workflow_runs', 'search_attributes');
        $droppedInstanceMemo = Schema::hasColumn('workflow_instances', 'memo');

        if ($droppedRunMemo || $droppedRunSearchAttributes) {
            Schema::table('workflow_runs', static function (Blueprint $table) use ($droppedRunMemo, $droppedRunSearchAttributes): void {
                $columns = [];

                if ($droppedRunMemo) {
                    $columns[] = 'memo';
                }

                if ($droppedRunSearchAttributes) {
                    $columns[] = 'search_attributes';
                }

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        if ($droppedInstanceMemo) {
            Schema::table('workflow_instances', static function (Blueprint $table): void {
                $table->dropColumn('memo');
            });
        }

        try {
            $this->get('/waterline/api/flows/' . $runId)
                ->assertOk()
                ->assertJsonPath('wait_kind', 'activity')
                ->assertJsonPath('open_wait_count', 2)
                ->assertJsonPath('waits.0.kind', 'activity');
        } finally {
            if ($droppedRunMemo || $droppedRunSearchAttributes) {
                Schema::table('workflow_runs', static function (Blueprint $table) use ($droppedRunMemo, $droppedRunSearchAttributes): void {
                    if ($droppedRunMemo) {
                        $table->json('memo')->nullable();
                    }

                    if ($droppedRunSearchAttributes) {
                        $table->json('search_attributes')->nullable();
                    }
                });
            }

            if ($droppedInstanceMemo) {
                Schema::table('workflow_instances', static function (Blueprint $table): void {
                    $table->json('memo')->nullable();
                });
            }
        }
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

    private function removeActivityHistoryParallelMetadata(?string $runId, int $sequence): void
    {
        $this->assertIsString($runId);

        WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->whereIn('event_type', [
                HistoryEventType::ActivityScheduled->value,
                HistoryEventType::ActivityStarted->value,
                HistoryEventType::ActivityHeartbeatRecorded->value,
                HistoryEventType::ActivityRetryScheduled->value,
                HistoryEventType::ActivityCompleted->value,
                HistoryEventType::ActivityFailed->value,
            ])
            ->get()
            ->each(static function (WorkflowHistoryEvent $event) use ($sequence): void {
                $payload = is_array($event->payload) ? $event->payload : [];

                if (($payload['sequence'] ?? null) !== $sequence) {
                    return;
                }

                unset(
                    $payload['parallel_group_id'],
                    $payload['parallel_group_kind'],
                    $payload['parallel_group_base_sequence'],
                    $payload['parallel_group_size'],
                    $payload['parallel_group_index'],
                    $payload['parallel_group_path'],
                );

                $event->forceFill(['payload' => $payload])->save();
            });
    }
}
