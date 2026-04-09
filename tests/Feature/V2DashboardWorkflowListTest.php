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

    public function testTerminalListEndpointsFilterByRawStatus(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $failed = $this->createTerminalSummary('terminal-failed', 'run-failed', 'failed');
        $cancelled = $this->createTerminalSummary('terminal-cancelled', 'run-cancelled', 'cancelled');
        $terminated = $this->createTerminalSummary('terminal-terminated', 'run-terminated', 'terminated');

        $this->get('/waterline/api/flows/failed')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $failed->id)
            ->assertJsonPath('data.0.status', 'failed')
            ->assertJsonPath('data.0.is_terminal', true);

        $this->get('/waterline/api/flows/cancelled')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $cancelled->id)
            ->assertJsonPath('data.0.status', 'cancelled')
            ->assertJsonPath('data.0.status_bucket', 'failed')
            ->assertJsonPath('data.0.is_terminal', true);

        $this->get('/waterline/api/flows/terminated')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $terminated->id)
            ->assertJsonPath('data.0.status', 'terminated')
            ->assertJsonPath('data.0.status_bucket', 'failed')
            ->assertJsonPath('data.0.is_terminal', true);
    }

    public function testV2ListRoutesCanFilterByVisibilityFields(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $matching = $this->createRunningSummary(
            'visible-order',
            'run-visible-order',
            Carbon::parse('2022-01-01 12:05:00'),
            Carbon::parse('2022-01-01 12:05:00'),
            businessKey: 'order-123',
            visibilityLabels: ['tenant' => 'acme', 'region' => 'us-east'],
        );
        $this->createRunningSummary(
            'other-order',
            'run-other-order',
            Carbon::parse('2022-01-01 12:06:00'),
            Carbon::parse('2022-01-01 12:06:00'),
            businessKey: 'order-456',
            visibilityLabels: ['tenant' => 'beta', 'region' => 'us-east'],
        );

        $this->get('/waterline/api/flows/running?workflow_type=workflow.test&business_key=order-123&label[tenant]=acme')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id)
            ->assertJsonPath('data.0.business_key', 'order-123')
            ->assertJsonPath('data.0.visibility_labels.tenant', 'acme');
    }

    private function createRunningSummary(
        string $instanceId,
        string $runId,
        Carbon $startedAt,
        Carbon $createdAt,
        ?string $businessKey = null,
        array $visibilityLabels = [],
    ): WorkflowRunSummary {
        $instance = WorkflowInstance::create([
            'id' => $instanceId,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'business_key' => $businessKey,
            'visibility_labels' => $visibilityLabels === [] ? null : $visibilityLabels,
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => $runId,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'business_key' => $businessKey,
            'visibility_labels' => $visibilityLabels === [] ? null : $visibilityLabels,
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
            'business_key' => $businessKey,
            'visibility_labels' => $visibilityLabels === [] ? null : $visibilityLabels,
            'status' => 'waiting',
            'status_bucket' => 'running',
            'started_at' => $startedAt,
            'sort_timestamp' => $startedAt,
            'sort_key' => RunSummarySortKey::key($startedAt, $createdAt, $createdAt, $run->id),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function createTerminalSummary(
        string $instanceId,
        string $runId,
        string $status,
    ): WorkflowRunSummary {
        $startedAt = Carbon::parse('2022-01-01 12:00:00');
        $closedAt = Carbon::parse('2022-01-01 12:05:00');

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
            'status' => $status,
            'closed_reason' => $status,
            'started_at' => $startedAt,
            'closed_at' => $closedAt,
            'last_progress_at' => $closedAt,
            'created_at' => $startedAt,
            'updated_at' => $closedAt,
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
            'status' => $status,
            'status_bucket' => 'failed',
            'closed_reason' => $status,
            'started_at' => $startedAt,
            'closed_at' => $closedAt,
            'duration_ms' => $closedAt->diffInMilliseconds($startedAt),
            'sort_timestamp' => $startedAt,
            'sort_key' => RunSummarySortKey::key($startedAt, $startedAt, $closedAt, $run->id),
            'created_at' => $startedAt,
            'updated_at' => $closedAt,
        ]);
    }
}
