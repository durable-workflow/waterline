<?php

namespace Waterline\Tests\Feature;

use Illuminate\Support\Str;
use Waterline\Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Support\WorkflowRunRetentionCleanup;

final class V2RetentionDetailsTest extends TestCase
{
    public function testCompletedAndFailedRunsExposePrunedDetailsWithoutChangingStatusOrListAccess(): void
    {
        config()->set('waterline.engine_source', 'v2');

        foreach (['completed', 'failed'] as $status) {
            $run = $this->createArchivedRun($status);
            $this->addRetainedDetails($run, $status === 'failed');
            $url = '/waterline/api/instances/'.$run->workflow_instance_id.'/runs/'.$run->id;

            $this->getJson($url)
                ->assertOk()
                ->assertJsonPath('details_pruned_at', null)
                ->assertJsonPath('retained_history_event_count', 1)
                ->assertJsonPath('retained_exception_count', $status === 'failed' ? 1 : 0);

            WorkflowRunRetentionCleanup::pruneRun($run->id);
            $prunedAt = $run->fresh()->details_pruned_at;
            $this->assertNotNull($prunedAt);

            $this->getJson($url)
                ->assertOk()
                ->assertJsonPath('status', $status)
                ->assertJsonPath('details_pruned_at', $prunedAt->toIso8601String())
                ->assertJsonPath('retained_history_event_count', 0)
                ->assertJsonPath('retained_exception_count', 0)
                ->assertJsonPath('archived_at', $run->archived_at?->jsonSerialize());

            $listedIds = collect($this->getJson('/waterline/api/flows/'.$status)
                ->assertOk()
                ->json('data'))->pluck('id')->all();
            $this->assertContains($run->id, $listedIds);
        }
    }

    public function testArchivedAndGenuinelyEmptyUnprunedRunsHaveNoPruningNotice(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $archived = $this->createArchivedRun('completed');
        $this->addRetainedDetails($archived, false);
        $empty = $this->createArchivedRun('completed');

        $this->getJson('/waterline/api/instances/'.$archived->workflow_instance_id.'/runs/'.$archived->id)
            ->assertOk()
            ->assertJsonPath('details_pruned_at', null)
            ->assertJsonPath('retained_history_event_count', 1);

        $this->getJson('/waterline/api/instances/'.$empty->workflow_instance_id.'/runs/'.$empty->id)
            ->assertOk()
            ->assertJsonPath('details_pruned_at', null)
            ->assertJsonPath('retained_history_event_count', 0)
            ->assertJsonPath('retained_exception_count', 0);
    }

    private function createArchivedRun(string $status): WorkflowRun
    {
        $instance = WorkflowInstance::create([
            'id' => (string) Str::ulid(),
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.retention',
            'namespace' => 'default',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.retention',
            'namespace' => 'default',
            'status' => $status,
            'connection' => 'sync',
            'queue' => 'default',
            'started_at' => now()->subHour(),
            'closed_at' => now()->subMinutes(2),
            'archived_at' => now()->subMinute(),
            'last_progress_at' => now()->subMinutes(2),
        ]);

        $instance->update(['current_run_id' => $run->id]);

        WorkflowRunSummary::create([
            'id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => $run->workflow_class,
            'workflow_type' => $run->workflow_type,
            'namespace' => 'default',
            'status' => $status,
            'status_bucket' => $status,
            'archived_at' => $run->archived_at,
            'started_at' => $run->started_at,
            'closed_at' => $run->closed_at,
        ]);

        return $run;
    }

    private function addRetainedDetails(WorkflowRun $run, bool $failed): void
    {
        WorkflowHistoryEvent::create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => $failed
                ? HistoryEventType::WorkflowFailed->value
                : HistoryEventType::WorkflowCompleted->value,
            'payload' => [],
            'recorded_at' => now()->subMinutes(2),
        ]);

        if ($failed) {
            WorkflowFailure::create([
                'id' => (string) Str::ulid(),
                'workflow_run_id' => $run->id,
                'source_kind' => 'workflow',
                'source_id' => $run->id,
                'propagation_kind' => 'workflow',
                'exception_class' => \RuntimeException::class,
                'message' => 'workflow failed',
                'file' => __FILE__,
            ]);
        }
    }
}
