<?php

namespace Waterline\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Waterline\Tests\TestCase;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Support\RunSummarySortKey;
use Workflow\V2\Support\VisibilityFilters;

class V2DashboardWorkflowListTest extends TestCase
{
    public function testRunningFlowsUseConfiguredRunSummaryModel(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('workflows.v2.run_summary_model', ConfiguredWaterlineListRunSummary::class);

        $this->createConfiguredSummaryTable();

        $startedAt = Carbon::parse('2022-01-01 12:05:00');
        $createdAt = Carbon::parse('2022-01-01 12:00:00');

        $instance = WorkflowInstance::create([
            'id' => 'configured-list-instance',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'business_key' => 'configured-list-business',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => 'configured-list-run',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'business_key' => 'configured-list-business',
            'status' => RunStatus::Waiting->value,
            'started_at' => $startedAt,
            'last_progress_at' => $startedAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $instance->update(['current_run_id' => $run->id]);

        ConfiguredWaterlineListRunSummary::create([
            'id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'business_key' => 'configured-list-business',
            'status' => RunStatus::Waiting->value,
            'status_bucket' => 'running',
            'started_at' => $startedAt,
            'sort_timestamp' => $startedAt,
            'sort_key' => RunSummarySortKey::key($startedAt, $createdAt, $createdAt, $run->id),
            'config_marker' => 'configured-summary',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $this->get('/waterline/api/flows/running')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $run->id)
            ->assertJsonPath('data.0.business_key', 'configured-list-business')
            ->assertJsonPath('data.0.config_marker', 'configured-summary');
    }

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

    public function testV2ListRoutesCanApplySavedVisibilityViews(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.saved_views.scope', 'ops');

        $matching = $this->createRunningSummary(
            'saved-visible-order',
            'run-saved-visible-order',
            Carbon::parse('2022-01-01 12:05:00'),
            Carbon::parse('2022-01-01 12:05:00'),
            businessKey: 'order-123',
            visibilityLabels: ['tenant' => 'acme', 'region' => 'us-east'],
        );
        $this->createRunningSummary(
            'saved-other-order',
            'run-saved-other-order',
            Carbon::parse('2022-01-01 12:06:00'),
            Carbon::parse('2022-01-01 12:06:00'),
            businessKey: 'order-456',
            visibilityLabels: ['tenant' => 'beta', 'region' => 'us-east'],
        );

        $saved = $this->postJson('/waterline/api/saved-views', [
            'name' => 'Acme invoice sync',
            'bucket' => 'running',
            'filters' => [
                'workflow_type' => 'workflow.test',
                'labels' => [
                    'tenant' => 'acme',
                ],
            ],
            'shared' => true,
        ]);

        $id = $saved
            ->assertCreated()
            ->assertJsonPath('name', 'Acme invoice sync')
            ->assertJsonPath('bucket', 'running')
            ->assertJsonPath('scope', 'ops')
            ->assertJsonPath('shared', true)
            ->assertJsonPath('system', false)
            ->assertJsonPath('filters.workflow_type', 'workflow.test')
            ->assertJsonPath('filters.labels.tenant', 'acme')
            ->json('id');

        $this->assertDatabaseHas('waterline_saved_views', [
            'id' => $id,
            'scope' => 'ops',
            'bucket' => 'running',
            'name' => 'Acme invoice sync',
        ]);

        $this->get('/waterline/api/saved-views?bucket=running')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'system:running')
            ->assertJsonPath('data.1.id', $id)
            ->assertJsonPath('data.1.filters.labels.tenant', 'acme');

        $this->get('/waterline/api/flows/running?view='.$id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id);

        $this->get('/waterline/api/flows/running?view='.$id.'&label[region]=eu-west')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->get('/waterline/api/flows/completed?view='.$id)
            ->assertStatus(422);
    }

    public function testV2ListRoutesCanFilterByExpandedVisibilityFieldsAndEchoAppliedContract(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.saved_views.scope', 'ops');

        $matching = $this->createRunningSummary(
            'visible-signal-order',
            'run-visible-signal-order',
            Carbon::parse('2022-01-01 12:05:00'),
            Carbon::parse('2022-01-01 12:05:00'),
            businessKey: 'order-123',
            visibilityLabels: ['tenant' => 'acme'],
            waitKind: 'signal',
            livenessState: 'waiting_for_signal',
            repairBlockedReason: 'unsupported_history',
            continueAsNewRecommended: true,
        );
        $this->createRunningSummary(
            'visible-timer-order',
            'run-visible-timer-order',
            Carbon::parse('2022-01-01 12:06:00'),
            Carbon::parse('2022-01-01 12:06:00'),
            businessKey: 'order-123',
            visibilityLabels: ['tenant' => 'acme'],
            waitKind: 'timer',
            livenessState: 'timer_scheduled',
            repairBlockedReason: 'repair_not_needed',
            isCurrentRun: false,
            continueAsNewRecommended: false,
        );

        $savedViewId = $this->postJson('/waterline/api/saved-views', [
            'name' => 'Signal waits',
            'bucket' => 'running',
            'filters' => [
                'workflow_type' => 'workflow.test',
                'wait_kind' => 'signal',
                'repair_blocked_reason' => 'unsupported_history',
                'continue_as_new_recommended' => true,
                'archived' => false,
                'is_terminal' => false,
            ],
            'shared' => true,
        ])->assertCreated()->json('id');

        $this->get('/waterline/api/flows/running?view='.$savedViewId.'&instance_id='.$matching->workflow_instance_id.'&run_id='.$matching->id.'&is_current_run=true&liveness_state=waiting_for_signal')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id)
            ->assertJsonPath('data.0.repair_blocked_reason', 'unsupported_history')
            ->assertJsonPath('data.0.repair_blocked.code', 'unsupported_history')
            ->assertJsonPath('data.0.repair_blocked.label', 'Replay Blocked')
            ->assertJsonPath(
                'data.0.repair_blocked.description',
                'Repair is blocked because only unsupported diagnostic history remains.',
            )
            ->assertJsonPath('data.0.repair_blocked.tone', 'dark')
            ->assertJsonPath('data.0.repair_blocked.badge_visible', true)
            ->assertJsonPath('visibility_filters.version', VisibilityFilters::VERSION)
            ->assertJsonPath('visibility_filters.bucket', 'running')
            ->assertJsonPath('visibility_filters.saved_view.id', $savedViewId)
            ->assertJsonPath('visibility_filters.applied.workflow_type', 'workflow.test')
            ->assertJsonPath('visibility_filters.applied.wait_kind', 'signal')
            ->assertJsonPath('visibility_filters.applied.liveness_state', 'waiting_for_signal')
            ->assertJsonPath('visibility_filters.applied.repair_blocked_reason', 'unsupported_history')
            ->assertJsonPath('visibility_filters.applied.is_current_run', true)
            ->assertJsonPath('visibility_filters.applied.continue_as_new_recommended', true)
            ->assertJsonPath('visibility_filters.applied.instance_id', $matching->workflow_instance_id)
            ->assertJsonPath('visibility_filters.applied.run_id', $matching->id)
            ->assertJsonPath('visibility_filters.applied.archived', false)
            ->assertJsonPath('visibility_filters.applied.is_terminal', false)
            ->assertJsonPath('visibility_filters.definition.fields.instance_id.label', 'Instance ID')
            ->assertJsonPath('visibility_filters.definition.fields.instance_id.type', 'string')
            ->assertJsonPath('visibility_filters.definition.fields.instance_id.input', 'text')
            ->assertJsonPath('visibility_filters.definition.fields.status_bucket.input', 'select')
            ->assertJsonPath('visibility_filters.definition.fields.is_current_run.type', 'boolean')
            ->assertJsonPath('visibility_filters.definition.fields.is_current_run.input', 'boolean_select')
            ->assertJsonPath('visibility_filters.definition.fields.continue_as_new_recommended.type', 'boolean')
            ->assertJsonPath('visibility_filters.definition.fields.continue_as_new_recommended.input', 'boolean_select')
            ->assertJsonPath('visibility_filters.definition.fields.repair_blocked_reason.label', 'Repair Blocked Reason')
            ->assertJsonPath('visibility_filters.definition.fields.repair_blocked_reason.type', 'string')
            ->assertJsonPath('visibility_filters.definition.fields.repair_blocked_reason.input', 'select')
            ->assertJsonPath(
                'visibility_filters.definition.fields.repair_blocked_reason.options.0.description',
                'Repair is blocked because only unsupported diagnostic history remains.',
            )
            ->assertJsonPath('visibility_filters.definition.fields.repair_blocked_reason.options.0.tone', 'dark')
            ->assertJsonPath('visibility_filters.definition.fields.repair_blocked_reason.options.0.badge_visible', true)
            ->assertJsonPath('visibility_filters.definition.fields.archived.type', 'boolean')
            ->assertJsonPath('visibility_filters.definition.fields.archived.input', 'boolean_select')
            ->assertJsonPath('visibility_filters.definition.labels.label', 'Labels')
            ->assertJsonPath('visibility_filters.definition.labels.input', 'key_value_textarea')
            ->assertJsonPath('visibility_filters.definition.labels.operator', 'exact');
    }

    public function testCompletedListRoutesCanFilterByArchivedTerminalFlags(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $archived = $this->createTerminalSummary(
            'terminal-archived',
            'run-terminal-archived',
            'completed',
            archivedAt: Carbon::parse('2022-01-01 12:06:00'),
        );
        $this->createTerminalSummary(
            'terminal-open',
            'run-terminal-open',
            'completed',
        );

        $this->get('/waterline/api/flows/completed?instance_id='.$archived->workflow_instance_id.'&archived=true&is_terminal=true&closed_reason=completed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $archived->id)
            ->assertJsonPath('visibility_filters.applied.archived', true)
            ->assertJsonPath('visibility_filters.applied.is_terminal', true)
            ->assertJsonPath('visibility_filters.applied.closed_reason', 'completed');
    }

    public function testV2SavedViewsCanBeUpdatedAndDeleted(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.saved_views.scope', 'ops');

        $id = $this->postJson('/waterline/api/saved-views', [
            'name' => 'Acme waits',
            'bucket' => 'running',
            'filters' => [
            'workflow_type' => 'workflow.test',
            'labels' => [
                'tenant' => 'acme',
            ],
        ],
            'shared' => true,
        ])->assertCreated()->json('id');

        $this->putJson('/waterline/api/saved-views/'.$id, [
            'name' => 'Acme signal waits',
            'bucket' => 'running',
            'filters' => [
                'instance_id' => 'order-123',
                'wait_kind' => 'signal',
                'repair_blocked_reason' => 'unsupported_history',
                'archived' => false,
            ],
            'shared' => false,
        ])->assertOk()
            ->assertJsonPath('name', 'Acme signal waits')
            ->assertJsonPath('shared', false)
            ->assertJsonPath('filters.instance_id', 'order-123')
            ->assertJsonPath('filters.wait_kind', 'signal')
            ->assertJsonPath('filters.repair_blocked_reason', 'unsupported_history')
            ->assertJsonPath('filters.archived', false);

        $this->get('/waterline/api/saved-views/'.$id)
            ->assertOk()
            ->assertJsonPath('name', 'Acme signal waits')
            ->assertJsonPath('filters.wait_kind', 'signal')
            ->assertJsonPath('filters.repair_blocked_reason', 'unsupported_history')
            ->assertJsonPath('filters.archived', false);

        $this->get('/waterline/api/saved-views?bucket=running')
            ->assertOk()
            ->assertJsonPath('filter_version', VisibilityFilters::VERSION)
            ->assertJsonPath('filter_definition.fields.instance_id.label', 'Instance ID')
            ->assertJsonPath('filter_definition.fields.instance_id.type', 'string')
            ->assertJsonPath('filter_definition.fields.repair_blocked_reason.label', 'Repair Blocked Reason')
            ->assertJsonPath('filter_definition.fields.repair_blocked_reason.input', 'select')
            ->assertJsonPath(
                'filter_definition.fields.repair_blocked_reason.options.0.description',
                'Repair is blocked because only unsupported diagnostic history remains.',
            )
            ->assertJsonPath('filter_definition.fields.repair_blocked_reason.options.0.tone', 'dark')
            ->assertJsonPath('filter_definition.fields.repair_blocked_reason.options.0.badge_visible', true)
            ->assertJsonPath('filter_definition.fields.is_current_run.type', 'boolean')
            ->assertJsonPath('filter_definition.fields.continue_as_new_recommended.type', 'boolean')
            ->assertJsonPath('filter_definition.fields.archived.type', 'boolean')
            ->assertJsonPath('filter_definition.fields.archived.input', 'boolean_select')
            ->assertJsonPath('filter_definition.labels.input', 'key_value_textarea');

        $this->delete('/waterline/api/saved-views/'.$id)
            ->assertNoContent();

        $this->assertDatabaseMissing('waterline_saved_views', [
            'id' => $id,
        ]);
    }

    public function testSavedViewsIndexStillEchoesFilterDefinitionWhenDisabled(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.saved_views.enabled', false);

        $this->get('/waterline/api/saved-views?bucket=running')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('filter_version', VisibilityFilters::VERSION)
            ->assertJsonPath('filter_definition.fields.instance_id.label', 'Instance ID')
            ->assertJsonPath('filter_definition.fields.archived.input', 'boolean_select')
            ->assertJsonPath('filter_definition.labels.help', 'One exact-match label per line in key=value format.');
    }

    private function createRunningSummary(
        string $instanceId,
        string $runId,
        Carbon $startedAt,
        Carbon $createdAt,
        ?string $businessKey = null,
        array $visibilityLabels = [],
        ?string $waitKind = null,
        ?string $livenessState = null,
        ?string $repairBlockedReason = null,
        ?Carbon $archivedAt = null,
        bool $isCurrentRun = true,
        bool $continueAsNewRecommended = false,
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
            'archived_at' => $archivedAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $instance->update(['current_run_id' => $isCurrentRun ? $run->id : null]);

        return WorkflowRunSummary::create([
            'id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'is_current_run' => $isCurrentRun,
            'engine_source' => 'v2',
            'class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'business_key' => $businessKey,
            'visibility_labels' => $visibilityLabels === [] ? null : $visibilityLabels,
            'status' => 'waiting',
            'status_bucket' => 'running',
            'wait_kind' => $waitKind,
            'liveness_state' => $livenessState,
            'repair_blocked_reason' => $repairBlockedReason,
            'continue_as_new_recommended' => $continueAsNewRecommended,
            'archived_at' => $archivedAt,
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
        ?Carbon $archivedAt = null,
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
            'archived_at' => $archivedAt,
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
            'archived_at' => $archivedAt,
            'started_at' => $startedAt,
            'closed_at' => $closedAt,
            'duration_ms' => $closedAt->diffInMilliseconds($startedAt),
            'sort_timestamp' => $startedAt,
            'sort_key' => RunSummarySortKey::key($startedAt, $startedAt, $closedAt, $run->id),
            'created_at' => $startedAt,
            'updated_at' => $closedAt,
        ]);
    }

    private function createConfiguredSummaryTable(): void
    {
        Schema::create('waterline_configured_list_run_summaries', static function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('workflow_instance_id', 191)->index();
            $table->unsignedInteger('run_number');
            $table->boolean('is_current_run')->default(false);
            $table->string('engine_source')->nullable();
            $table->string('class');
            $table->string('workflow_type');
            $table->string('business_key')->nullable();
            $table->string('status');
            $table->string('status_bucket')->nullable();
            $table->timestamp('started_at', 6)->nullable();
            $table->timestamp('sort_timestamp', 6)->nullable();
            $table->string('sort_key')->nullable();
            $table->string('config_marker')->nullable();
            $table->timestamps(6);
        });
    }
}

final class ConfiguredWaterlineListRunSummary extends WorkflowRunSummary
{
    protected $table = 'waterline_configured_list_run_summaries';
}
