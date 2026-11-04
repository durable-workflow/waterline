<?php

namespace Waterline\Tests\Feature;

use Illuminate\Support\Carbon;
use Waterline\Tests\TestCase;
use Waterline\Models\SavedWorkflowView;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;

class SavedWorkflowTimeWindowTest extends TestCase
{
    public function testRollingSavedViewRetainsItsDurationWhenReloadedAtAnotherTime(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.saved_views.scope', 'ops');

        try {
            Carbon::setTestNow(Carbon::parse('2026-01-10T12:00:00Z'));

            $id = $this->postJson('/waterline/api/saved-views', [
                'name' => 'Started in the last day',
                'bucket' => 'running',
                'filters' => [
                    'started_time' => 'rolling',
                    'started_within_seconds' => 86400,
                ],
            ])->assertCreated()
                ->assertJsonPath('filters.started_time', 'rolling')
                ->assertJsonPath('filters.started_within_seconds', 86400)
                ->assertJsonMissingPath('filters.started_from')
                ->assertJsonMissingPath('filters.started_before')
                ->json('id');

            Carbon::setTestNow(Carbon::parse('2026-01-12T12:00:00Z'));

            $this->getJson('/waterline/api/saved-views/'.$id)
                ->assertOk()
                ->assertJsonPath('filters.started_time', 'rolling')
                ->assertJsonPath('filters.started_within_seconds', 86400)
                ->assertJsonMissingPath('filters.started_from')
                ->assertJsonMissingPath('filters.started_before');
        } finally {
            Carbon::setTestNow();
        }
    }
    public function testRollingMembershipMovesAtReloadAndUsesStartTimeInsteadOfSortTime(): void
    {
        config()->set('waterline.engine_source', 'v2');
        $this->createRun('old', '2026-01-08 12:00:00');
        $this->createRun('lower', '2026-01-09 12:00:00');
        $this->createRun('inside', '2026-01-10 11:00:00');
        $this->createRun('upper', '2026-01-10 12:00:00');
        $this->createRun('future', '2026-01-11 12:00:00');
        $this->createRun('unknown', null);
        $view = SavedWorkflowView::create(['name' => 'Recent', 'bucket' => 'running', 'filters' => [
            'started_time' => 'rolling', 'started_within_seconds' => 86400,
        ]]);
        try {
            Carbon::setTestNow('2026-01-10T12:00:00Z');
            $first = $this->getJson('/waterline/api/flows/running?view='.$view->id)->assertOk();
            $this->assertEqualsCanonicalizing(['lower', 'inside'], array_column($first->json('data'), 'id'));
            $first->assertJsonPath('time_windows.started_time.from', '2026-01-09T12:00:00.000000Z');
            Carbon::setTestNow('2026-01-11T12:00:00Z');
            $second = $this->getJson('/waterline/api/flows/running?view='.$view->id)->assertOk();
            $this->assertSame(['upper'], array_column($second->json('data'), 'id'));
            $second->assertJsonPath('time_windows.started_time.before', '2026-01-11T12:00:00.000000Z');
            $this->getJson('/waterline/api/flows/running?view='.$view->id.'&started_time=any')
                ->assertOk()->assertJsonCount(6, 'data')->assertJsonPath('time_windows.started_time', null);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testFixedOffsetRangeStaysFixedAndFiltersBeforePagination(): void
    {
        config()->set('waterline.engine_source', 'v2');
        for ($i = 0; $i < 51; $i++) {
            $this->createRun('inside-'.$i, '2026-01-10 12:00:00');
        }
        $this->createRun('outside', '2026-01-12 12:00:00');
        $query = http_build_query(['started_time' => 'fixed', 'started_from' => '2026-01-10T13:00:00+02:00',
            'started_before' => '2026-01-11T13:00:00+02:00']);
        try {
            foreach (['2026-01-15T00:00:00Z', '2026-02-01T00:00:00Z'] as $clock) {
                Carbon::setTestNow($clock);
                $this->getJson('/waterline/api/flows/running?'.$query)->assertOk()
                    ->assertJsonPath('total', 51)->assertJsonCount(50, 'data')
                    ->assertJsonPath('time_windows.started_time.from', '2026-01-10T11:00:00.000000Z');
                $this->getJson('/waterline/api/flows/running?'.$query.'&page=2')->assertOk()
                    ->assertJsonPath('total', 51)->assertJsonCount(1, 'data');
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testUtcMicrosecondBoundsAreIndependentOfTheApplicationTimezone(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('app.timezone', 'America/New_York');
        foreach (['before' => '123455', 'lower' => '123456', 'inside' => '123457', 'upper' => '123458'] as $id => $fraction) {
            $this->createRun($id, '2026-01-10 12:00:00');
            DB::table('workflow_run_summaries')->where('id', $id)->update(['started_at' => '2026-01-10 12:00:00.'.$fraction]);
        }
        $query = http_build_query(['started_time' => 'fixed', 'started_from' => '2026-01-10T07:00:00.123456-05:00',
            'started_before' => '2026-01-10T07:00:00.123458-05:00']);
        $result = $this->getJson('/waterline/api/flows/running?'.$query)->assertOk();
        $this->assertEqualsCanonicalizing(['lower', 'inside'], array_column($result->json('data'), 'id'));
    }

    public function testInvalidTimeInputIsRefusedBeforeListFallbackOrPersistence(): void
    {
        config()->set('waterline.engine_source', 'v2');
        $filters = ['started_time' => 'rolling', 'started_within_seconds' => -1];
        $this->postJson('/waterline/api/saved-views', ['name' => 'Invalid', 'bucket' => 'running', 'filters' => $filters])
            ->assertUnprocessable()->assertJsonValidationErrors('filters.started_time');
        $this->getJson('/waterline/api/flows/running?'.http_build_query($filters))
            ->assertUnprocessable()->assertJsonValidationErrors('filters.started_time');
        $this->assertSame(0, SavedWorkflowView::count());
    }

    public function testLegacyViewsRetainTheirVersionAndUnknownVersionsAreNotApplied(): void
    {
        config()->set('waterline.engine_source', 'v2');
        $this->createRun('one', '2026-01-10 12:00:00');
        $legacy = SavedWorkflowView::create(['name' => 'Legacy', 'bucket' => 'running', 'filter_version' => 6,
            'filters' => ['workflow_type' => 'workflow.test']]);
        $this->getJson('/waterline/api/flows/running?view='.$legacy->id)->assertOk()
            ->assertJsonPath('visibility_filters.saved_view.filter_version', 6)
            ->assertJsonPath('visibility_filters.saved_view_applied', true)->assertJsonCount(1, 'data');
        $legacy->update(['filter_version' => 99]);
        $this->getJson('/waterline/api/flows/running?view='.$legacy->id)->assertOk()
            ->assertJsonPath('visibility_filters.saved_view_applied', false)
            ->assertJsonPath('visibility_filters.saved_view.filter_version_supported', false);
    }

    public function testUnavailableTimeProjectionCannotBecomeAnUnfilteredDurableFallback(): void
    {
        config()->set('waterline.engine_source', 'v2');
        $this->createRun('one', '2026-01-10 12:00:00');
        Schema::drop('workflow_run_summaries');
        $this->getJson('/waterline/api/flows/running?started_time=rolling&started_within_seconds=86400')
            ->assertStatus(503)->assertJsonPath('message', 'The start-time filtered projection is unavailable. Waterline cannot return an unfiltered fallback list.');
    }

    public function testTimeWindowDoesNotBroadenTheConfiguredNamespace(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'orders');
        foreach (['orders', 'other'] as $namespace) {
            $this->createRun($namespace, '2026-01-10 12:00:00');
            DB::table('workflow_instances')->where('id', 'instance-'.$namespace)->update(['namespace' => $namespace]);
            DB::table('workflow_runs')->where('id', $namespace)->update(['namespace' => $namespace]);
            DB::table('workflow_run_summaries')->where('id', $namespace)->update(['namespace' => $namespace]);
        }
        $query = http_build_query(['started_time' => 'fixed', 'started_from' => '2026-01-10T00:00:00Z',
            'started_before' => '2026-01-11T00:00:00Z']);
        $this->getJson('/waterline/api/flows/running?'.$query)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'orders')->assertJsonPath('operator_scope.namespace', 'orders');
    }

    private function createRun(string $id, ?string $startedAt): void
    {
        WorkflowInstance::create(['id' => 'instance-'.$id, 'workflow_class' => 'WorkflowClass', 'workflow_type' => 'workflow.test', 'run_count' => 1]);
        WorkflowRun::create(['id' => $id, 'workflow_instance_id' => 'instance-'.$id, 'run_number' => 1,
            'workflow_class' => 'WorkflowClass', 'workflow_type' => 'workflow.test', 'status' => 'waiting', 'started_at' => $startedAt]);
        WorkflowRunSummary::create(['id' => $id, 'workflow_instance_id' => 'instance-'.$id, 'run_number' => 1,
            'engine_source' => 'v2', 'class' => 'WorkflowClass', 'workflow_type' => 'workflow.test', 'status' => 'waiting',
            'status_bucket' => 'running', 'started_at' => $startedAt, 'sort_timestamp' => '2026-02-01 00:00:00']);
    }

}
