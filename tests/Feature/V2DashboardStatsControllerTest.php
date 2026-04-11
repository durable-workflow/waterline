<?php

namespace Waterline\Tests\Feature;

use Carbon\CarbonInterval;
use Waterline\Tests\TestCase;
use Waterline\Tests\Fixtures\V2\TestCommandContractWorkflow;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunLineageEntry;
use Workflow\V2\Models\WorkflowRunWait;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimelineEntry;
use Workflow\V2\Support\WorkerCompatibilityFleet;

class V2DashboardStatsControllerTest extends TestCase
{
    public function testIndexUsesV2RunSummaries()
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => '01JTESTFLOWINSTANCE00000000',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUN000000000000',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'completed',
            'closed_reason' => 'completed',
            'started_at' => now()->subMinutes(10),
            'closed_at' => now()->subMinutes(5),
            'last_progress_at' => now()->subMinutes(5),
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
            'status' => 'completed',
            'status_bucket' => 'completed',
            'closed_reason' => 'completed',
            'started_at' => $run->started_at,
            'closed_at' => $run->closed_at,
            'duration_ms' => CarbonInterval::minutes(5)->totalMilliseconds,
            'exception_count' => 1,
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(5),
        ]);

        WorkflowFailure::create([
            'id' => '01JTESTFAILURE0000000000000',
            'workflow_run_id' => $run->id,
            'source_kind' => 'activity_execution',
            'source_id' => 'activity-1',
            'propagation_kind' => 'activity',
            'handled' => false,
            'exception_class' => \RuntimeException::class,
            'message' => 'boom',
            'file' => __FILE__,
            'line' => 42,
            'trace_preview' => 'trace',
        ]);

        $response = $this->get('/waterline/api/stats');

        $response
            ->assertStatus(200)
            ->assertJsonPath('flows', 1)
            ->assertJsonPath('flows_past_hour', 1)
            ->assertJsonPath('exceptions_past_hour', 1)
            ->assertJsonPath('failed_flows_past_week', 0)
            ->assertJsonPath('max_duration_workflow.id', $run->id)
            ->assertJsonPath('max_exceptions_workflow.exceptions_count', 1)
            ->assertJsonPath('max_exceptions_workflow.id', $run->id);
    }

    public function testIndexCountsOnlyActualFailuresForWeeklyFailedTotals(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => '01JTESTFLOWINSTANCEFAILED000',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNCANCELLED000',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'cancelled',
            'closed_reason' => 'cancelled',
            'started_at' => now()->subMinutes(20),
            'closed_at' => now()->subMinutes(10),
            'last_progress_at' => now()->subMinutes(10),
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
            'status' => 'cancelled',
            'status_bucket' => 'failed',
            'closed_reason' => 'cancelled',
            'started_at' => $run->started_at,
            'closed_at' => $run->closed_at,
            'duration_ms' => CarbonInterval::minutes(10)->totalMilliseconds,
            'exception_count' => 0,
            'created_at' => now()->subMinutes(20),
            'updated_at' => now()->subMinutes(10),
        ]);

        $this->get('/waterline/api/stats')
            ->assertStatus(200)
            ->assertJsonPath('failed_flows_past_week', 0);
    }

    public function testIndexIncludesV2OperatorMetrics(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()->set('workflows.v2.compatibility.supported', ['build-a']);
        config()->set('workflows.v2.compatibility.namespace', 'waterline-metrics-test');
        config()->set('workflows.v2.history_budget.continue_as_new_event_threshold', 10);
        config()->set('workflows.v2.history_budget.continue_as_new_size_bytes_threshold', 10000);
        config()->set('workflows.v2.update_wait.completion_timeout_seconds', 9);
        config()->set('workflows.v2.update_wait.poll_interval_milliseconds', 25);
        config()->set('workflows.v2.task_repair.redispatch_after_seconds', 8);
        config()->set('workflows.v2.task_repair.loop_throttle_seconds', 12);
        config()->set('workflows.v2.task_repair.scan_limit', 16);
        config()->set('workflows.v2.task_repair.failure_backoff_max_seconds', 32);
        WorkerCompatibilityFleet::clear();
        $this->beforeApplicationDestroyed(static function (): void {
            WorkerCompatibilityFleet::clear();
        });

        $instance = WorkflowInstance::create([
            'id' => '01JTESTFLOWINSTANCEMETRICS',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNMETRICS00000',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'pending',
            'started_at' => now()->subMinutes(10),
            'last_progress_at' => now()->subMinute(),
        ]);

        $instance->update(['current_run_id' => $run->id]);

        $missingSummaryInstance = WorkflowInstance::create([
            'id' => str_pad('01JTESTMISSINGINSTANCE', 26, '0'),
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $missingSummaryRun = WorkflowRun::create([
            'id' => str_pad('01JTESTMISSINGRUN', 26, '0'),
            'workflow_instance_id' => $missingSummaryInstance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'completed',
            'closed_reason' => 'completed',
            'started_at' => now()->subMinutes(20),
            'closed_at' => now()->subMinutes(15),
            'last_progress_at' => now()->subMinutes(15),
        ]);

        $missingSummaryInstance->update(['current_run_id' => $missingSummaryRun->id]);

        WorkflowRunSummary::create([
            'id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'pending',
            'status_bucket' => 'running',
            'started_at' => $run->started_at,
            'liveness_state' => 'repair_needed',
            'history_event_count' => 12,
            'history_size_bytes' => 2048,
            'continue_as_new_recommended' => true,
            'created_at' => now()->subMinutes(10),
            'updated_at' => now(),
        ]);

        $claimFailedInstance = WorkflowInstance::create([
            'id' => str_pad('01JTESTCLAIMINSTANCE', 26, '0'),
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $claimFailedRun = WorkflowRun::create([
            'id' => str_pad('01JTESTCLAIMRUN', 26, '0'),
            'workflow_instance_id' => $claimFailedInstance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'started_at' => now()->subMinutes(9),
            'last_progress_at' => now()->subMinute(),
        ]);

        $claimFailedInstance->update(['current_run_id' => $claimFailedRun->id]);

        WorkflowRunSummary::create([
            'id' => $claimFailedRun->id,
            'workflow_instance_id' => $claimFailedInstance->id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'status_bucket' => 'running',
            'started_at' => $claimFailedRun->started_at,
            'open_wait_id' => 'signal:missing',
            'liveness_state' => 'workflow_task_claim_failed',
            'created_at' => now()->subMinutes(9),
            'updated_at' => now(),
        ]);

        $timelineEvent = WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowStarted, [
            'workflow_run_id' => $run->id,
        ]);

        WorkflowRunWait::create([
            'id' => 'waterline-wait-orphan',
            'workflow_run_id' => str_pad('01JWLWAITMISSRUN', 26, '0'),
            'workflow_instance_id' => 'waterline-wait-orphan-instance',
            'wait_id' => 'signal:orphan',
            'position' => 0,
            'kind' => 'signal',
            'status' => 'open',
            'source_status' => 'open',
            'task_backed' => false,
            'external_only' => true,
        ]);

        WorkflowTimelineEntry::create([
            'id' => 'waterline-timeline-orphan',
            'workflow_run_id' => str_pad('01JWLTIMEMISSRUN', 26, '0'),
            'workflow_instance_id' => 'waterline-timeline-orphan-instance',
            'history_event_id' => str_pad('01JWLTIMEMISSEVENT', 26, '0'),
            'sequence' => $timelineEvent->sequence,
            'type' => 'WorkflowStarted',
            'kind' => 'workflow',
            'entry_kind' => 'point',
            'summary' => 'Orphaned timeline row.',
            'recorded_at' => now(),
        ]);

        WorkflowLink::create([
            'id' => '01JTESTFLOWLINKMETRICS0001',
            'link_type' => 'child_workflow',
            'parent_workflow_instance_id' => $missingSummaryInstance->id,
            'parent_workflow_run_id' => $missingSummaryRun->id,
            'child_workflow_instance_id' => $instance->id,
            'child_workflow_run_id' => $run->id,
            'is_primary_parent' => true,
        ]);

        WorkflowRunLineageEntry::create([
            'id' => 'metrics-lineage-entry',
            'workflow_run_id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'direction' => 'parent',
            'lineage_id' => '01JTESTFLOWLINKMETRICS0001',
            'position' => 0,
            'link_type' => 'child_workflow',
            'is_primary_parent' => true,
            'related_workflow_instance_id' => $missingSummaryInstance->id,
            'related_workflow_run_id' => $missingSummaryRun->id,
            'related_run_number' => $missingSummaryRun->run_number,
            'status' => 'completed',
            'status_bucket' => 'completed',
            'closed_reason' => 'completed',
            'linked_at' => now()->subMinute(),
            'payload' => [],
        ]);

        WorkflowRunLineageEntry::create([
            'id' => 'metrics-lineage-orphan',
            'workflow_run_id' => str_pad('01JWLLINEAGEMISSRUN', 26, '0'),
            'workflow_instance_id' => 'waterline-lineage-orphan-instance',
            'direction' => 'parent',
            'lineage_id' => 'lineage-orphan',
            'position' => 0,
            'link_type' => 'continue_as_new',
            'is_primary_parent' => true,
            'related_workflow_instance_id' => $instance->id,
            'related_workflow_run_id' => $run->id,
            'related_run_number' => $run->run_number,
            'status' => 'pending',
            'status_bucket' => 'running',
            'linked_at' => now()->subMinute(),
            'payload' => [],
        ]);

        WorkflowCommand::record($instance, $run, [
            'command_type' => CommandType::Start->value,
            'target_scope' => 'instance',
            'status' => CommandStatus::Accepted->value,
            'outcome' => CommandOutcome::StartedNew->value,
            'accepted_at' => now()->subSeconds(30),
            'applied_at' => now()->subSeconds(30),
        ]);

        WorkflowTask::create([
            'id' => '01JTESTFLOWTASKMETRICS0000',
            'workflow_run_id' => $run->id,
            'task_type' => 'workflow',
            'status' => 'ready',
            'available_at' => now()->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        WorkflowTask::create([
            'id' => str_pad('01JTESTCLAIMTASK', 26, '0'),
            'workflow_run_id' => $claimFailedRun->id,
            'task_type' => 'workflow',
            'status' => 'ready',
            'available_at' => now()->subSecond(),
            'payload' => [],
            'connection' => 'sync',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'last_claim_failed_at' => now()->subSecond(),
            'last_claim_error' => 'Workflow v2 backend capabilities are unsupported: [queue_sync_unsupported] sync.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ActivityExecution::create([
            'id' => '01JTESTRETRYACTIVITY000000',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => 'RetryActivity',
            'activity_type' => 'activity.retry',
            'status' => 'pending',
            'arguments' => serialize([]),
            'connection' => 'redis',
            'queue' => 'activities',
            'attempt_count' => 2,
            'current_attempt_id' => '01JTESTRETRYATTEMPT2000000',
            'started_at' => now()->subMinutes(2),
            'created_at' => now()->subMinutes(3),
            'updated_at' => now(),
        ]);

        ActivityAttempt::create([
            'id' => '01JTESTRETRYATTEMPT1000000',
            'workflow_run_id' => $run->id,
            'activity_execution_id' => '01JTESTRETRYACTIVITY000000',
            'workflow_task_id' => '01JTESTFLOWTASKMETRICS0000',
            'attempt_number' => 1,
            'status' => 'failed',
            'started_at' => now()->subMinutes(2),
            'closed_at' => now()->subMinute(),
        ]);

        WorkerCompatibilityFleet::record(['build-a'], 'redis', 'default', 'waterline-worker-a');

        $this->get('/waterline/api/stats')
            ->assertStatus(200)
            ->assertJsonPath('operator_metrics.runs.running', 2)
            ->assertJsonPath('operator_metrics.runs.claim_failed', 1)
            ->assertJsonPath('operator_metrics.tasks.claim_failed', 1)
            ->assertJsonPath('operator_metrics.activities.retrying', 1)
            ->assertJsonPath('operator_metrics.activities.pending', 1)
            ->assertJsonPath('operator_metrics.activities.running', 0)
            ->assertJsonPath('operator_metrics.activities.failed_attempts', 1)
            ->assertJsonPath('operator_metrics.activities.max_attempt_count', 2)
            ->assertJsonPath('operator_metrics.backlog.runnable_tasks', 2)
            ->assertJsonPath('operator_metrics.backlog.retrying_activities', 1)
            ->assertJsonPath('operator_metrics.backlog.repair_needed_runs', 1)
            ->assertJsonPath('operator_metrics.backlog.claim_failed_runs', 1)
            ->assertJsonPath('operator_metrics.repair.existing_task_candidates', 0)
            ->assertJsonPath('operator_metrics.repair.missing_task_candidates', 1)
            ->assertJsonPath('operator_metrics.repair.total_candidates', 1)
            ->assertJsonPath('operator_metrics.repair.scan_limit', 16)
            ->assertJsonPath('operator_metrics.repair.scan_strategy', 'scope_fair_round_robin')
            ->assertJsonPath('operator_metrics.repair.selected_existing_task_candidates', 0)
            ->assertJsonPath('operator_metrics.repair.selected_missing_task_candidates', 1)
            ->assertJsonPath('operator_metrics.repair.selected_total_candidates', 1)
            ->assertJsonPath('operator_metrics.repair.scan_pressure', false)
            ->assertJsonPath('operator_metrics.repair.scopes.0.scope_key', 'default:default:any')
            ->assertJsonPath('operator_metrics.repair.scopes.0.missing_task_candidates', 1)
            ->assertJsonPath('operator_metrics.repair.scopes.0.existing_task_candidates', 0)
            ->assertJsonPath('operator_metrics.repair.scopes.0.selected_missing_task_candidates', 1)
            ->assertJsonPath('operator_metrics.repair.scopes.0.selected_existing_task_candidates', 0)
            ->assertJsonPath('operator_metrics.repair.scopes.0.selected_total_candidates', 1)
            ->assertJsonPath('operator_metrics.repair.scopes.0.scan_limited_by_global_policy', false)
            ->assertJsonPath('operator_metrics.starts.pending_runs', 1)
            ->assertJsonPath('operator_metrics.starts.pending_commands', 1)
            ->assertJsonPath('operator_metrics.starts.ready_tasks', 1)
            ->assertJsonPath('operator_metrics.history.continue_as_new_recommended_runs', 1)
            ->assertJsonPath('operator_metrics.history.max_event_count', 12)
            ->assertJsonPath('operator_metrics.history.event_threshold', 10)
            ->assertJsonPath('operator_metrics.projections.run_summaries.runs', 3)
            ->assertJsonPath('operator_metrics.projections.run_summaries.summaries', 2)
            ->assertJsonPath('operator_metrics.projections.run_summaries.missing', 1)
            ->assertJsonPath('operator_metrics.projections.run_summaries.orphaned', 0)
            ->assertJsonPath('operator_metrics.projections.run_summaries.stale', 0)
            ->assertJsonPath('operator_metrics.projections.run_summaries.needs_rebuild', 1)
            ->assertJsonPath('operator_metrics.projections.run_waits.runs', 3)
            ->assertJsonPath('operator_metrics.projections.run_waits.rows', 1)
            ->assertJsonPath('operator_metrics.projections.run_waits.projected_runs', 1)
            ->assertJsonPath('operator_metrics.projections.run_waits.runs_with_waits', 1)
            ->assertJsonPath('operator_metrics.projections.run_waits.projected_runs_with_waits', 0)
            ->assertJsonPath('operator_metrics.projections.run_waits.missing_runs_with_waits', 1)
            ->assertJsonPath('operator_metrics.projections.run_waits.summaries_with_open_waits', 1)
            ->assertJsonPath('operator_metrics.projections.run_waits.projected_current_open_waits', 0)
            ->assertJsonPath('operator_metrics.projections.run_waits.missing_current_open_waits', 1)
            ->assertJsonPath('operator_metrics.projections.run_waits.stale_projected_runs', 0)
            ->assertJsonPath('operator_metrics.projections.run_waits.orphaned', 1)
            ->assertJsonPath('operator_metrics.projections.run_waits.needs_rebuild', 2)
            ->assertJsonPath('operator_metrics.projections.run_timeline_entries.runs', 3)
            ->assertJsonPath('operator_metrics.projections.run_timeline_entries.history_events', 1)
            ->assertJsonPath('operator_metrics.projections.run_timeline_entries.rows', 1)
            ->assertJsonPath('operator_metrics.projections.run_timeline_entries.projected_runs', 1)
            ->assertJsonPath('operator_metrics.projections.run_timeline_entries.runs_with_history', 1)
            ->assertJsonPath('operator_metrics.projections.run_timeline_entries.projected_runs_with_history', 0)
            ->assertJsonPath('operator_metrics.projections.run_timeline_entries.missing_runs_with_history', 1)
            ->assertJsonPath('operator_metrics.projections.run_timeline_entries.missing_history_events', 1)
            ->assertJsonPath('operator_metrics.projections.run_timeline_entries.stale_projected_runs', 0)
            ->assertJsonPath('operator_metrics.projections.run_timeline_entries.orphaned', 1)
            ->assertJsonPath('operator_metrics.projections.run_timeline_entries.needs_rebuild', 2)
            ->assertJsonPath('operator_metrics.projections.run_lineage_entries.runs', 3)
            ->assertJsonPath('operator_metrics.projections.run_lineage_entries.rows', 2)
            ->assertJsonPath('operator_metrics.projections.run_lineage_entries.projected_runs', 2)
            ->assertJsonPath('operator_metrics.projections.run_lineage_entries.runs_with_lineage', 2)
            ->assertJsonPath('operator_metrics.projections.run_lineage_entries.projected_runs_with_lineage', 1)
            ->assertJsonPath('operator_metrics.projections.run_lineage_entries.missing_runs_with_lineage', 1)
            ->assertJsonPath('operator_metrics.projections.run_lineage_entries.stale_projected_runs', 1)
            ->assertJsonPath('operator_metrics.projections.run_lineage_entries.orphaned', 1)
            ->assertJsonPath('operator_metrics.projections.run_lineage_entries.needs_rebuild', 3)
            ->assertJsonPath('operator_metrics.workers.compatibility_namespace', 'waterline-metrics-test')
            ->assertJsonPath('operator_metrics.workers.required_compatibility', 'build-a')
            ->assertJsonPath('operator_metrics.workers.active_workers', 1)
            ->assertJsonPath('operator_metrics.workers.active_worker_scopes', 1)
            ->assertJsonPath('operator_metrics.workers.active_workers_supporting_required', 1)
            ->assertJsonPath('operator_metrics.backend.queue.connection', 'sync')
            ->assertJsonPath('operator_metrics.backend.queue.driver', 'sync')
            ->assertJsonPath('operator_metrics.backend.supported', false)
            ->assertJsonFragment(['code' => 'queue_sync_unsupported'])
            ->assertJsonPath('operator_metrics.update_wait.completion_timeout_seconds', 9)
            ->assertJsonPath('operator_metrics.update_wait.poll_interval_milliseconds', 25)
            ->assertJsonPath('operator_metrics.repair_policy.redispatch_after_seconds', 8)
            ->assertJsonPath('operator_metrics.repair_policy.loop_throttle_seconds', 12)
            ->assertJsonPath('operator_metrics.repair_policy.scan_limit', 16)
            ->assertJsonPath('operator_metrics.repair_policy.scan_strategy', 'scope_fair_round_robin')
            ->assertJsonPath('operator_metrics.repair_policy.failure_backoff_max_seconds', 32)
            ->assertJsonPath('operator_metrics.repair_policy.failure_backoff_strategy', 'exponential_by_repair_count');
    }

    public function testIndexIncludesCommandContractBackfillMetrics(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $availableInstance = WorkflowInstance::create([
            'id' => 'waterline-command-contract-available',
            'workflow_class' => TestCommandContractWorkflow::class,
            'workflow_type' => 'workflow.command-contract',
            'run_count' => 1,
        ]);

        $availableRun = WorkflowRun::create([
            'id' => '01JWLCONTRACTAVAILABLE0001',
            'workflow_instance_id' => $availableInstance->id,
            'run_number' => 1,
            'workflow_class' => TestCommandContractWorkflow::class,
            'workflow_type' => 'workflow.command-contract',
            'status' => 'waiting',
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subSecond(),
        ]);

        $availableInstance->update(['current_run_id' => $availableRun->id]);

        WorkflowHistoryEvent::record($availableRun, HistoryEventType::WorkflowStarted, [
            'workflow_class' => TestCommandContractWorkflow::class,
            'workflow_type' => 'workflow.command-contract',
            'declared_queries' => ['current-stage', 'stageMatches'],
            'declared_query_contracts' => [
                [
                    'name' => 'current-stage',
                    'parameters' => [],
                ],
            ],
            'declared_signals' => ['approved-by', 'rejected-by'],
            'declared_signal_contracts' => [
                [
                    'name' => 'approved-by',
                    'parameters' => [
                        [
                            'name' => 'actor',
                            'position' => 0,
                            'required' => true,
                            'variadic' => false,
                            'default_available' => false,
                            'default' => null,
                            'type' => 'string',
                            'allows_null' => false,
                        ],
                    ],
                ],
            ],
            'declared_updates' => ['mark-approved'],
            'declared_update_contracts' => [
                [
                    'name' => 'mark-approved',
                    'parameters' => [
                        [
                            'name' => 'approved',
                            'position' => 0,
                            'required' => true,
                            'variadic' => false,
                            'default_available' => false,
                            'default' => null,
                            'type' => 'bool',
                            'allows_null' => false,
                        ],
                    ],
                ],
            ],
        ]);

        $unavailableInstance = WorkflowInstance::create([
            'id' => 'waterline-command-contract-unavailable',
            'workflow_class' => 'Missing\\Workflow\\CommandContractWorkflow',
            'workflow_type' => 'missing-command-contract-workflow',
            'run_count' => 1,
        ]);

        $unavailableRun = WorkflowRun::create([
            'id' => '01JWLCONTRACTUNAVAILABLE01',
            'workflow_instance_id' => $unavailableInstance->id,
            'run_number' => 1,
            'workflow_class' => 'Missing\\Workflow\\CommandContractWorkflow',
            'workflow_type' => 'missing-command-contract-workflow',
            'status' => 'waiting',
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subSecond(),
        ]);

        $unavailableInstance->update(['current_run_id' => $unavailableRun->id]);

        WorkflowHistoryEvent::record($unavailableRun, HistoryEventType::WorkflowStarted, [
            'workflow_class' => 'Missing\\Workflow\\CommandContractWorkflow',
            'workflow_type' => 'missing-command-contract-workflow',
            'declared_signals' => ['approved-by', 'rejected-by'],
            'declared_updates' => ['mark-approved'],
        ]);

        $this->get('/waterline/api/stats')
            ->assertStatus(200)
            ->assertJsonPath('operator_metrics.command_contracts.backfill_needed_runs', 2)
            ->assertJsonPath('operator_metrics.command_contracts.backfill_available_runs', 1)
            ->assertJsonPath('operator_metrics.command_contracts.backfill_unavailable_runs', 1);
    }
}
