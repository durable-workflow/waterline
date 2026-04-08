<?php

namespace Waterline\Tests\Feature;

use Illuminate\Support\Carbon;
use Waterline\Tests\TestCase;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Support\RunSummarySortKey;

class V2DashboardWorkflowListTest extends TestCase
{
    public function testRunningFlowsAreSortedByStableV2SortContract(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.workflow_sort_column', 'created_at');

        $newest = $this->createRunningSummary(
            'order-newest',
            'run-a',
            Carbon::parse('2022-01-01 12:05:00'),
            Carbon::parse('2022-01-01 11:00:00'),
        );
        $oldest = $this->createRunningSummary(
            'order-oldest',
            'run-z',
            Carbon::parse('2022-01-01 12:01:00'),
            Carbon::parse('2022-01-01 13:00:00'),
        );
        $middle = $this->createRunningSummary(
            'order-middle',
            'run-m',
            Carbon::parse('2022-01-01 12:03:00'),
            Carbon::parse('2022-01-01 12:30:00'),
        );

        $response = $this->get('/waterline/api/flows/running');

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $newest->id)
            ->assertJsonPath('data.1.id', $middle->id)
            ->assertJsonPath('data.2.id', $oldest->id)
            ->assertJsonPath(
                'data.0.sort_key',
                RunSummarySortKey::key($newest->started_at, $newest->created_at, $newest->updated_at, $newest->id),
            )
            ->assertJsonPath('data.0.sort_timestamp', $newest->sort_timestamp?->jsonSerialize())
            ->assertJsonPath('data.0.instance_id', $newest->workflow_instance_id)
            ->assertJsonPath('data.0.selected_run_id', $newest->id)
            ->assertJsonPath('data.0.run_id', $newest->id);
    }

    public function testRunningFlowsBreakSortTimestampTiesByRunIdNotCreatedAt(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.workflow_sort_column', 'created_at');

        $sortTimestamp = Carbon::parse('2022-01-01 12:05:00');

        $olderRunId = '01JTESTSORTKEY00000000000001';
        $newerRunId = '01JTESTSORTKEY00000000000002';

        $older = $this->createRunningSummary(
            'order-older-tie',
            $olderRunId,
            $sortTimestamp,
            Carbon::parse('2022-01-01 12:30:00'),
        );
        $newer = $this->createRunningSummary(
            'order-newer-tie',
            $newerRunId,
            $sortTimestamp,
            Carbon::parse('2022-01-01 11:30:00'),
        );

        $response = $this->get('/waterline/api/flows/running');

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonPath('data.0.sort_key', $newer->sort_key)
            ->assertJsonPath('data.1.sort_key', $older->sort_key);
    }

    private function createRunningSummary(
        string $instanceId,
        string $runId,
        Carbon $startedAt,
        Carbon $createdAt,
    ): WorkflowRunSummary {
        $instance = WorkflowInstance::create([
            'id' => $instanceId,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => $runId,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'started_at' => $startedAt,
            'last_progress_at' => $startedAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $instance->update(['current_run_id' => $run->id]);

        return WorkflowRunSummary::create([
            'id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'status_bucket' => 'running',
            'started_at' => $startedAt,
            'sort_timestamp' => $startedAt,
            'sort_key' => RunSummarySortKey::key($startedAt, $createdAt, $createdAt, $run->id),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
