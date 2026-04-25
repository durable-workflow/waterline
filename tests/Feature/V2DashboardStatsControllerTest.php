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
use Workflow\V2\Models\WorkflowRunTimerEntry;
use Workflow\V2\Models\WorkflowRunWait;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimelineEntry;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Support\WorkerCompatibilityFleet;

class V2DashboardStatsControllerTest extends TestCase
{
    public function testIndexUsesV2RunSummaries()
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => '01JTESTFLOWINSTANCE2FACBC3',
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
            'id' => '01JTESTFAILURE00000ED9632A',
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
            'id' => '01JTESTFLOWINSTANCED6BD865',
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
        config()->set('workflows.v2.task_dispatch_mode', 'queue');
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
            'projection_schema_version' => RunSummaryProjector::SCHEMA_VERSION,
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
            'projection_schema_version' => RunSummaryProjector::SCHEMA_VERSION,
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
        WorkflowHistoryEvent::record($run, HistoryEventType::TimerScheduled, [
            'timer_id' => 'waterline-selected-timer',
            'sequence' => 2,
            'delay_seconds' => 60,
            'fire_at' => now()->addMinute()->toJSON(),
        ]);
        WorkflowRunTimerEntry::create([
            'id' => 'waterline-timer-orphan',
            'workflow_run_id' => str_pad('01JWLTIMERMISSRUN', 26, '0'),
            'workflow_instance_id' => 'waterline-timer-orphan-instance',
            'timer_id' => 'waterline-orphan-timer',
            'schema_version' => WorkflowRunTimerEntry::CURRENT_SCHEMA_VERSION - 1,
            'position' => 0,
            'status' => 'pending',
            'source_status' => 'pending',
            'history_authority' => 'typed_history',
            'payload' => [
                'id' => 'waterline-orphan-timer',
                'status' => 'pending',
                'source_status' => 'pending',
                'history_authority' => 'typed_history',
                'history_event_types' => [],
            ],
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
            ->assertJsonPath('operator_metrics.tasks.dispatch_overdue', 0)
            ->assertJsonPath('operator_metrics.tasks.lease_expired', 0)
            ->assertJsonPath('operator_metrics.tasks.unhealthy', 1)
            ->assertJsonPath('operator_metrics.backlog.runnable_tasks', 2)
            ->assertJsonPath('operator_metrics.backlog.retrying_activities', 1)
            ->assertJsonPath('operator_metrics.backlog.repair_needed_runs', 1)
            ->assertJsonPath('operator_metrics.backlog.claim_failed_runs', 1)
            ->assertJsonPath('operator_metrics.backlog.delayed_tasks', 0)
            ->assertJsonPath('operator_metrics.backlog.leased_tasks', 0)
            ->assertJsonPath('operator_metrics.backlog.unhealthy_tasks', 1)
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
            ->assertJsonStructure([
                'operator_metrics' => [
                    'repair' => ['max_missing_run_age_ms', 'oldest_missing_run_started_at'],
                ],
            ])
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
            ->assertJsonPath('operator_metrics.projections.run_timeline_entries.history_events', 2)
            ->assertJsonPath('operator_metrics.projections.run_timeline_entries.rows', 1)
            ->assertJsonPath('operator_metrics.projections.run_timeline_entries.projected_runs', 1)
            ->assertJsonPath('operator_metrics.projections.run_timeline_entries.runs_with_history', 1)
            ->assertJsonPath('operator_metrics.projections.run_timeline_entries.projected_runs_with_history', 0)
            ->assertJsonPath('operator_metrics.projections.run_timeline_entries.missing_runs_with_history', 1)
            ->assertJsonPath('operator_metrics.projections.run_timeline_entries.missing_history_events', 2)
            ->assertJsonPath('operator_metrics.projections.run_timeline_entries.stale_projected_runs', 0)
            ->assertJsonPath('operator_metrics.projections.run_timeline_entries.orphaned', 1)
            ->assertJsonPath('operator_metrics.projections.run_timeline_entries.needs_rebuild', 2)
            ->assertJsonPath('operator_metrics.projections.run_timer_entries.runs', 3)
            ->assertJsonPath('operator_metrics.projections.run_timer_entries.rows', 1)
            ->assertJsonPath('operator_metrics.projections.run_timer_entries.projected_runs', 1)
            ->assertJsonPath('operator_metrics.projections.run_timer_entries.runs_with_timers', 1)
            ->assertJsonPath('operator_metrics.projections.run_timer_entries.projected_runs_with_timers', 0)
            ->assertJsonPath('operator_metrics.projections.run_timer_entries.missing_runs_with_timers', 1)
            ->assertJsonPath('operator_metrics.projections.run_timer_entries.stale_projected_runs', 0)
            ->assertJsonPath('operator_metrics.projections.run_timer_entries.schema_version_mismatch_runs', 0)
            ->assertJsonPath('operator_metrics.projections.run_timer_entries.schema_version_mismatch_rows', 1)
            ->assertJsonPath('operator_metrics.projections.run_timer_entries.orphaned', 1)
            ->assertJsonPath('operator_metrics.projections.run_timer_entries.needs_rebuild', 2)
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

    public function testIndexExposesSchedulerRoleMetrics(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $response = $this->get('/waterline/api/stats')->assertStatus(200);

        $schedules = $response->json('operator_metrics.schedules');

        $this->assertIsArray(
            $schedules,
            'operator_metrics.schedules must be present in the dashboard payload; '
                . 'vendored workflow package must resolve to >=2.0.0-alpha.11.',
        );
        foreach (
            ['active', 'paused', 'missed', 'oldest_overdue_at', 'max_overdue_ms', 'fires_total', 'failures_total']
            as $key
        ) {
            $this->assertArrayHasKey(
                $key,
                $schedules,
                sprintf('operator_metrics.schedules.%s must be present in the dashboard payload', $key),
            );
        }

        $this->assertIsInt($schedules['active']);
        $this->assertIsInt($schedules['paused']);
        $this->assertIsInt($schedules['missed']);
        $this->assertIsInt($schedules['fires_total']);
        $this->assertIsInt($schedules['failures_total']);
        $this->assertTrue(
            $schedules['oldest_overdue_at'] === null || is_string($schedules['oldest_overdue_at']),
            'operator_metrics.schedules.oldest_overdue_at must be null or ISO-8601 string',
        );
        $this->assertTrue(
            $schedules['max_overdue_ms'] === null || is_int($schedules['max_overdue_ms']),
            'operator_metrics.schedules.max_overdue_ms must be null or integer milliseconds',
        );
    }

    public function testIndexExposesCompatibilityBlockedAge(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $response = $this->get('/waterline/api/stats')->assertStatus(200);

        $backlog = $response->json('operator_metrics.backlog');

        $this->assertIsArray($backlog);

        foreach (
            ['oldest_compatibility_blocked_started_at', 'max_compatibility_blocked_age_ms']
            as $key
        ) {
            $this->assertArrayHasKey(
                $key,
                $backlog,
                sprintf('operator_metrics.backlog.%s must be present in the dashboard payload', $key),
            );
        }

        $this->assertTrue(
            $backlog['oldest_compatibility_blocked_started_at'] === null
                || is_string($backlog['oldest_compatibility_blocked_started_at']),
            'operator_metrics.backlog.oldest_compatibility_blocked_started_at must be null or ISO-8601 string',
        );
        $this->assertTrue(
            $backlog['max_compatibility_blocked_age_ms'] === null
                || is_int($backlog['max_compatibility_blocked_age_ms']),
            'operator_metrics.backlog.max_compatibility_blocked_age_ms must be null or integer milliseconds',
        );
    }

    public function testIndexExposesStuckLeaseAge(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $response = $this->get('/waterline/api/stats')->assertStatus(200);

        $tasks = $response->json('operator_metrics.tasks');

        $this->assertIsArray($tasks);
        $this->assertArrayHasKey('oldest_lease_expired_at', $tasks);
        $this->assertArrayHasKey('max_lease_expired_age_ms', $tasks);

        $this->assertTrue(
            $tasks['oldest_lease_expired_at'] === null
                || is_string($tasks['oldest_lease_expired_at']),
            'operator_metrics.tasks.oldest_lease_expired_at must be null or ISO-8601 string',
        );
        $this->assertTrue(
            $tasks['max_lease_expired_age_ms'] === null
                || is_int($tasks['max_lease_expired_age_ms']),
            'operator_metrics.tasks.max_lease_expired_age_ms must be null or integer milliseconds',
        );
    }

    public function testIndexExposesReadyDueAge(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $response = $this->get('/waterline/api/stats')->assertStatus(200);

        $tasks = $response->json('operator_metrics.tasks');

        $this->assertIsArray($tasks);
        $this->assertArrayHasKey('oldest_ready_due_at', $tasks);
        $this->assertArrayHasKey('max_ready_due_age_ms', $tasks);

        $this->assertTrue(
            $tasks['oldest_ready_due_at'] === null
                || is_string($tasks['oldest_ready_due_at']),
            'operator_metrics.tasks.oldest_ready_due_at must be null or ISO-8601 string',
        );
        $this->assertTrue(
            $tasks['max_ready_due_age_ms'] === null
                || is_int($tasks['max_ready_due_age_ms']),
            'operator_metrics.tasks.max_ready_due_age_ms must be null or integer milliseconds',
        );
    }

    public function testIndexExposesDispatchOverdueAge(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $response = $this->get('/waterline/api/stats')->assertStatus(200);

        $tasks = $response->json('operator_metrics.tasks');

        $this->assertIsArray($tasks);
        $this->assertArrayHasKey('oldest_dispatch_overdue_since', $tasks);
        $this->assertArrayHasKey('max_dispatch_overdue_age_ms', $tasks);

        $this->assertTrue(
            $tasks['oldest_dispatch_overdue_since'] === null
                || is_string($tasks['oldest_dispatch_overdue_since']),
            'operator_metrics.tasks.oldest_dispatch_overdue_since must be null or ISO-8601 string',
        );
        $this->assertTrue(
            $tasks['max_dispatch_overdue_age_ms'] === null
                || is_int($tasks['max_dispatch_overdue_age_ms']),
            'operator_metrics.tasks.max_dispatch_overdue_age_ms must be null or integer milliseconds',
        );
    }

    public function testIndexExposesRunWaitAge(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $response = $this->get('/waterline/api/stats')->assertStatus(200);

        $runs = $response->json('operator_metrics.runs');

        $this->assertIsArray($runs);
        $this->assertArrayHasKey('waiting', $runs);
        $this->assertArrayHasKey('oldest_wait_started_at', $runs);
        $this->assertArrayHasKey('max_wait_age_ms', $runs);

        $this->assertIsInt($runs['waiting']);
        $this->assertTrue(
            $runs['oldest_wait_started_at'] === null
                || is_string($runs['oldest_wait_started_at']),
            'operator_metrics.runs.oldest_wait_started_at must be null or ISO-8601 string',
        );
        $this->assertTrue(
            $runs['max_wait_age_ms'] === null
                || is_int($runs['max_wait_age_ms']),
            'operator_metrics.runs.max_wait_age_ms must be null or integer milliseconds',
        );
    }

    public function testIndexExposesRetryingActivityAge(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $response = $this->get('/waterline/api/stats')->assertStatus(200);

        $activities = $response->json('operator_metrics.activities');

        $this->assertIsArray($activities);
        $this->assertArrayHasKey('oldest_retrying_started_at', $activities);
        $this->assertArrayHasKey('max_retrying_age_ms', $activities);

        $this->assertTrue(
            $activities['oldest_retrying_started_at'] === null
                || is_string($activities['oldest_retrying_started_at']),
            'operator_metrics.activities.oldest_retrying_started_at must be null or ISO-8601 string',
        );
        $this->assertTrue(
            $activities['max_retrying_age_ms'] === null
                || is_int($activities['max_retrying_age_ms']),
            'operator_metrics.activities.max_retrying_age_ms must be null or integer milliseconds',
        );
    }

    public function testIndexExposesClaimFailedAge(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $response = $this->get('/waterline/api/stats')->assertStatus(200);

        $tasks = $response->json('operator_metrics.tasks');

        $this->assertIsArray($tasks);
        $this->assertArrayHasKey('oldest_claim_failed_at', $tasks);
        $this->assertArrayHasKey('max_claim_failed_age_ms', $tasks);

        $this->assertTrue(
            $tasks['oldest_claim_failed_at'] === null
                || is_string($tasks['oldest_claim_failed_at']),
            'operator_metrics.tasks.oldest_claim_failed_at must be null or ISO-8601 string',
        );
        $this->assertTrue(
            $tasks['max_claim_failed_age_ms'] === null
                || is_int($tasks['max_claim_failed_age_ms']),
            'operator_metrics.tasks.max_claim_failed_age_ms must be null or integer milliseconds',
        );
    }

    public function testIndexExposesDispatchFailedAge(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $response = $this->get('/waterline/api/stats')->assertStatus(200);

        $tasks = $response->json('operator_metrics.tasks');

        $this->assertIsArray($tasks);
        $this->assertArrayHasKey('oldest_dispatch_failed_at', $tasks);
        $this->assertArrayHasKey('max_dispatch_failed_age_ms', $tasks);

        $this->assertTrue(
            $tasks['oldest_dispatch_failed_at'] === null
                || is_string($tasks['oldest_dispatch_failed_at']),
            'operator_metrics.tasks.oldest_dispatch_failed_at must be null or ISO-8601 string',
        );
        $this->assertTrue(
            $tasks['max_dispatch_failed_age_ms'] === null
                || is_int($tasks['max_dispatch_failed_age_ms']),
            'operator_metrics.tasks.max_dispatch_failed_age_ms must be null or integer milliseconds',
        );
    }

    public function testIndexExposesRunSummaryMissingAge(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $response = $this->get('/waterline/api/stats')->assertStatus(200);

        $projections = $response->json('operator_metrics.projections');
        $runSummaries = is_array($projections['run_summaries'] ?? null) ? $projections['run_summaries'] : null;

        if (! is_array($runSummaries)
            || ! array_key_exists('max_missing_run_age_ms', $runSummaries)
            || ! array_key_exists('oldest_missing_run_started_at', $runSummaries)) {
            $this->markTestSkipped(
                'Vendored workflow package predates the run-summary projection-lag rollout-safety '
                . 'contract (operator_metrics.projections.run_summaries.{oldest_missing_run_started_at,max_missing_run_age_ms}).',
            );
        }

        $this->assertTrue(
            $runSummaries['oldest_missing_run_started_at'] === null
                || is_string($runSummaries['oldest_missing_run_started_at']),
            'operator_metrics.projections.run_summaries.oldest_missing_run_started_at must be null or ISO-8601 string',
        );
        $this->assertTrue(
            $runSummaries['max_missing_run_age_ms'] === null
                || is_int($runSummaries['max_missing_run_age_ms']),
            'operator_metrics.projections.run_summaries.max_missing_run_age_ms must be null or integer milliseconds',
        );
    }

    public function testIndexExposesMatchingRole(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $response = $this->get('/waterline/api/stats')->assertStatus(200);

        $matchingRole = $response->json('operator_metrics.matching_role');

        $this->assertIsArray($matchingRole);
        $this->assertArrayHasKey('queue_wake_enabled', $matchingRole);
        $this->assertArrayHasKey('shape', $matchingRole);
        $this->assertArrayHasKey('task_dispatch_mode', $matchingRole);

        $this->assertIsBool($matchingRole['queue_wake_enabled']);
        $this->assertContains(
            $matchingRole['shape'],
            ['in_worker', 'dedicated'],
            'operator_metrics.matching_role.shape must be in_worker or dedicated',
        );
        $this->assertContains(
            $matchingRole['task_dispatch_mode'],
            ['queue', 'poll'],
            'operator_metrics.matching_role.task_dispatch_mode must be queue or poll',
        );
    }

    public function testIndexExposesUnhealthyAgeRollup(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $response = $this->get('/waterline/api/stats')->assertStatus(200);

        $tasks = $response->json('operator_metrics.tasks');

        $this->assertIsArray($tasks);

        if (! array_key_exists('oldest_unhealthy_at', $tasks)
            || ! array_key_exists('max_unhealthy_age_ms', $tasks)) {
            $this->markTestSkipped(
                'Vendored workflow package predates the unhealthy-age rollup '
                . '(operator_metrics.tasks.{oldest_unhealthy_at,max_unhealthy_age_ms}).',
            );
        }

        $this->assertTrue(
            $tasks['oldest_unhealthy_at'] === null
                || is_string($tasks['oldest_unhealthy_at']),
            'operator_metrics.tasks.oldest_unhealthy_at must be null or ISO-8601 string',
        );
        $this->assertTrue(
            $tasks['max_unhealthy_age_ms'] === null
                || is_int($tasks['max_unhealthy_age_ms']),
            'operator_metrics.tasks.max_unhealthy_age_ms must be null or integer milliseconds',
        );
    }

    public function testIndexExposesBackendSeverityRollup(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $response = $this->get('/waterline/api/stats')->assertStatus(200);

        $backend = $response->json('operator_metrics.backend');

        $this->assertIsArray($backend);

        if (! array_key_exists('severity', $backend)) {
            $this->markTestSkipped(
                'Vendored workflow package predates the backend-admission severity rollup '
                . '(operator_metrics.backend.severity).',
            );
        }

        $this->assertIsString($backend['severity']);
        $this->assertContains(
            $backend['severity'],
            ['ok', 'info', 'warning', 'error'],
            'operator_metrics.backend.severity must be one of ok, info, warning, error',
        );
    }
}
