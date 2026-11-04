<?php

namespace Waterline\Tests\Compatibility;

use Carbon\CarbonInterface;
use Composer\InstalledVersions;
use Illuminate\Support\Str;
use Waterline\Tests\TestCase;
use Workflow\V2\Contracts\HistoryExportRedactor;
use Workflow\V2\Contracts\OperatorObservabilityRepository;
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
                ->assertJsonPath('retained_history_event_count', null)
                ->assertJsonPath('retained_exception_count', null);

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
            ->assertJsonPath('retained_history_event_count', null);

        $this->getJson('/waterline/api/instances/'.$empty->workflow_instance_id.'/runs/'.$empty->id)
            ->assertOk()
            ->assertJsonPath('details_pruned_at', null)
            ->assertJsonPath('retained_history_event_count', null)
            ->assertJsonPath('retained_exception_count', null);
    }

    public function testFallbackHistoryExportMarksPrunedRunsIncompleteWithANewDedupeKey(): void
    {
        config()->set('waterline.engine_source', 'v2');
        $this->app->instance(OperatorObservabilityRepository::class, $this->unavailableObservability());

        foreach (['completed', 'failed'] as $status) {
            $run = $this->createArchivedRun($status);
            $this->addRetainedDetails($run, $status === 'failed');
            $url = '/waterline/api/instances/'.$run->workflow_instance_id.'/runs/'.$run->id.'/history-export';

            $retained = $this->getJson($url)
                ->assertOk()
                ->assertJsonPath('operator_visibility_degraded.reason', 'selected_run_projection_unavailable')
                ->assertJsonPath('history_complete', true)
                ->assertJsonPath('workflow.details_state', 'retained')
                ->assertJsonPath('workflow.details_pruned_at', null)
                ->assertJsonCount(1, 'history_events');

            WorkflowRunRetentionCleanup::pruneRun($run->id);
            $prunedAt = $run->fresh()->details_pruned_at;
            $this->assertNotNull($prunedAt);

            $pruned = $this->getJson($url)
                ->assertOk()
                ->assertJsonPath('operator_visibility_degraded.reason', 'selected_run_projection_unavailable')
                ->assertJsonPath('history_complete', false)
                ->assertJsonPath('workflow.status', $status)
                ->assertJsonPath('workflow.details_state', 'pruned')
                ->assertJsonPath('workflow.details_pruned_at', $prunedAt->toIso8601String())
                ->assertJsonCount(0, 'history_events');

            $this->assertNotSame($retained->json('dedupe_key'), $pruned->json('dedupe_key'));
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $pruned->json('integrity.checksum'));
        }
    }

    public function testFallbackDedupeKeyChangesWhenARunWithNoHistoryIsPruned(): void
    {
        config()->set('waterline.engine_source', 'v2');
        $this->app->instance(OperatorObservabilityRepository::class, $this->unavailableObservability());

        // Sequence and event count are identical before and after pruning an
        // empty run, so only the pruned marker can separate the two exports.
        $run = $this->createArchivedRun('completed');
        $url = '/waterline/api/instances/'.$run->workflow_instance_id.'/runs/'.$run->id.'/history-export';

        $before = $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('history_complete', true)
            ->assertJsonPath('workflow.details_state', 'retained')
            ->assertJsonCount(0, 'history_events');

        WorkflowRunRetentionCleanup::pruneRun($run->id);
        $this->assertNotNull($run->fresh()->details_pruned_at);

        $after = $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('history_complete', false)
            ->assertJsonPath('workflow.details_state', 'pruned')
            ->assertJsonCount(0, 'history_events');

        $this->assertNotSame($before->json('dedupe_key'), $after->json('dedupe_key'));
    }

    public function testPrimaryHistoryExportMarksPrunedRunsIncompleteOnCoreWithThePruningContract(): void
    {
        $version = InstalledVersions::getVersion('durable-workflow/workflow');
        if ($version === null || version_compare($version, '2.17.0.0', '<')) {
            $this->markTestSkipped('Core '.$version.' predates the retained-run pruning export contract (2.17.0).');
        }

        config()->set('waterline.engine_source', 'v2');

        foreach (['completed', 'failed'] as $status) {
            $run = $this->createArchivedRun($status);
            $this->addRetainedDetails($run, $status === 'failed');
            $url = '/waterline/api/instances/'.$run->workflow_instance_id.'/runs/'.$run->id.'/history-export';

            $retained = $this->getJson($url)
                ->assertOk()
                ->assertJsonMissingPath('operator_visibility_degraded')
                ->assertJsonPath('history_complete', true)
                ->assertJsonPath('workflow.details_state', 'retained');

            WorkflowRunRetentionCleanup::pruneRun($run->id);

            $pruned = $this->getJson($url)
                ->assertOk()
                ->assertJsonMissingPath('operator_visibility_degraded')
                ->assertJsonPath('history_complete', false)
                ->assertJsonPath('workflow.status', $status)
                ->assertJsonPath('workflow.details_state', 'pruned')
                ->assertJsonPath('workflow.details_pruned_at', fn ($value): bool => is_string($value) && $value !== '')
                ->assertJsonCount(0, 'history_events');

            $this->assertNotSame($retained->json('dedupe_key'), $pruned->json('dedupe_key'));
        }
    }

    private function unavailableObservability(): OperatorObservabilityRepository
    {
        return new class() implements OperatorObservabilityRepository {
            public function runDetail(WorkflowRun $run, ?int $timelineLimit = null): array
            {
                return [];
            }

            public function listItem(WorkflowRunSummary $summary): array
            {
                return [];
            }

            public function runHistoryExport(
                WorkflowRun $run,
                ?CarbonInterface $exportedAt = null,
                HistoryExportRedactor|callable|null $redactor = null,
            ): array {
                throw new \RuntimeException('Selected-run projections are unavailable.');
            }

            public function dashboardSummary(?CarbonInterface $now = null, ?string $namespace = null): array
            {
                return [];
            }

            public function metrics(?CarbonInterface $now = null, ?string $namespace = null): array
            {
                return [];
            }
        };
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
