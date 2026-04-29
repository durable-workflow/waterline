<?php

namespace Waterline\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Waterline\Models\SavedWorkflowView;
use Waterline\Tests\TestCase;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Support\RunListItemView;
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
            'search_attributes' => [
                'customer_tier' => 'gold',
            ],
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
            ->assertJsonPath('data.0.workflow_type', 'workflow.test')
            ->assertJsonPath('data.0.search_attributes.customer_tier', 'gold')
            ->assertJsonPath('data.0.actionability.schema', 'waterline.actionability')
            ->assertJsonPath('data.0.actionability.repair_state', 'unknown')
            ->assertJsonPath('visibility_filters.actionability_contract.version', 1)
            ->assertJsonMissingPath('data.0.config_marker');
    }

    public function testRunningFlowsPreserveLegacySearchAttributesFromRunWhenDefaultSummarySchemaOmitsThem(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $this->ensureLegacyVisibilityColumnsPresent();

        $startedAt = Carbon::parse('2022-01-01 12:05:00');
        $createdAt = Carbon::parse('2022-01-01 12:00:00');

        $instance = WorkflowInstance::create([
            'id' => 'default-summary-instance',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'business_key' => 'default-summary-business',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => 'default-summary-run',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'business_key' => 'default-summary-business',
            'search_attributes' => [
                'customer_tier' => 'gold',
            ],
            'status' => RunStatus::Waiting->value,
            'started_at' => $startedAt,
            'last_progress_at' => $startedAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $instance->update(['current_run_id' => $run->id]);

        WorkflowRunSummary::create([
            'id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'business_key' => 'default-summary-business',
            'status' => RunStatus::Waiting->value,
            'status_bucket' => 'running',
            'started_at' => $startedAt,
            'sort_timestamp' => $startedAt,
            'sort_key' => RunSummarySortKey::key($startedAt, $createdAt, $createdAt, $run->id),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $this->get('/waterline/api/flows/running')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $run->id)
            ->assertJsonPath('data.0.search_attributes.customer_tier', 'gold');
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
            ->assertJsonPath('data.0.sort_timestamp', $newest->sort_timestamp?->format('Y-m-d\TH:i:sP'))
            ->assertJsonPath('data.0.instance_id', $newest->workflow_instance_id)
            ->assertJsonPath('data.0.selected_run_id', $newest->id)
            ->assertJsonPath('data.0.run_id', $newest->id);
    }

    public function testRunningFlowsCanUseAscendingV2SortDirection(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.workflow_sort_column', 'created_at');

        $newestStartedAt = Carbon::parse('2022-01-01 12:05:00');
        $newestCreatedAt = Carbon::parse('2022-01-01 11:00:00');
        $oldestStartedAt = Carbon::parse('2022-01-01 12:01:00');
        $oldestCreatedAt = Carbon::parse('2022-01-01 13:00:00');

        $newest = WorkflowRunSummary::create([
            'id' => 'run-ascending-newest',
            'workflow_instance_id' => 'ascending-newest',
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'status_bucket' => 'running',
            'started_at' => $newestStartedAt,
            'sort_timestamp' => $newestStartedAt,
            'sort_key' => RunSummarySortKey::key($newestStartedAt, $newestCreatedAt, $newestCreatedAt, 'run-ascending-newest'),
            'created_at' => $newestCreatedAt,
            'updated_at' => $newestCreatedAt,
        ]);
        $oldest = WorkflowRunSummary::create([
            'id' => 'run-ascending-oldest',
            'workflow_instance_id' => 'ascending-oldest',
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'status_bucket' => 'running',
            'started_at' => $oldestStartedAt,
            'sort_timestamp' => $oldestStartedAt,
            'sort_key' => RunSummarySortKey::key($oldestStartedAt, $oldestCreatedAt, $oldestCreatedAt, 'run-ascending-oldest'),
            'created_at' => $oldestCreatedAt,
            'updated_at' => $oldestCreatedAt,
        ]);

        $this->get('/waterline/api/flows/running?sort_direction=asc')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $oldest->id)
            ->assertJsonPath('data.1.id', $newest->id);
    }

    public function testRunningFlowsBreakSortTimestampTiesByRunIdNotCreatedAt(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.workflow_sort_column', 'created_at');

        $sortTimestamp = Carbon::parse('2022-01-01 12:05:00');

        $olderRunId = '01JTESTSORTKEY000000000001';
        $newerRunId = '01JTESTSORTKEY000000000002';

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
            ->assertJsonPath('data.0.is_terminal', true)
            ->assertJsonPath('data.0.detail_action.label', 'Run Detail')
            ->assertJsonPath('data.0.detail_action.available', true)
            ->assertJsonPath('data.0.detail_action.history_available', false)
            ->assertJsonPath('data.0.detail_action.unavailable_label', 'No typed history');

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
            ->assertJsonPath('owner_type', 'scope')
            ->assertJsonPath('owner_id', 'ops')
            ->assertJsonPath('owned_by_current_operator', true)
            ->assertJsonPath('mutable_by_current_operator', true)
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
            ->assertJsonPath('data.1.id', 'system:running-task-problems')
            ->assertJsonPath('data.2.id', 'system:running-repair-blocked')
            ->assertJsonPath('data.3.id', $id)
            ->assertJsonPath('data.3.filters.labels.tenant', 'acme');

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
            repairAttention: true,
            continueAsNewRecommended: true,
            declaredEntryMode: 'compatibility',
            declaredContractSource: 'live_definition',
            declaredContractBackfillNeeded: true,
            declaredContractBackfillAvailable: true,
            taskProblem: true,
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
            repairAttention: false,
            isCurrentRun: false,
            continueAsNewRecommended: false,
            declaredEntryMode: 'canonical',
            declaredContractSource: 'durable_history',
            declaredContractBackfillNeeded: false,
            declaredContractBackfillAvailable: false,
            taskProblem: false,
        );

        $savedViewId = $this->postJson('/waterline/api/saved-views', [
            'name' => 'Signal waits',
            'bucket' => 'running',
            'filters' => [
                'workflow_type' => 'workflow.test',
                'wait_kind' => 'signal',
                'repair_state' => 'blocked',
                'task_problem' => true,
                'continue_as_new_recommended' => true,
                'declared_entry_mode' => 'compatibility',
                'declared_contract_source' => 'live_definition',
                'archived' => false,
                'is_terminal' => false,
            ],
            'shared' => true,
        ])->assertCreated()->json('id');

        $this->get('/waterline/api/flows/running?view='.$savedViewId.'&instance_id='.$matching->workflow_instance_id.'&run_id='.$matching->id.'&is_current_run=true&liveness_state=waiting_for_signal&repair_state=blocked&task_problem=true&declared_entry_mode=compatibility&declared_contract_source=live_definition')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id)
            ->assertJsonPath('data.0.repair_blocked_reason', 'unsupported_history')
            ->assertJsonPath('data.0.repair_attention', true)
            ->assertJsonPath('data.0.repair_blocked.code', 'unsupported_history')
            ->assertJsonPath('data.0.repair_blocked.label', 'Replay Blocked')
            ->assertJsonPath(
                'data.0.repair_blocked.description',
                'Repair is blocked because only unsupported diagnostic history remains.',
            )
            ->assertJsonPath('data.0.repair_blocked.tone', 'dark')
            ->assertJsonPath('data.0.repair_blocked.badge_visible', true)
            ->assertJsonPath('data.0.declared_entry_mode', 'compatibility')
            ->assertJsonPath('data.0.declared_contract_source', 'live_definition')
            ->assertJsonPath('visibility_filters.version', VisibilityFilters::VERSION)
            ->assertJsonPath('visibility_filters.bucket', 'running')
            ->assertJsonPath('visibility_filters.saved_view.id', $savedViewId)
            ->assertJsonPath('visibility_filters.applied.workflow_type', 'workflow.test')
            ->assertJsonPath('visibility_filters.applied.wait_kind', 'signal')
            ->assertJsonPath('visibility_filters.applied.liveness_state', 'waiting_for_signal')
            ->assertJsonPath('visibility_filters.applied.repair_state', 'blocked')
            ->assertJsonPath('visibility_filters.applied.task_problem', true)
            ->assertJsonPath('visibility_filters.applied.is_current_run', true)
            ->assertJsonPath('visibility_filters.applied.continue_as_new_recommended', true)
            ->assertJsonPath('visibility_filters.applied.declared_entry_mode', 'compatibility')
            ->assertJsonPath('visibility_filters.applied.declared_contract_source', 'live_definition')
            ->assertJsonPath('visibility_filters.applied.instance_id', $matching->workflow_instance_id)
            ->assertJsonPath('visibility_filters.applied.run_id', $matching->id)
            ->assertJsonPath('visibility_filters.applied.archived', false)
            ->assertJsonPath('visibility_filters.applied.is_terminal', false)
            ->assertJsonPath('visibility_filters.definition.fields.instance_id.label', 'Instance ID')
            ->assertJsonPath('visibility_filters.definition.fields.instance_id.type', 'string')
            ->assertJsonPath('visibility_filters.definition.fields.instance_id.input', 'text')
            ->assertJsonPath('visibility_filters.definition.fields.instance_id_contains.operator', 'contains')
            ->assertJsonPath('visibility_filters.definition.fields.instance_id_contains.contains_field', 'instance_id')
            ->assertJsonPath('visibility_filters.definition.fields.workflow_type_contains.operator', 'contains')
            ->assertJsonPath('visibility_filters.definition.fields.business_key.help', 'Exact-match indexed operator metadata copied onto the run summary and saved-view contract.')
            ->assertJsonPath('visibility_filters.definition.fields.status_bucket.input', 'select')
            ->assertJsonPath('visibility_filters.definition.fields.is_current_run.type', 'boolean')
            ->assertJsonPath('visibility_filters.definition.fields.is_current_run.input', 'boolean_select')
            ->assertJsonPath('visibility_filters.definition.fields.continue_as_new_recommended.type', 'boolean')
            ->assertJsonPath('visibility_filters.definition.fields.continue_as_new_recommended.input', 'boolean_select')
            ->assertJsonPath('visibility_filters.definition.fields.task_problem.label', 'Task Problem')
            ->assertJsonPath('visibility_filters.definition.fields.task_problem.type', 'boolean')
            ->assertJsonPath('visibility_filters.definition.fields.task_problem.input', 'boolean_select')
            ->assertJsonPath('visibility_filters.definition.fields.declared_entry_mode.label', 'Entry Contract')
            ->assertJsonPath('visibility_filters.definition.fields.declared_entry_mode.input', 'select')
            ->assertJsonPath(
                'visibility_filters.definition.fields.declared_contract_source.label',
                'Command Contract Source',
            )
            ->assertJsonPath('visibility_filters.definition.fields.declared_contract_source.input', 'select')
            ->assertJsonPath('visibility_filters.definition.fields.repair_blocked_reason.label', 'Repair Blocked Reason')
            ->assertJsonPath('visibility_filters.definition.fields.repair_blocked_reason.type', 'string')
            ->assertJsonPath('visibility_filters.definition.fields.repair_blocked_reason.input', 'select')
            ->assertJsonPath(
                'visibility_filters.definition.fields.repair_blocked_reason.options.0.description',
                'Repair is blocked because only unsupported diagnostic history remains.',
            )
            ->assertJsonPath('visibility_filters.definition.fields.repair_blocked_reason.options.0.tone', 'dark')
            ->assertJsonPath('visibility_filters.definition.fields.repair_blocked_reason.options.0.badge_visible', true)
            ->assertJsonPath('visibility_filters.definition.fields.repair_state.label', 'Repair State')
            ->assertJsonPath('visibility_filters.definition.fields.repair_state.input', 'select')
            ->assertJsonPath('visibility_filters.definition.fields.repair_state.derived_from', 'waterline.actionability')
            ->assertJsonPath('visibility_filters.definition.fields.repair_state.options.1.value', 'blocked')
            ->assertJsonPath('visibility_filters.definition.fields.repair_attention.derived_from', 'waterline.actionability')
            ->assertJsonPath('visibility_filters.definition.fields.task_problem.derived_from', 'waterline.actionability')
            ->assertJsonPath('visibility_filters.definition.actionability.schema', 'waterline.actionability')
            ->assertJsonPath('visibility_filters.definition.actionability.filter_fields.0', 'repair_state')
            ->assertJsonPath('visibility_filters.definition.fields.repair_attention.label', 'Repair Attention')
            ->assertJsonPath('visibility_filters.definition.fields.repair_attention.type', 'boolean')
            ->assertJsonPath('visibility_filters.definition.fields.repair_attention.input', 'boolean_select')
            ->assertJsonPath('visibility_filters.definition.fields.archived.type', 'boolean')
            ->assertJsonPath('visibility_filters.definition.fields.archived.input', 'boolean_select')
            ->assertJsonPath('visibility_filters.definition.labels.label', 'Labels')
            ->assertJsonPath('visibility_filters.definition.labels.input', 'key_value_textarea')
            ->assertJsonPath('visibility_filters.definition.labels.operator', 'exact')
            ->assertJsonPath(
                'visibility_filters.definition.labels.help',
                'One exact-match label per line in key=value format. Labels are indexed operator metadata set at start and saved-view compatible.',
            )
            ->assertJsonPath('visibility_filters.definition.indexed_metadata.business_key.filter_field', 'business_key')
            ->assertJsonPath('visibility_filters.definition.indexed_metadata.business_key.saved_view_compatible', true)
            ->assertJsonPath('visibility_filters.definition.indexed_metadata.labels.indexed', true)
            ->assertJsonPath('visibility_filters.definition.indexed_metadata.labels.filterable', true)
            ->assertJsonPath('visibility_filters.definition.detail_metadata.memo.filterable', false)
            ->assertJsonPath('visibility_filters.definition.detail_metadata.memo.saved_view_compatible', false);
    }

    public function testV2ListRoutesMarkUnsupportedSavedViewVersionsWithoutApplyingTheirFilters(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.saved_views.scope', 'ops');

        $signal = $this->createRunningSummary(
            'unsupported-view-signal',
            'run-unsup-view-signal-01',
            Carbon::parse('2022-01-01 12:05:00'),
            Carbon::parse('2022-01-01 12:05:00'),
            waitKind: 'signal',
        );
        $timer = $this->createRunningSummary(
            'unsupported-view-timer',
            'run-unsup-view-timer-01',
            Carbon::parse('2022-01-01 12:06:00'),
            Carbon::parse('2022-01-01 12:06:00'),
            waitKind: 'timer',
        );

        $savedView = SavedWorkflowView::create([
            'name' => 'Signal waits (old contract)',
            'scope' => 'ops',
            'bucket' => 'running',
            'filters' => [
                'wait_kind' => 'signal',
            ],
            'filter_version' => 99,
            'shared' => true,
        ]);

        $this->get('/waterline/api/flows/running?view='.$savedView->id.'&instance_id='.$timer->workflow_instance_id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $timer->id)
            ->assertJsonPath('visibility_filters.version', VisibilityFilters::VERSION)
            ->assertJsonPath('visibility_filters.supported_versions', VisibilityFilters::supportedVersions())
            ->assertJsonPath('visibility_filters.saved_view.id', $savedView->id)
            ->assertJsonPath('visibility_filters.saved_view.filter_version', 99)
            ->assertJsonPath('visibility_filters.saved_view.filter_version_supported', false)
            ->assertJsonPath('visibility_filters.saved_view.filter_version_status', 'unsupported')
            ->assertJsonPath(
                'visibility_filters.saved_view.filter_version_message',
                'This saved view uses visibility filter version 99, but this Waterline build supports version 6.',
            )
            ->assertJsonPath('visibility_filters.saved_view_applied', false)
            ->assertJsonPath(
                'visibility_filters.saved_view_warning',
                'This saved view uses visibility filter version 99, but this Waterline build supports version 6.',
            )
            ->assertJsonPath('visibility_filters.applied.instance_id', $timer->workflow_instance_id)
            ->assertJsonMissingPath('visibility_filters.applied.wait_kind');

        $this->assertNotSame($signal->id, $timer->id);
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

    public function testV2RunningSystemTaskProblemsViewFiltersFlaggedRuns(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $flagged = $this->createRunningSummary(
            'task-problem-instance',
            'run-task-problem-instance',
            Carbon::parse('2022-01-01 12:05:00'),
            Carbon::parse('2022-01-01 12:05:00'),
            taskProblem: true,
        );
        $this->createRunningSummary(
            'healthy-instance',
            'run-healthy-instance',
            Carbon::parse('2022-01-01 12:06:00'),
            Carbon::parse('2022-01-01 12:06:00'),
            taskProblem: false,
        );

        $this->get('/waterline/api/flows/running?view=system:running-task-problems')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $flagged->id)
            ->assertJsonPath('data.0.task_problem', true)
            ->assertJsonPath('visibility_filters.saved_view.id', 'system:running-task-problems')
            ->assertJsonPath('visibility_filters.saved_view.system', true)
            ->assertJsonPath('visibility_filters.saved_view_applied', true)
            ->assertJsonPath('visibility_filters.applied.task_problem', true);
    }

    public function testV2RunningSystemRepairBlockedViewFiltersActionableRepairStates(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $flagged = $this->createRunningSummary(
            'repair-blocked-instance',
            'run-repair-blocked',
            Carbon::parse('2022-01-01 12:05:00'),
            Carbon::parse('2022-01-01 12:05:00'),
            repairBlockedReason: 'unsupported_history',
            repairAttention: true,
        );
        $this->createRunningSummary(
            'repair-not-needed-instance',
            'run-repair-not-needed',
            Carbon::parse('2022-01-01 12:06:00'),
            Carbon::parse('2022-01-01 12:06:00'),
            repairBlockedReason: 'repair_not_needed',
            repairAttention: false,
        );

        $this->get('/waterline/api/flows/running?view=system:running-repair-blocked')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $flagged->id)
            ->assertJsonPath('data.0.repair_attention', true)
            ->assertJsonPath('data.0.repair_blocked_reason', 'unsupported_history')
            ->assertJsonPath('visibility_filters.saved_view.id', 'system:running-repair-blocked')
            ->assertJsonPath('visibility_filters.saved_view.system', true)
            ->assertJsonPath('visibility_filters.saved_view_applied', true)
            ->assertJsonPath('visibility_filters.saved_view.filters.repair_state', 'blocked')
            ->assertJsonPath('visibility_filters.applied.repair_state', 'blocked');
    }

    public function testV2RunningRepairStateFilterCanSelectUnknownActionability(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $unknown = $this->createRunningSummary(
            'unknown-repair-instance',
            'run-unknown-repair',
            Carbon::parse('2022-01-01 12:05:00'),
            Carbon::parse('2022-01-01 12:05:00'),
        );
        $repairable = $this->createRunningSummary(
            'repairable-repair-instance',
            'run-repairable-repair',
            Carbon::parse('2022-01-01 12:07:00'),
            Carbon::parse('2022-01-01 12:07:00'),
            repairAttention: true,
        );
        $this->createRunningSummary(
            'blocked-repair-instance',
            'run-blocked-repair',
            Carbon::parse('2022-01-01 12:06:00'),
            Carbon::parse('2022-01-01 12:06:00'),
            repairBlockedReason: 'unsupported_history',
            repairAttention: true,
        );

        $this->get('/waterline/api/flows/running?repair_state=unknown')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $unknown->id)
            ->assertJsonPath('data.0.actionability.repair_state', 'unknown')
            ->assertJsonPath('visibility_filters.applied.repair_state', 'unknown');

        $this->get('/waterline/api/flows/running?repair_state=repairable')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $repairable->id)
            ->assertJsonPath('data.0.actionability.repair_state', 'repairable')
            ->assertJsonPath('visibility_filters.applied.repair_state', 'repairable');
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
                'repair_state' => 'blocked',
                'archived' => false,
            ],
            'shared' => false,
        ])->assertOk()
            ->assertJsonPath('name', 'Acme signal waits')
            ->assertJsonPath('shared', false)
            ->assertJsonPath('filters.instance_id', 'order-123')
            ->assertJsonPath('filters.wait_kind', 'signal')
            ->assertJsonPath('filters.repair_state', 'blocked')
            ->assertJsonPath('filters.archived', false);

        $this->get('/waterline/api/saved-views/'.$id)
            ->assertOk()
            ->assertJsonPath('name', 'Acme signal waits')
            ->assertJsonPath('filters.wait_kind', 'signal')
            ->assertJsonPath('filters.repair_state', 'blocked')
            ->assertJsonPath('filters.archived', false);

        $this->get('/waterline/api/saved-views?bucket=running')
            ->assertOk()
            ->assertJsonPath('filter_version', VisibilityFilters::VERSION)
            ->assertJsonPath('saved_view_policy.mutation', 'Only the saved-view owner can update or delete a custom view, including shared views.')
            ->assertJsonPath('filter_definition.fields.instance_id.label', 'Instance ID')
            ->assertJsonPath('filter_definition.fields.instance_id.type', 'string')
            ->assertJsonPath('filter_definition.fields.instance_id_contains.operator', 'contains')
            ->assertJsonPath('filter_definition.fields.repair_blocked_reason.label', 'Repair Blocked Reason')
            ->assertJsonPath('filter_definition.fields.repair_blocked_reason.input', 'select')
            ->assertJsonPath('filter_definition.fields.repair_state.label', 'Repair State')
            ->assertJsonPath('filter_definition.fields.repair_state.derived_from', 'waterline.actionability')
            ->assertJsonPath('filter_definition.fields.repair_attention.label', 'Repair Attention')
            ->assertJsonPath('filter_definition.fields.repair_attention.type', 'boolean')
            ->assertJsonPath('filter_definition.fields.repair_attention.input', 'boolean_select')
            ->assertJsonPath('filter_definition.fields.task_problem.label', 'Task Problem')
            ->assertJsonPath('filter_definition.fields.task_problem.type', 'boolean')
            ->assertJsonPath('filter_definition.fields.task_problem.input', 'boolean_select')
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
            ->assertJsonPath('filter_definition.labels.input', 'key_value_textarea')
            ->assertJsonPath('filter_definition.indexed_metadata.business_key.indexed', true)
            ->assertJsonPath('filter_definition.detail_metadata.memo.filterable', false);

        $this->delete('/waterline/api/saved-views/'.$id)
            ->assertNoContent();

        $this->assertDatabaseMissing('waterline_saved_views', [
            'id' => $id,
        ]);
    }

    public function testSavedViewsSurfaceVersionMetadataAndUpdatesRewriteToCurrentVersion(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.saved_views.scope', 'ops');

        $view = SavedWorkflowView::create([
            'name' => 'Legacy signal waits',
            'scope' => 'ops',
            'bucket' => 'running',
            'filters' => [
                'wait_kind' => 'signal',
            ],
            'filter_version' => 99,
            'shared' => true,
        ]);

        $this->get('/waterline/api/saved-views/'.$view->id)
            ->assertOk()
            ->assertJsonPath('id', $view->id)
            ->assertJsonPath('filter_version', 99)
            ->assertJsonPath('filter_version_supported', false)
            ->assertJsonPath('filter_version_status', 'unsupported')
            ->assertJsonPath(
                'filter_version_message',
                'This saved view uses visibility filter version 99, but this Waterline build supports version 6.',
            )
            ->assertJsonPath('current_filter_version', VisibilityFilters::VERSION)
            ->assertJsonPath('supported_filter_versions', VisibilityFilters::supportedVersions());

        $this->putJson('/waterline/api/saved-views/'.$view->id, [
            'name' => 'Current signal waits',
            'bucket' => 'running',
            'filters' => [
                'wait_kind' => 'signal',
                'archived' => false,
            ],
            'shared' => false,
        ])->assertOk()
            ->assertJsonPath('name', 'Current signal waits')
            ->assertJsonPath('filter_version', VisibilityFilters::VERSION)
            ->assertJsonPath('filter_version_supported', true)
            ->assertJsonPath('filter_version_status', 'supported')
            ->assertJsonPath('filter_version_message', null)
            ->assertJsonPath('current_filter_version', VisibilityFilters::VERSION)
            ->assertJsonPath('supported_filter_versions', VisibilityFilters::supportedVersions());

        $this->assertDatabaseHas('waterline_saved_views', [
            'id' => $view->id,
            'filter_version' => VisibilityFilters::VERSION,
            'name' => 'Current signal waits',
        ]);
    }

    public function testSavedViewsAreReadableWhenSharedButMutableOnlyByOwner(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.saved_views.scope', 'ops');

        $owner = new SavedViewTestUser('owner');
        $other = new SavedViewTestUser('other');

        $this->actingAs($owner);

        $private = $this->postJson('/waterline/api/saved-views', [
            'name' => 'Owner private waits',
            'bucket' => 'running',
            'filters' => [
                'wait_kind' => 'signal',
            ],
            'shared' => false,
        ])
            ->assertCreated()
            ->assertJsonPath('owner_type', SavedViewTestUser::class)
            ->assertJsonPath('owner_id', 'owner')
            ->assertJsonPath('owned_by_current_operator', true)
            ->json('id');

        $shared = $this->postJson('/waterline/api/saved-views', [
            'name' => 'Owner shared waits',
            'bucket' => 'running',
            'filters' => [
                'wait_kind' => 'timer',
            ],
            'shared' => true,
        ])->assertCreated()->json('id');

        $this->actingAs($other);

        $this->get('/waterline/api/saved-views/'.$private)
            ->assertNotFound();

        $this->get('/waterline/api/saved-views?bucket=running')
            ->assertOk()
            ->assertJsonMissing(['id' => $private])
            ->assertJsonFragment([
                'id' => $shared,
                'owned_by_current_operator' => false,
                'mutable_by_current_operator' => false,
            ]);

        $this->get('/waterline/api/saved-views/'.$shared)
            ->assertOk()
            ->assertJsonPath('id', $shared)
            ->assertJsonPath('owned_by_current_operator', false)
            ->assertJsonPath('mutable_by_current_operator', false);

        $this->putJson('/waterline/api/saved-views/'.$shared, [
            'name' => 'Other update',
            'bucket' => 'running',
            'filters' => [],
            'shared' => true,
        ])->assertNotFound();

        $this->delete('/waterline/api/saved-views/'.$shared)
            ->assertNotFound();
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
            ->assertJsonPath('filter_definition.fields.repair_attention.input', 'boolean_select')
            ->assertJsonPath('filter_definition.fields.task_problem.input', 'boolean_select')
            ->assertJsonPath('filter_definition.fields.archived.input', 'boolean_select')
            ->assertJsonPath(
                'filter_definition.labels.help',
                'One exact-match label per line in key=value format. Labels are indexed operator metadata set at start and saved-view compatible.',
            )
            ->assertJsonPath('filter_definition.indexed_metadata.labels.saved_view_compatible', true)
            ->assertJsonPath('filter_definition.detail_metadata.memo.saved_view_compatible', false);
    }

    public function testV2ListResponseItemsMatchTypedListItemContract(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.run_diagnostics.history_budget_warning_ratio', 0.8);
        config()->set('workflows.v2.history_budget.continue_as_new_event_threshold', 10);
        config()->set('workflows.v2.history_budget.continue_as_new_size_bytes_threshold', 1000);

        $summary = $this->createRunningSummary(
            'contract-shape-instance',
            'run-contract-shape',
            Carbon::parse('2022-01-01 12:05:00'),
            Carbon::parse('2022-01-01 12:00:00'),
            businessKey: 'contract-test',
            visibilityLabels: ['tenant' => 'acme'],
            waitKind: 'signal',
            livenessState: 'waiting_for_signal',
            repairBlockedReason: 'unsupported_history',
            repairAttention: true,
            taskProblem: true,
            declaredEntryMode: 'compatibility',
            declaredContractSource: 'live_definition',
            declaredContractBackfillNeeded: true,
            declaredContractBackfillAvailable: true,
            historyEventCount: 8,
            historySizeBytes: 500,
        );

        $response = $this->get('/waterline/api/flows/running');
        $response->assertOk()->assertJsonCount(1, 'data');

        $item = $response->json('data.0');
        $expectedFields = RunListItemView::fields();
        $expectedFields[] = 'history_budget_indicator';
        $expectedFields[] = 'compatibility_supported';
        $expectedFields[] = 'compatibility_reason';
        $expectedFields[] = 'compatibility_supported_in_fleet';
        $expectedFields[] = 'compatibility_fleet_reason';
        $expectedFields[] = 'compatibility_namespace';
        $expectedFields[] = 'compatibility_semantics';
        $expectedFields[] = 'actionability';
        $expectedFields[] = 'detail_action';

        $this->assertSame(
            $expectedFields,
            array_keys($item),
            'List item keys must exactly match RunListItemView::fields()',
        );

        // Verify typed projections
        $this->assertSame($summary->id, $item['id']);
        $this->assertSame($summary->workflow_instance_id, $item['instance_id']);
        $this->assertSame('signal', $item['wait_kind']);
        $this->assertSame('waiting_for_signal', $item['liveness_state']);
        $this->assertSame('unsupported_history', $item['repair_blocked_reason']);
        $this->assertTrue($item['repair_attention']);
        $this->assertIsArray($item['repair_blocked']);
        $this->assertSame('unsupported_history', $item['repair_blocked']['code']);
        $this->assertSame('blocked', $item['actionability']['repair_state']);
        $this->assertFalse($item['actionability']['actions']['repair']['allowed']);
        $this->assertSame('unsupported_history', $item['actionability']['actions']['repair']['reason']);
        $this->assertSame('repair_state', $item['actionability']['actions']['repair']['derived_from']);
        $this->assertSame('unsupported_history', $item['actionability']['badges']['repair']['code']);
        $this->assertSame('Replay Blocked', $item['actionability']['badges']['repair']['label']);
        $this->assertSame('dark', $item['actionability']['badges']['repair']['tone']);
        $this->assertTrue($item['actionability']['badges']['repair']['badge_visible']);
        $this->assertSame('repair_state', $item['actionability']['badges']['repair']['derived_from']);
        $this->assertTrue($item['task_problem']);
        $this->assertIsArray($item['task_problem_badge']);
        $this->assertSame('task_problem', $item['actionability']['badges']['task_problem']['code']);
        $this->assertSame('Task Problem', $item['actionability']['badges']['task_problem']['label']);
        $this->assertTrue($item['actionability']['badges']['task_problem']['badge_visible']);
        $this->assertSame('waterline.actionability', $item['actionability']['badges']['task_problem']['derived_from']);
        $this->assertSame('compatibility', $item['declared_entry_mode']);
        $this->assertSame('live_definition', $item['declared_contract_source']);
        $this->assertSame('no_required_marker', $item['compatibility_semantics']['state']);
        $this->assertNull($item['compatibility_semantics']['required_marker']);
        $this->assertTrue($item['compatibility_semantics']['claimable_by_this_build']);
        $this->assertTrue($item['compatibility_semantics']['supported_in_active_fleet']);
        $this->assertSame('No compatibility marker is required for this row.', $item['compatibility_semantics']['operator_summary']);
        $this->assertSame(8, $item['history_event_count']);
        $this->assertSame('near_limit', $item['history_budget_indicator']['status']);
        $this->assertSame('History near limit', $item['history_budget_indicator']['label']);
        $this->assertSame('info', $item['history_budget_indicator']['tone']);
        $this->assertTrue($item['history_budget_indicator']['badge_visible']);
        $this->assertSame(0.8, $item['history_budget_indicator']['event_ratio']);
        $this->assertSame(0.5, $item['history_budget_indicator']['size_ratio']);
        $this->assertSame(10, $item['history_budget_indicator']['history_event_threshold']);
        $this->assertSame(1000, $item['history_budget_indicator']['history_size_bytes_threshold']);
        $this->assertSame(['tenant' => 'acme'], $item['visibility_labels']);
        $this->assertFalse($item['is_terminal']);
        $this->assertIsString($item['started_at']);
        $this->assertIsString($item['sort_timestamp']);
        $this->assertIsString($item['sort_key']);
    }

    public function testSavedViewsRemainAvailableWhenEngineSourceIsAuto(): void
    {
        config()->set('waterline.engine_source', 'auto');

        $this->get('/waterline/api/saved-views?bucket=running')
            ->assertOk()
            ->assertJsonPath('filter_version', VisibilityFilters::VERSION)
            ->assertJsonPath('filter_definition.fields.instance_id.label', 'Instance ID');
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
        bool $repairAttention = false,
        ?Carbon $archivedAt = null,
        bool $isCurrentRun = true,
        bool $continueAsNewRecommended = false,
        ?string $declaredEntryMode = null,
        ?string $declaredContractSource = null,
        bool $declaredContractBackfillNeeded = false,
        bool $declaredContractBackfillAvailable = false,
        bool $taskProblem = false,
        int $historyEventCount = 0,
        int $historySizeBytes = 0,
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
            'repair_attention' => $repairAttention,
            'task_problem' => $taskProblem,
            'history_event_count' => $historyEventCount,
            'history_size_bytes' => $historySizeBytes,
            'continue_as_new_recommended' => $continueAsNewRecommended,
            'declared_entry_mode' => $declaredEntryMode,
            'declared_contract_source' => $declaredContractSource,
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
            $table->string('workflow_instance_id', 191)->index('wl_cfg_list_run_summary_instance_idx');
            $table->unsignedInteger('run_number');
            $table->boolean('is_current_run')->default(false);
            $table->string('engine_source')->nullable();
            $table->string('class');
            $table->string('workflow_type');
            $table->string('business_key')->nullable();
            $table->json('search_attributes')->nullable();
            $table->string('declared_entry_mode')->nullable();
            $table->string('declared_contract_source')->nullable();
            $table->boolean('repair_attention')->default(false);
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

final class SavedViewTestUser implements Authenticatable
{
    public function __construct(private readonly string $id)
    {
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): string
    {
        return $this->id;
    }

    public function getAuthPassword()
    {
        return null;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getRememberToken()
    {
        return null;
    }

    public function setRememberToken($value): void
    {
    }

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
}
