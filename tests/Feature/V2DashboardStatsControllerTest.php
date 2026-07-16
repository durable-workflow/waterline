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
use Workflow\V2\Contracts\OperatorObservabilityRepository;
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

    public function testIndexScopesWorkerFleetToConfiguredWaterlineNamespace(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'billing');
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()->set('workflows.v2.compatibility.namespace', 'shipping');

        WorkerCompatibilityFleet::clear();
        $this->beforeApplicationDestroyed(static function (): void {
            WorkerCompatibilityFleet::clear();
        });

        WorkerCompatibilityFleet::recordForNamespace(
            namespace: 'billing',
            supported: ['build-a'],
            connection: 'redis',
            queue: 'default',
            workerId: 'worker-billing',
        );
        WorkerCompatibilityFleet::recordForNamespace(
            namespace: 'shipping',
            supported: ['build-a'],
            connection: 'redis',
            queue: 'default',
            workerId: 'worker-shipping',
        );

        $this->get('/waterline/api/stats')
            ->assertOk()
            ->assertJsonPath('operator_metrics.workers.compatibility_namespace', 'billing')
            ->assertJsonPath('operator_metrics.workers.active_workers', 1)
            ->assertJsonPath('operator_metrics.workers.active_worker_scopes', 1)
            ->assertJsonPath('operator_metrics.workers.fleet.0.worker_id', 'worker-billing')
            ->assertJsonPath('operator_metrics.workers.fleet.0.namespace', 'billing');
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
            ->assertJsonPath('operator_metrics.backlog.tasks_added_last_minute', 2)
            ->assertJsonPath('operator_metrics.backlog.tasks_dispatched_last_minute', 0)
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
            ->assertJsonPath('operator_metrics.backend.supported', true)
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

    public function testIndexScopesWorkerFleetMetricsToConfiguredNamespace(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'billing');
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()->set('workflows.v2.compatibility.supported', ['build-a']);
        WorkerCompatibilityFleet::clear();
        $this->beforeApplicationDestroyed(static function (): void {
            WorkerCompatibilityFleet::clear();
        });

        WorkerCompatibilityFleet::recordForNamespace('billing', ['build-a'], 'redis', 'default', 'worker-billing');
        WorkerCompatibilityFleet::recordForNamespace('shipping', ['build-a'], 'redis', 'default', 'worker-shipping');

        $this->get('/waterline/api/stats')
            ->assertOk()
            ->assertJsonPath('operator_metrics.workers.compatibility_namespace', 'billing')
            ->assertJsonPath('operator_metrics.workers.active_workers', 1)
            ->assertJsonPath('operator_metrics.workers.active_worker_scopes', 1)
            ->assertJsonPath('operator_metrics.workers.active_workers_supporting_required', 1)
            ->assertJsonCount(1, 'operator_metrics.workers.fleet')
            ->assertJsonPath('operator_metrics.workers.fleet.0.worker_id', 'worker-billing')
            ->assertJsonPath('operator_metrics.workers.fleet.0.namespace', 'billing')
            ->assertJsonPath('operator_metrics.workers.fleet.0.connection', 'redis')
            ->assertJsonPath('operator_metrics.workers.fleet.0.queue', 'default')
            ->assertJsonPath('operator_metrics.workers.fleet.0.supports_required', true);
    }

    public function testIndexDistinguishesSupportingWorkersInNamespaceScopedFleet(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'billing');
        config()->set('workflows.v2.compatibility.current', 'build-b');
        config()->set('workflows.v2.compatibility.supported', ['build-b']);

        WorkerCompatibilityFleet::clear();
        $this->beforeApplicationDestroyed(static function (): void {
            WorkerCompatibilityFleet::clear();
        });

        WorkerCompatibilityFleet::recordForNamespace(
            namespace: 'billing',
            supported: ['build-a'],
            connection: 'redis',
            queue: 'default',
            workerId: 'worker-billing-old',
        );
        WorkerCompatibilityFleet::recordForNamespace(
            namespace: 'billing',
            supported: ['build-a', 'build-b'],
            connection: 'redis',
            queue: 'default',
            workerId: 'worker-billing-new',
        );
        WorkerCompatibilityFleet::recordForNamespace(
            namespace: 'shipping',
            supported: ['build-b'],
            connection: 'redis',
            queue: 'default',
            workerId: 'worker-shipping',
        );

        $this->get('/waterline/api/stats')
            ->assertOk()
            ->assertJsonPath('operator_metrics.workers.compatibility_namespace', 'billing')
            ->assertJsonPath('operator_metrics.workers.required_compatibility', 'build-b')
            ->assertJsonPath('operator_metrics.workers.active_workers', 2)
            ->assertJsonPath('operator_metrics.workers.active_worker_scopes', 2)
            ->assertJsonPath('operator_metrics.workers.active_workers_supporting_required', 1)
            ->assertJsonCount(2, 'operator_metrics.workers.fleet')
            ->assertJsonFragment([
                'worker_id' => 'worker-billing-old',
                'namespace' => 'billing',
                'connection' => 'redis',
                'queue' => 'default',
                'supported' => ['build-a'],
                'supports_required' => false,
            ])
            ->assertJsonFragment([
                'worker_id' => 'worker-billing-new',
                'namespace' => 'billing',
                'connection' => 'redis',
                'queue' => 'default',
                'supported' => ['build-a', 'build-b'],
                'supports_required' => true,
            ])
            ->assertJsonMissing(['worker_id' => 'worker-shipping']);
    }

    public function testIndexReportsZeroSupportingWorkersWhenNamespaceFleetLacksRequiredCompatibility(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'billing');
        config()->set('workflows.v2.compatibility.current', 'build-c');
        config()->set('workflows.v2.compatibility.supported', ['build-c']);

        WorkerCompatibilityFleet::clear();
        $this->beforeApplicationDestroyed(static function (): void {
            WorkerCompatibilityFleet::clear();
        });

        // Two workers heartbeat in the configured namespace but advertise only
        // older builds — the rebuild path must surface them as fleet entries
        // while still reporting zero workers supporting the required build.
        WorkerCompatibilityFleet::recordForNamespace(
            namespace: 'billing',
            supported: ['build-a'],
            connection: 'redis',
            queue: 'default',
            workerId: 'worker-billing-stale-a',
        );
        WorkerCompatibilityFleet::recordForNamespace(
            namespace: 'billing',
            supported: ['build-a', 'build-b'],
            connection: 'redis',
            queue: 'default',
            workerId: 'worker-billing-stale-ab',
        );
        // A foreign-namespace worker advertises the required build. The
        // namespace filter must keep it out of the billing-scoped fleet, so
        // its support cannot rescue active_workers_supporting_required from
        // zero — that metric tells operators whether the configured namespace
        // can safely accept work, not whether the fleet at large can.
        WorkerCompatibilityFleet::recordForNamespace(
            namespace: 'shipping',
            supported: ['build-c'],
            connection: 'redis',
            queue: 'default',
            workerId: 'worker-shipping-current',
        );

        $this->get('/waterline/api/stats')
            ->assertOk()
            ->assertJsonPath('operator_metrics.workers.compatibility_namespace', 'billing')
            ->assertJsonPath('operator_metrics.workers.required_compatibility', 'build-c')
            ->assertJsonPath('operator_metrics.workers.active_workers', 2)
            ->assertJsonPath('operator_metrics.workers.active_worker_scopes', 2)
            ->assertJsonPath('operator_metrics.workers.active_workers_supporting_required', 0)
            ->assertJsonCount(2, 'operator_metrics.workers.fleet')
            ->assertJsonFragment([
                'worker_id' => 'worker-billing-stale-a',
                'namespace' => 'billing',
                'connection' => 'redis',
                'queue' => 'default',
                'supported' => ['build-a'],
                'supports_required' => false,
            ])
            ->assertJsonFragment([
                'worker_id' => 'worker-billing-stale-ab',
                'namespace' => 'billing',
                'connection' => 'redis',
                'queue' => 'default',
                'supported' => ['build-a', 'build-b'],
                'supports_required' => false,
            ])
            ->assertJsonMissing(['worker_id' => 'worker-shipping-current']);
    }

    public function testIndexReplacesPriorScopeWhenWorkerHeartbeatMovesQueues(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'billing');
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()->set('workflows.v2.compatibility.supported', ['build-a']);

        WorkerCompatibilityFleet::clear();
        $this->beforeApplicationDestroyed(static function (): void {
            WorkerCompatibilityFleet::clear();
        });

        WorkerCompatibilityFleet::recordForNamespace(
            namespace: 'billing',
            supported: ['build-a'],
            connection: 'redis',
            queue: 'default',
            workerId: 'worker-billing-multi',
        );
        WorkerCompatibilityFleet::recordForNamespace(
            namespace: 'billing',
            supported: ['build-a'],
            connection: 'redis',
            queue: 'priority',
            workerId: 'worker-billing-multi',
        );

        $this->get('/waterline/api/stats')
            ->assertOk()
            ->assertJsonPath('operator_metrics.workers.compatibility_namespace', 'billing')
            ->assertJsonPath('operator_metrics.workers.active_workers', 1)
            ->assertJsonPath('operator_metrics.workers.active_worker_scopes', 1)
            ->assertJsonPath('operator_metrics.workers.active_workers_supporting_required', 1)
            ->assertJsonCount(1, 'operator_metrics.workers.fleet')
            ->assertJsonFragment([
                'worker_id' => 'worker-billing-multi',
                'namespace' => 'billing',
                'connection' => 'redis',
                'queue' => 'priority',
                'supports_required' => true,
            ]);
    }

    public function testIndexReturnsEmptyNamespaceScopedFleetWhenNoMatchingWorkers(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'billing');
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()->set('workflows.v2.compatibility.supported', ['build-a']);

        WorkerCompatibilityFleet::clear();
        $this->beforeApplicationDestroyed(static function (): void {
            WorkerCompatibilityFleet::clear();
        });

        WorkerCompatibilityFleet::recordForNamespace(
            namespace: 'shipping',
            supported: ['build-a'],
            connection: 'redis',
            queue: 'default',
            workerId: 'worker-shipping',
        );
        WorkerCompatibilityFleet::recordForNamespace(
            namespace: 'inventory',
            supported: ['build-a'],
            connection: 'redis',
            queue: 'default',
            workerId: 'worker-inventory',
        );

        $this->get('/waterline/api/stats')
            ->assertOk()
            ->assertJsonPath('operator_metrics.workers.compatibility_namespace', 'billing')
            ->assertJsonPath('operator_metrics.workers.required_compatibility', 'build-a')
            ->assertJsonPath('operator_metrics.workers.active_workers', 0)
            ->assertJsonPath('operator_metrics.workers.active_worker_scopes', 0)
            ->assertJsonPath('operator_metrics.workers.active_workers_supporting_required', 0)
            ->assertJsonPath('operator_metrics.workers.fleet', [])
            ->assertJsonMissing(['worker_id' => 'worker-shipping'])
            ->assertJsonMissing(['worker_id' => 'worker-inventory']);
    }

    public function testIndexPassesThroughEngineSuppliedNamespaceScopedWorkerFleet(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'billing');
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()->set('workflows.v2.compatibility.supported', ['build-a']);

        WorkerCompatibilityFleet::clear();
        $this->beforeApplicationDestroyed(static function (): void {
            WorkerCompatibilityFleet::clear();
        });

        // If Waterline ignored the engine-supplied namespace scoping and rebuilt
        // the fleet from WorkerCompatibilityFleet, this entry would surface in
        // the response. The early-return path means the engine payload wins.
        WorkerCompatibilityFleet::recordForNamespace(
            namespace: 'billing',
            supported: ['build-a'],
            connection: 'redis',
            queue: 'rebuild-only',
            workerId: 'worker-rebuild-only',
        );

        $this->app->instance(
            OperatorObservabilityRepository::class,
            new class implements OperatorObservabilityRepository
            {
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
                    ?\Carbon\CarbonInterface $exportedAt = null,
                    \Workflow\V2\Contracts\HistoryExportRedactor|callable|null $redactor = null,
                ): array {
                    return [];
                }

                public function dashboardSummary(?\Carbon\CarbonInterface $now = null, ?string $namespace = null): array
                {
                    return [
                        'flows' => 0,
                        'flows_per_minute' => 0,
                        'flows_past_hour' => 0,
                        'exceptions_past_hour' => 0,
                        'failed_flows_past_week' => 0,
                        'max_wait_time_workflow' => null,
                        'max_duration_workflow' => null,
                        'max_exceptions_workflow' => null,
                        'operator_metrics' => [
                            'workers' => [
                                'compatibility_namespace' => 'billing',
                                'required_compatibility' => 'build-a',
                                'active_workers' => 7,
                                'active_worker_scopes' => 9,
                                'active_workers_supporting_required' => 5,
                                'fleet' => [
                                    [
                                        'worker_id' => 'engine-billing-1',
                                        'namespace' => 'billing',
                                        'connection' => 'redis',
                                        'queue' => 'engine-default',
                                        'supported' => ['build-a'],
                                        'supports_required' => true,
                                    ],
                                ],
                            ],
                        ],
                    ];
                }

                public function metrics(?\Carbon\CarbonInterface $now = null, ?string $namespace = null): array
                {
                    return [];
                }
            }
        );

        $this->get('/waterline/api/stats')
            ->assertOk()
            ->assertJsonPath('operator_metrics.workers.compatibility_namespace', 'billing')
            ->assertJsonPath('operator_metrics.workers.required_compatibility', 'build-a')
            ->assertJsonPath('operator_metrics.workers.active_workers', 7)
            ->assertJsonPath('operator_metrics.workers.active_worker_scopes', 9)
            ->assertJsonPath('operator_metrics.workers.active_workers_supporting_required', 5)
            ->assertJsonCount(1, 'operator_metrics.workers.fleet')
            ->assertJsonPath('operator_metrics.workers.fleet.0.worker_id', 'engine-billing-1')
            ->assertJsonPath('operator_metrics.workers.fleet.0.queue', 'engine-default')
            ->assertJsonPath('operator_metrics.workers.fleet.0.supports_required', true)
            ->assertJsonMissing(['worker_id' => 'worker-rebuild-only']);
    }

    public function testIndexPassesThroughWorkerFleetWhenWaterlineNamespaceUnconfigured(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', null);
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()->set('workflows.v2.compatibility.supported', ['build-a']);

        WorkerCompatibilityFleet::clear();
        $this->beforeApplicationDestroyed(static function (): void {
            WorkerCompatibilityFleet::clear();
        });

        // If Waterline rebuilt the fleet from WorkerCompatibilityFleet despite no
        // configured namespace, this entry's distinct queue would surface in the
        // response. With a null namespace, scopedWorkerMetrics must early-return
        // the engine payload verbatim.
        WorkerCompatibilityFleet::recordForNamespace(
            namespace: 'fleet-global',
            supported: ['build-a'],
            connection: 'redis',
            queue: 'rebuild-only',
            workerId: 'worker-rebuild-only',
        );

        $this->app->instance(
            OperatorObservabilityRepository::class,
            new class implements OperatorObservabilityRepository
            {
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
                    ?\Carbon\CarbonInterface $exportedAt = null,
                    \Workflow\V2\Contracts\HistoryExportRedactor|callable|null $redactor = null,
                ): array {
                    return [];
                }

                public function dashboardSummary(?\Carbon\CarbonInterface $now = null, ?string $namespace = null): array
                {
                    return [
                        'flows' => 0,
                        'flows_per_minute' => 0,
                        'flows_past_hour' => 0,
                        'exceptions_past_hour' => 0,
                        'failed_flows_past_week' => 0,
                        'max_wait_time_workflow' => null,
                        'max_duration_workflow' => null,
                        'max_exceptions_workflow' => null,
                        'operator_metrics' => [
                            'workers' => [
                                'compatibility_namespace' => 'fleet-global',
                                'required_compatibility' => 'build-a',
                                'active_workers' => 4,
                                'active_worker_scopes' => 6,
                                'active_workers_supporting_required' => 3,
                                'fleet' => [
                                    [
                                        'worker_id' => 'engine-fleet-1',
                                        'namespace' => 'fleet-global',
                                        'connection' => 'redis',
                                        'queue' => 'engine-default',
                                        'supported' => ['build-a'],
                                        'supports_required' => true,
                                    ],
                                ],
                            ],
                        ],
                    ];
                }

                public function metrics(?\Carbon\CarbonInterface $now = null, ?string $namespace = null): array
                {
                    return [];
                }
            }
        );

        $this->get('/waterline/api/stats')
            ->assertOk()
            ->assertJsonPath('operator_metrics.workers.compatibility_namespace', 'fleet-global')
            ->assertJsonPath('operator_metrics.workers.required_compatibility', 'build-a')
            ->assertJsonPath('operator_metrics.workers.active_workers', 4)
            ->assertJsonPath('operator_metrics.workers.active_worker_scopes', 6)
            ->assertJsonPath('operator_metrics.workers.active_workers_supporting_required', 3)
            ->assertJsonCount(1, 'operator_metrics.workers.fleet')
            ->assertJsonPath('operator_metrics.workers.fleet.0.worker_id', 'engine-fleet-1')
            ->assertJsonPath('operator_metrics.workers.fleet.0.queue', 'engine-default')
            ->assertJsonMissing(['worker_id' => 'worker-rebuild-only']);
    }

    public function testIndexRebuildsWorkerFleetWhenEngineSuppliesForeignNamespaceScope(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'billing');
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()->set('workflows.v2.compatibility.supported', ['build-a']);

        WorkerCompatibilityFleet::clear();
        $this->beforeApplicationDestroyed(static function (): void {
            WorkerCompatibilityFleet::clear();
        });

        // Recorded under the configured namespace so the rebuild path produces a
        // deterministic fleet entry; the engine response below intentionally
        // disagrees on namespace, and Waterline must trust the configured value.
        WorkerCompatibilityFleet::recordForNamespace(
            namespace: 'billing',
            supported: ['build-a'],
            connection: 'redis',
            queue: 'default',
            workerId: 'worker-billing-rebuilt',
        );

        $this->app->instance(
            OperatorObservabilityRepository::class,
            new class implements OperatorObservabilityRepository
            {
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
                    ?\Carbon\CarbonInterface $exportedAt = null,
                    \Workflow\V2\Contracts\HistoryExportRedactor|callable|null $redactor = null,
                ): array {
                    return [];
                }

                public function dashboardSummary(?\Carbon\CarbonInterface $now = null, ?string $namespace = null): array
                {
                    return [
                        'flows' => 0,
                        'flows_per_minute' => 0,
                        'flows_past_hour' => 0,
                        'exceptions_past_hour' => 0,
                        'failed_flows_past_week' => 0,
                        'max_wait_time_workflow' => null,
                        'max_duration_workflow' => null,
                        'max_exceptions_workflow' => null,
                        'operator_metrics' => [
                            'workers' => [
                                'compatibility_namespace' => 'shipping',
                                'required_compatibility' => 'build-z',
                                'active_workers' => 99,
                                'active_worker_scopes' => 99,
                                'active_workers_supporting_required' => 99,
                                'fleet' => [
                                    [
                                        'worker_id' => 'engine-shipping-1',
                                        'namespace' => 'shipping',
                                        'connection' => 'redis',
                                        'queue' => 'engine-shipping-queue',
                                        'supported' => ['build-z'],
                                        'supports_required' => true,
                                    ],
                                ],
                            ],
                        ],
                    ];
                }

                public function metrics(?\Carbon\CarbonInterface $now = null, ?string $namespace = null): array
                {
                    return [];
                }
            }
        );

        $this->get('/waterline/api/stats')
            ->assertOk()
            ->assertJsonPath('operator_metrics.workers.compatibility_namespace', 'billing')
            ->assertJsonPath('operator_metrics.workers.required_compatibility', 'build-a')
            ->assertJsonPath('operator_metrics.workers.active_workers', 1)
            ->assertJsonPath('operator_metrics.workers.active_worker_scopes', 1)
            ->assertJsonPath('operator_metrics.workers.active_workers_supporting_required', 1)
            ->assertJsonCount(1, 'operator_metrics.workers.fleet')
            ->assertJsonPath('operator_metrics.workers.fleet.0.worker_id', 'worker-billing-rebuilt')
            ->assertJsonPath('operator_metrics.workers.fleet.0.namespace', 'billing')
            ->assertJsonPath('operator_metrics.workers.fleet.0.queue', 'default')
            ->assertJsonPath('operator_metrics.workers.fleet.0.supports_required', true)
            ->assertJsonMissing(['worker_id' => 'engine-shipping-1']);
    }

    public function testIndexSurfacesHostProcessAndHeartbeatTimestampsOnNamespaceScopedFleet(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'billing');
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()->set('workflows.v2.compatibility.supported', ['build-a']);
        config()->set('workflows.v2.compatibility.heartbeat_ttl_seconds', 30);

        WorkerCompatibilityFleet::clear();
        $this->beforeApplicationDestroyed(static function (): void {
            WorkerCompatibilityFleet::clear();
        });

        $recordedAt = now();
        $expiresAt = $recordedAt->copy()->addSeconds(30);

        WorkerCompatibilityFleet::recordForNamespace(
            namespace: 'billing',
            supported: ['build-a'],
            connection: 'redis',
            queue: 'default',
            workerId: 'worker-billing-correlated',
        );

        $rawHost = gethostname();
        $expectedHost = is_string($rawHost) && trim($rawHost) !== '' ? trim($rawHost) : null;
        $expectedProcessId = (string) getmypid();

        $response = $this->get('/waterline/api/stats')
            ->assertOk()
            ->assertJsonPath('operator_metrics.workers.compatibility_namespace', 'billing')
            ->assertJsonCount(1, 'operator_metrics.workers.fleet')
            ->assertJsonPath('operator_metrics.workers.fleet.0.worker_id', 'worker-billing-correlated')
            ->assertJsonPath('operator_metrics.workers.fleet.0.host', $expectedHost)
            ->assertJsonPath('operator_metrics.workers.fleet.0.process_id', $expectedProcessId)
            ->assertJsonPath('operator_metrics.workers.fleet.0.source', 'database');

        $entry = $response->json('operator_metrics.workers.fleet.0');
        $this->assertIsArray($entry);
        $this->assertArrayHasKey('recorded_at', $entry);
        $this->assertArrayHasKey('expires_at', $entry);
        $this->assertIsString($entry['recorded_at']);
        $this->assertIsString($entry['expires_at']);
        $this->assertTrue(\Illuminate\Support\Carbon::parse($entry['recorded_at'])->equalTo($recordedAt));
        $this->assertTrue(\Illuminate\Support\Carbon::parse($entry['expires_at'])->equalTo($expiresAt));
    }

    public function testIndexRebuildEmptiesFleetWhenConfiguredNamespaceUnpopulatedDespiteForeignEngineFleet(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'billing');
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()->set('workflows.v2.compatibility.supported', ['build-a']);

        WorkerCompatibilityFleet::clear();
        $this->beforeApplicationDestroyed(static function (): void {
            WorkerCompatibilityFleet::clear();
        });

        // Recorded under foreign namespaces only — the rebuild path filters by
        // the configured billing namespace, so neither of these may surface.
        WorkerCompatibilityFleet::recordForNamespace(
            namespace: 'shipping',
            supported: ['build-a'],
            connection: 'redis',
            queue: 'default',
            workerId: 'worker-shipping-fleet',
        );
        WorkerCompatibilityFleet::recordForNamespace(
            namespace: 'inventory',
            supported: ['build-a'],
            connection: 'redis',
            queue: 'priority',
            workerId: 'worker-inventory-fleet',
        );

        // Engine reports a foreign-scoped fleet with multiple entries and
        // non-zero counts. Because the engine namespace disagrees with the
        // configured billing namespace, scopedWorkerMetrics must rebuild from
        // WorkerCompatibilityFleet — and since billing has no recorded
        // workers, the rebuilt fleet must be empty and all engine entries
        // dropped wholesale.
        $this->app->instance(
            OperatorObservabilityRepository::class,
            new class implements OperatorObservabilityRepository
            {
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
                    ?\Carbon\CarbonInterface $exportedAt = null,
                    \Workflow\V2\Contracts\HistoryExportRedactor|callable|null $redactor = null,
                ): array {
                    return [];
                }

                public function dashboardSummary(?\Carbon\CarbonInterface $now = null, ?string $namespace = null): array
                {
                    return [
                        'flows' => 0,
                        'flows_per_minute' => 0,
                        'flows_past_hour' => 0,
                        'exceptions_past_hour' => 0,
                        'failed_flows_past_week' => 0,
                        'max_wait_time_workflow' => null,
                        'max_duration_workflow' => null,
                        'max_exceptions_workflow' => null,
                        'operator_metrics' => [
                            'workers' => [
                                'compatibility_namespace' => 'shipping',
                                'required_compatibility' => 'build-a',
                                'active_workers' => 5,
                                'active_worker_scopes' => 7,
                                'active_workers_supporting_required' => 4,
                                'fleet' => [
                                    [
                                        'worker_id' => 'engine-shipping-1',
                                        'namespace' => 'shipping',
                                        'connection' => 'redis',
                                        'queue' => 'engine-default',
                                        'supported' => ['build-a'],
                                        'supports_required' => true,
                                    ],
                                    [
                                        'worker_id' => 'engine-shipping-2',
                                        'namespace' => 'shipping',
                                        'connection' => 'redis',
                                        'queue' => 'engine-priority',
                                        'supported' => ['build-a'],
                                        'supports_required' => true,
                                    ],
                                    [
                                        'worker_id' => 'engine-shipping-3',
                                        'namespace' => 'shipping',
                                        'connection' => 'redis',
                                        'queue' => 'engine-bulk',
                                        'supported' => ['build-a'],
                                        'supports_required' => true,
                                    ],
                                ],
                            ],
                        ],
                    ];
                }

                public function metrics(?\Carbon\CarbonInterface $now = null, ?string $namespace = null): array
                {
                    return [];
                }
            }
        );

        $this->get('/waterline/api/stats')
            ->assertOk()
            ->assertJsonPath('operator_metrics.workers.compatibility_namespace', 'billing')
            ->assertJsonPath('operator_metrics.workers.required_compatibility', 'build-a')
            ->assertJsonPath('operator_metrics.workers.active_workers', 0)
            ->assertJsonPath('operator_metrics.workers.active_worker_scopes', 0)
            ->assertJsonPath('operator_metrics.workers.active_workers_supporting_required', 0)
            ->assertJsonPath('operator_metrics.workers.fleet', [])
            ->assertJsonMissing(['worker_id' => 'engine-shipping-1'])
            ->assertJsonMissing(['worker_id' => 'engine-shipping-2'])
            ->assertJsonMissing(['worker_id' => 'engine-shipping-3'])
            ->assertJsonMissing(['worker_id' => 'worker-shipping-fleet'])
            ->assertJsonMissing(['worker_id' => 'worker-inventory-fleet']);
    }

    public function testIndexPreservesEngineSuppliedCoordinationHealthFieldsOnNamespaceScopedFleet(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'billing');
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()->set('workflows.v2.compatibility.supported', ['build-a']);

        WorkerCompatibilityFleet::clear();
        $this->beforeApplicationDestroyed(static function (): void {
            WorkerCompatibilityFleet::clear();
        });

        // Recorded under the configured namespace with a distinct queue so that
        // if Waterline took the rebuild branch instead of the pass-through, the
        // surfaced fleet would name "rebuild-only" rather than the engine's
        // "engine-default" — and the engine-supplied coordination-health
        // fields below would be dropped.
        WorkerCompatibilityFleet::recordForNamespace(
            namespace: 'billing',
            supported: ['build-a'],
            connection: 'redis',
            queue: 'rebuild-only',
            workerId: 'worker-rebuild-only',
        );

        $this->app->instance(
            OperatorObservabilityRepository::class,
            new class implements OperatorObservabilityRepository
            {
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
                    ?\Carbon\CarbonInterface $exportedAt = null,
                    \Workflow\V2\Contracts\HistoryExportRedactor|callable|null $redactor = null,
                ): array {
                    return [];
                }

                public function dashboardSummary(?\Carbon\CarbonInterface $now = null, ?string $namespace = null): array
                {
                    return [
                        'flows' => 0,
                        'flows_per_minute' => 0,
                        'flows_past_hour' => 0,
                        'exceptions_past_hour' => 0,
                        'failed_flows_past_week' => 0,
                        'max_wait_time_workflow' => null,
                        'max_duration_workflow' => null,
                        'max_exceptions_workflow' => null,
                        'operator_metrics' => [
                            'workers' => [
                                'compatibility_namespace' => 'billing',
                                'required_compatibility' => 'build-a',
                                'active_workers' => 3,
                                'active_worker_scopes' => 4,
                                'active_workers_supporting_required' => 3,
                                // Coordination-health fields the engine may grow at
                                // the workers level. Pinning that pass-through preserves
                                // them lets workflow ship new operator surfaces without
                                // a coordinated Waterline change.
                                'wake_latency_ms_p95' => 42,
                                'queue_latency_ms_p95' => 17,
                                'lease_conflicts_past_hour' => 2,
                                'retry_rate_past_hour' => 0.125,
                                'duplicate_risk_indicators' => ['stale_lease', 'wake_dup'],
                                'routing_health' => [
                                    'status' => 'healthy',
                                    'blocked_cohorts' => [],
                                ],
                                'stuck_workflow_detector' => [
                                    'count' => 0,
                                    'oldest_age_seconds' => null,
                                ],
                                'fleet' => [
                                    [
                                        'worker_id' => 'engine-billing-1',
                                        'namespace' => 'billing',
                                        'connection' => 'redis',
                                        'queue' => 'engine-default',
                                        'supported' => ['build-a'],
                                        'supports_required' => true,
                                        // Per-fleet-entry coordination metadata the
                                        // engine may surface alongside heartbeat data.
                                        'lease_conflicts_past_hour' => 1,
                                        'last_wake_latency_ms' => 35,
                                    ],
                                ],
                            ],
                        ],
                    ];
                }

                public function metrics(?\Carbon\CarbonInterface $now = null, ?string $namespace = null): array
                {
                    return [];
                }
            }
        );

        $this->get('/waterline/api/stats')
            ->assertOk()
            ->assertJsonPath('operator_metrics.workers.compatibility_namespace', 'billing')
            ->assertJsonPath('operator_metrics.workers.required_compatibility', 'build-a')
            ->assertJsonPath('operator_metrics.workers.active_workers', 3)
            ->assertJsonPath('operator_metrics.workers.active_worker_scopes', 4)
            ->assertJsonPath('operator_metrics.workers.active_workers_supporting_required', 3)
            ->assertJsonPath('operator_metrics.workers.wake_latency_ms_p95', 42)
            ->assertJsonPath('operator_metrics.workers.queue_latency_ms_p95', 17)
            ->assertJsonPath('operator_metrics.workers.lease_conflicts_past_hour', 2)
            ->assertJsonPath('operator_metrics.workers.retry_rate_past_hour', 0.125)
            ->assertJsonPath('operator_metrics.workers.duplicate_risk_indicators', ['stale_lease', 'wake_dup'])
            ->assertJsonPath('operator_metrics.workers.routing_health.status', 'healthy')
            ->assertJsonPath('operator_metrics.workers.routing_health.blocked_cohorts', [])
            ->assertJsonPath('operator_metrics.workers.stuck_workflow_detector.count', 0)
            ->assertJsonPath('operator_metrics.workers.stuck_workflow_detector.oldest_age_seconds', null)
            ->assertJsonCount(1, 'operator_metrics.workers.fleet')
            ->assertJsonPath('operator_metrics.workers.fleet.0.worker_id', 'engine-billing-1')
            ->assertJsonPath('operator_metrics.workers.fleet.0.queue', 'engine-default')
            ->assertJsonPath('operator_metrics.workers.fleet.0.lease_conflicts_past_hour', 1)
            ->assertJsonPath('operator_metrics.workers.fleet.0.last_wake_latency_ms', 35)
            ->assertJsonMissing(['worker_id' => 'worker-rebuild-only']);
    }

    public function testIndexRebuildKeepsEngineSuppliedTopLevelCoordinationFieldsButDropsPerFleetEntryAnnotations(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'billing');
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()->set('workflows.v2.compatibility.supported', ['build-a']);

        WorkerCompatibilityFleet::clear();
        $this->beforeApplicationDestroyed(static function (): void {
            WorkerCompatibilityFleet::clear();
        });

        // The rebuild branch fires because the engine reports a foreign
        // namespace below, so the surfaced fleet must be derived from this
        // local recording — and engine-supplied per-entry annotations must
        // not bleed onto the rebuilt entry.
        WorkerCompatibilityFleet::recordForNamespace(
            namespace: 'billing',
            supported: ['build-a'],
            connection: 'redis',
            queue: 'default',
            workerId: 'worker-billing-rebuilt',
        );

        $this->app->instance(
            OperatorObservabilityRepository::class,
            new class implements OperatorObservabilityRepository
            {
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
                    ?\Carbon\CarbonInterface $exportedAt = null,
                    \Workflow\V2\Contracts\HistoryExportRedactor|callable|null $redactor = null,
                ): array {
                    return [];
                }

                public function dashboardSummary(?\Carbon\CarbonInterface $now = null, ?string $namespace = null): array
                {
                    return [
                        'flows' => 0,
                        'flows_per_minute' => 0,
                        'flows_past_hour' => 0,
                        'exceptions_past_hour' => 0,
                        'failed_flows_past_week' => 0,
                        'max_wait_time_workflow' => null,
                        'max_duration_workflow' => null,
                        'max_exceptions_workflow' => null,
                        'operator_metrics' => [
                            'workers' => [
                                // Foreign namespace forces the rebuild branch.
                                'compatibility_namespace' => 'shipping',
                                'required_compatibility' => 'build-z',
                                'active_workers' => 99,
                                'active_worker_scopes' => 99,
                                'active_workers_supporting_required' => 99,
                                // Top-level coordination fields the engine
                                // surfaces. The rebuild branch mutates the
                                // existing $workers array, so these keys must
                                // survive into the response even though the
                                // fleet itself is replaced.
                                'wake_latency_ms_p95' => 88,
                                'queue_latency_ms_p95' => 71,
                                'lease_conflicts_past_hour' => 5,
                                'retry_rate_past_hour' => 0.5,
                                'duplicate_risk_indicators' => ['stale_lease'],
                                'routing_health' => [
                                    'status' => 'degraded',
                                    'blocked_cohorts' => ['shipping'],
                                ],
                                'stuck_workflow_detector' => [
                                    'count' => 2,
                                    'oldest_age_seconds' => 600,
                                ],
                                'fleet' => [
                                    [
                                        'worker_id' => 'engine-shipping-1',
                                        'namespace' => 'shipping',
                                        'connection' => 'redis',
                                        'queue' => 'engine-default',
                                        'supported' => ['build-z'],
                                        'supports_required' => true,
                                        // Per-entry coordination metadata
                                        // that the rebuild path cannot carry
                                        // because it constructs entries from
                                        // local heartbeats only.
                                        'lease_conflicts_past_hour' => 9,
                                        'last_wake_latency_ms' => 123,
                                    ],
                                ],
                            ],
                        ],
                    ];
                }

                public function metrics(?\Carbon\CarbonInterface $now = null, ?string $namespace = null): array
                {
                    return [];
                }
            }
        );

        $response = $this->get('/waterline/api/stats')
            ->assertOk()
            // Rebuild reasserts the configured namespace and counts.
            ->assertJsonPath('operator_metrics.workers.compatibility_namespace', 'billing')
            ->assertJsonPath('operator_metrics.workers.required_compatibility', 'build-a')
            ->assertJsonPath('operator_metrics.workers.active_workers', 1)
            ->assertJsonPath('operator_metrics.workers.active_worker_scopes', 1)
            ->assertJsonPath('operator_metrics.workers.active_workers_supporting_required', 1)
            ->assertJsonCount(1, 'operator_metrics.workers.fleet')
            ->assertJsonPath('operator_metrics.workers.fleet.0.worker_id', 'worker-billing-rebuilt')
            ->assertJsonPath('operator_metrics.workers.fleet.0.namespace', 'billing')
            ->assertJsonPath('operator_metrics.workers.fleet.0.queue', 'default')
            ->assertJsonMissing(['worker_id' => 'engine-shipping-1'])
            // Top-level engine coordination fields survive the rebuild.
            ->assertJsonPath('operator_metrics.workers.wake_latency_ms_p95', 88)
            ->assertJsonPath('operator_metrics.workers.queue_latency_ms_p95', 71)
            ->assertJsonPath('operator_metrics.workers.lease_conflicts_past_hour', 5)
            ->assertJsonPath('operator_metrics.workers.retry_rate_past_hour', 0.5)
            ->assertJsonPath('operator_metrics.workers.duplicate_risk_indicators', ['stale_lease'])
            ->assertJsonPath('operator_metrics.workers.routing_health.status', 'degraded')
            ->assertJsonPath('operator_metrics.workers.routing_health.blocked_cohorts', ['shipping'])
            ->assertJsonPath('operator_metrics.workers.stuck_workflow_detector.count', 2)
            ->assertJsonPath('operator_metrics.workers.stuck_workflow_detector.oldest_age_seconds', 600);

        // Per-entry coordination annotations are NOT carried by the rebuild
        // path — fleetEntry produces a fixed shape, so any engine-supplied
        // per-entry keys are dropped.
        $entry = $response->json('operator_metrics.workers.fleet.0');
        $this->assertIsArray($entry);
        $this->assertArrayNotHasKey('lease_conflicts_past_hour', $entry);
        $this->assertArrayNotHasKey('last_wake_latency_ms', $entry);
    }

    public function testIndexLeavesWorkersNullWhenEngineOmitsSectionUnderConfiguredNamespace(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'billing');
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()->set('workflows.v2.compatibility.supported', ['build-a']);

        WorkerCompatibilityFleet::clear();
        $this->beforeApplicationDestroyed(static function (): void {
            WorkerCompatibilityFleet::clear();
        });

        // A heartbeat exists under the configured namespace. If
        // scopedWorkerMetrics ignored the early-return guard on a non-array
        // engine workers value, this entry would surface in the rebuilt
        // fleet — which would silently change the surface contract for
        // older workflow alphas that intentionally omit the workers section
        // until they catch up to the namespace-scoped snapshot shape.
        WorkerCompatibilityFleet::recordForNamespace(
            namespace: 'billing',
            supported: ['build-a'],
            connection: 'redis',
            queue: 'default',
            workerId: 'worker-billing-only',
        );

        $this->app->instance(
            OperatorObservabilityRepository::class,
            new class implements OperatorObservabilityRepository
            {
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
                    ?\Carbon\CarbonInterface $exportedAt = null,
                    \Workflow\V2\Contracts\HistoryExportRedactor|callable|null $redactor = null,
                ): array {
                    return [];
                }

                public function dashboardSummary(?\Carbon\CarbonInterface $now = null, ?string $namespace = null): array
                {
                    return [
                        'flows' => 0,
                        'flows_per_minute' => 0,
                        'flows_past_hour' => 0,
                        'exceptions_past_hour' => 0,
                        'failed_flows_past_week' => 0,
                        'max_wait_time_workflow' => null,
                        'max_duration_workflow' => null,
                        'max_exceptions_workflow' => null,
                        // operator_metrics intentionally omits the workers
                        // key — older workflow alphas surface only the runs
                        // metrics until they grow the namespace-scoped
                        // workers snapshot.
                        'operator_metrics' => [
                            'runs' => [
                                'oldest_repair_needed_at' => null,
                                'max_repair_needed_age_ms' => null,
                            ],
                        ],
                    ];
                }

                public function metrics(?\Carbon\CarbonInterface $now = null, ?string $namespace = null): array
                {
                    return [];
                }
            }
        );

        $response = $this->get('/waterline/api/stats')
            ->assertOk()
            // The early-return guard preserves the engine's non-array
            // workers value (here: omitted, becoming null) verbatim — it
            // must not be synthesised from local heartbeats just because a
            // namespace happens to be configured.
            ->assertJsonPath('operator_metrics.workers', null)
            ->assertJsonMissing(['worker_id' => 'worker-billing-only']);

        $operatorMetrics = $response->json('operator_metrics');
        $this->assertIsArray($operatorMetrics);
        $this->assertArrayHasKey('workers', $operatorMetrics);
        $this->assertNull($operatorMetrics['workers']);
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
                . 'vendored workflow package must resolve to >=2.0.0-alpha.27.',
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

    public function testIndexExposesBacklogFlowRates(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $response = $this->get('/waterline/api/stats')->assertStatus(200);

        $backlog = $response->json('operator_metrics.backlog');

        $this->assertIsArray($backlog);

        foreach (['tasks_added_last_minute', 'tasks_dispatched_last_minute'] as $key) {
            $this->assertArrayHasKey(
                $key,
                $backlog,
                sprintf('operator_metrics.backlog.%s must be present in the dashboard payload', $key),
            );
            $this->assertIsInt(
                $backlog[$key],
                sprintf('operator_metrics.backlog.%s must be an integer count', $key),
            );
        }
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

        $this->assertIsArray($runSummaries);
        $this->assertArrayHasKey('oldest_missing_run_started_at', $runSummaries);
        $this->assertArrayHasKey('max_missing_run_age_ms', $runSummaries);

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
        $this->assertArrayHasKey('wake_owner', $matchingRole);
        $this->assertArrayHasKey('shape', $matchingRole);
        $this->assertArrayHasKey('task_dispatch_mode', $matchingRole);
        $this->assertArrayHasKey('partition_primitives', $matchingRole);
        $this->assertArrayHasKey('backpressure_model', $matchingRole);

        $this->assertIsBool($matchingRole['queue_wake_enabled']);
        $this->assertSame(
            $matchingRole['queue_wake_enabled'] ? 'worker_loop' : 'dedicated_repair_pass',
            $matchingRole['wake_owner'],
            'operator_metrics.matching_role.wake_owner must track the queue-wake owner contract',
        );
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
        $this->assertSame(
            ['connection', 'queue', 'compatibility', 'namespace'],
            $matchingRole['partition_primitives'],
            'operator_metrics.matching_role.partition_primitives must preserve the frozen queue routing order',
        );
        $this->assertSame(
            'lease_ownership',
            $matchingRole['backpressure_model'],
            'operator_metrics.matching_role.backpressure_model must preserve the frozen matching-role backpressure contract',
        );

        $this->assertArrayHasKey('discovery_limits', $matchingRole);
        $this->assertIsArray($matchingRole['discovery_limits']);

        foreach ([
            'poll_batch_cap',
            'availability_ceiling_seconds',
            'wake_signal_ttl_seconds',
            'workflow_task_lease_seconds',
            'activity_task_lease_seconds',
        ] as $limitKey) {
            $this->assertArrayHasKey(
                $limitKey,
                $matchingRole['discovery_limits'],
                "operator_metrics.matching_role.discovery_limits.{$limitKey} must be exposed for the dashboard",
            );
            $this->assertIsInt(
                $matchingRole['discovery_limits'][$limitKey],
                "operator_metrics.matching_role.discovery_limits.{$limitKey} must be an integer matching-role contract value",
            );
            $this->assertGreaterThan(
                0,
                $matchingRole['discovery_limits'][$limitKey],
                "operator_metrics.matching_role.discovery_limits.{$limitKey} must be a positive matching-role contract value",
            );
        }
    }

    public function testIndexExposesDedicatedMatchingRoleWakeOwner(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('workflows.v2.matching_role.queue_wake_enabled', false);

        $this->get('/waterline/api/stats')
            ->assertStatus(200)
            ->assertJsonPath('operator_metrics.matching_role.queue_wake_enabled', false)
            ->assertJsonPath('operator_metrics.matching_role.wake_owner', 'dedicated_repair_pass');
    }

    public function testIndexExposesUnhealthyAgeRollup(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $response = $this->get('/waterline/api/stats')->assertStatus(200);

        $tasks = $response->json('operator_metrics.tasks');

        $this->assertIsArray($tasks);
        $this->assertArrayHasKey('oldest_unhealthy_at', $tasks);
        $this->assertArrayHasKey('max_unhealthy_age_ms', $tasks);

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
        $this->assertArrayHasKey('severity', $backend);

        $this->assertIsString($backend['severity']);
        $this->assertContains(
            $backend['severity'],
            ['ok', 'info', 'warning', 'error'],
            'operator_metrics.backend.severity must be one of ok, info, warning, error',
        );
    }

    public function testIndexExposesActivityTimeoutOverdueRollup(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $response = $this->get('/waterline/api/stats')->assertStatus(200);

        $activities = $response->json('operator_metrics.activities');

        $this->assertIsArray($activities);
        $this->assertArrayHasKey('timeout_overdue', $activities);
        $this->assertArrayHasKey('oldest_timeout_overdue_at', $activities);
        $this->assertArrayHasKey('max_timeout_overdue_age_ms', $activities);

        $this->assertIsInt($activities['timeout_overdue']);
        $this->assertGreaterThanOrEqual(0, $activities['timeout_overdue']);
        $this->assertTrue(
            $activities['oldest_timeout_overdue_at'] === null
                || is_string($activities['oldest_timeout_overdue_at']),
            'operator_metrics.activities.oldest_timeout_overdue_at must be null or ISO-8601 string',
        );
        $this->assertTrue(
            $activities['max_timeout_overdue_age_ms'] === null
                || is_int($activities['max_timeout_overdue_age_ms']),
            'operator_metrics.activities.max_timeout_overdue_age_ms must be null or integer milliseconds',
        );
    }

    public function testIndexExposesRunRepairNeededAge(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $response = $this->get('/waterline/api/stats')->assertStatus(200);

        $runs = $response->json('operator_metrics.runs');

        $this->assertIsArray($runs);

        $this->assertArrayHasKey('oldest_repair_needed_at', $runs);
        $this->assertArrayHasKey('max_repair_needed_age_ms', $runs);

        $this->assertTrue(
            $runs['oldest_repair_needed_at'] === null
                || is_string($runs['oldest_repair_needed_at']),
            'operator_metrics.runs.oldest_repair_needed_at must be null or ISO-8601 string',
        );
        $this->assertTrue(
            $runs['max_repair_needed_age_ms'] === null
                || is_int($runs['max_repair_needed_age_ms']),
            'operator_metrics.runs.max_repair_needed_age_ms must be null or integer milliseconds',
        );
    }

    public function testIndexTrustsEngineSuppliedCountsWhenNamespaceScopeMatches(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'billing');
        // Local config and heartbeats disagree with the engine on what the
        // required build is and how many workers support it. The early-return
        // contract says: once the engine's compatibility_namespace matches the
        // configured namespace, the engine's view wins — Waterline must not
        // second-guess the counts or the required build by re-deriving from
        // local state.
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()->set('workflows.v2.compatibility.supported', ['build-a']);

        WorkerCompatibilityFleet::clear();
        $this->beforeApplicationDestroyed(static function (): void {
            WorkerCompatibilityFleet::clear();
        });

        // Two local heartbeats under the configured namespace. If Waterline
        // ever re-derived counts on a namespace match, active_workers would be
        // 2 and the fleet would name these workers — neither must happen.
        WorkerCompatibilityFleet::recordForNamespace(
            namespace: 'billing',
            supported: ['build-a'],
            connection: 'redis',
            queue: 'default',
            workerId: 'worker-billing-local-1',
        );
        WorkerCompatibilityFleet::recordForNamespace(
            namespace: 'billing',
            supported: ['build-a'],
            connection: 'redis',
            queue: 'priority',
            workerId: 'worker-billing-local-2',
        );

        $this->app->instance(
            OperatorObservabilityRepository::class,
            new class implements OperatorObservabilityRepository
            {
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
                    ?\Carbon\CarbonInterface $exportedAt = null,
                    \Workflow\V2\Contracts\HistoryExportRedactor|callable|null $redactor = null,
                ): array {
                    return [];
                }

                public function dashboardSummary(?\Carbon\CarbonInterface $now = null, ?string $namespace = null): array
                {
                    return [
                        'flows' => 0,
                        'flows_per_minute' => 0,
                        'flows_past_hour' => 0,
                        'exceptions_past_hour' => 0,
                        'failed_flows_past_week' => 0,
                        'max_wait_time_workflow' => null,
                        'max_duration_workflow' => null,
                        'max_exceptions_workflow' => null,
                        'operator_metrics' => [
                            'workers' => [
                                // Namespace matches the configured value, so
                                // the early-return path fires.
                                'compatibility_namespace' => 'billing',
                                // Required build disagrees with the local
                                // compatibility.current ('build-a'). The
                                // pass-through must keep the engine's value.
                                'required_compatibility' => 'build-z',
                                // Counts disagree with what local heartbeats
                                // would yield (2 / 2 / 2). The pass-through
                                // must keep the engine's numbers.
                                'active_workers' => 11,
                                'active_worker_scopes' => 17,
                                'active_workers_supporting_required' => 4,
                                'fleet' => [
                                    [
                                        'worker_id' => 'engine-billing-only',
                                        'namespace' => 'billing',
                                        'connection' => 'redis',
                                        'queue' => 'engine-default',
                                        'supported' => ['build-z'],
                                        'supports_required' => true,
                                    ],
                                ],
                            ],
                        ],
                    ];
                }

                public function metrics(?\Carbon\CarbonInterface $now = null, ?string $namespace = null): array
                {
                    return [];
                }
            }
        );

        $this->get('/waterline/api/stats')
            ->assertOk()
            ->assertJsonPath('operator_metrics.workers.compatibility_namespace', 'billing')
            // Engine's required_compatibility survives even though it
            // disagrees with the locally-configured current build.
            ->assertJsonPath('operator_metrics.workers.required_compatibility', 'build-z')
            // Engine's counts survive verbatim — Waterline does not
            // re-derive them from the two local heartbeats above.
            ->assertJsonPath('operator_metrics.workers.active_workers', 11)
            ->assertJsonPath('operator_metrics.workers.active_worker_scopes', 17)
            ->assertJsonPath('operator_metrics.workers.active_workers_supporting_required', 4)
            ->assertJsonCount(1, 'operator_metrics.workers.fleet')
            ->assertJsonPath('operator_metrics.workers.fleet.0.worker_id', 'engine-billing-only')
            ->assertJsonPath('operator_metrics.workers.fleet.0.queue', 'engine-default')
            ->assertJsonPath('operator_metrics.workers.fleet.0.supported', ['build-z'])
            ->assertJsonPath('operator_metrics.workers.fleet.0.supports_required', true)
            // Local heartbeats must not bleed into the surface when the
            // engine has already supplied a namespace-matching snapshot.
            ->assertJsonMissing(['worker_id' => 'worker-billing-local-1'])
            ->assertJsonMissing(['worker_id' => 'worker-billing-local-2']);
    }

    public function testIndexRebuildCountsAllNamespaceWorkersAsSupportingWhenRequiredCompatibilityUnset(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'billing');
        // No compatibility marker is configured. The rebuild path must treat
        // this as the permissive "single-fleet, no pinning" posture documented
        // in the workflows config defaults — every namespace heartbeat
        // counts as a supporting worker, and required_compatibility surfaces
        // as null. Operators reading the dashboard during early bootstrap
        // (before any build marker has been assigned) need a single signal
        // that distinguishes "we have no marker configured" from "we have a
        // marker and zero workers support it" — the latter is a rollout-block
        // signal, the former is not.
        config()->set('workflows.v2.compatibility.current', null);
        config()->set('workflows.v2.compatibility.supported', null);

        WorkerCompatibilityFleet::clear();
        $this->beforeApplicationDestroyed(static function (): void {
            WorkerCompatibilityFleet::clear();
        });

        // Three billing-scoped workers, deliberately advertising disjoint and
        // even empty supported lists. Without a required marker, none of
        // those values can fail the check — every worker must report
        // supports_required=true and roll up into active_workers_supporting_required.
        WorkerCompatibilityFleet::recordForNamespace(
            namespace: 'billing',
            supported: ['build-a'],
            connection: 'redis',
            queue: 'default',
            workerId: 'worker-billing-a',
        );
        WorkerCompatibilityFleet::recordForNamespace(
            namespace: 'billing',
            supported: ['build-legacy'],
            connection: 'redis',
            queue: 'priority',
            workerId: 'worker-billing-legacy',
        );
        WorkerCompatibilityFleet::recordForNamespace(
            namespace: 'billing',
            supported: [],
            connection: 'redis',
            queue: 'background',
            workerId: 'worker-billing-empty',
        );
        // A foreign-namespace worker must stay out of the billing-scoped
        // fleet even when no required marker is configured — the namespace
        // filter is the contract that prevents one cohort's permissive view
        // from leaking into another's.
        WorkerCompatibilityFleet::recordForNamespace(
            namespace: 'shipping',
            supported: ['build-a'],
            connection: 'redis',
            queue: 'default',
            workerId: 'worker-shipping',
        );

        $this->get('/waterline/api/stats')
            ->assertOk()
            ->assertJsonPath('operator_metrics.workers.compatibility_namespace', 'billing')
            // Required marker passes through as null instead of being
            // synthesized into a placeholder string — Waterline must not
            // invent a marker when none is configured, otherwise dashboards
            // would mislead operators into thinking a rollout pin exists.
            ->assertJsonPath('operator_metrics.workers.required_compatibility', null)
            ->assertJsonPath('operator_metrics.workers.active_workers', 3)
            ->assertJsonPath('operator_metrics.workers.active_worker_scopes', 3)
            // The defining contract: with no required marker, every
            // namespace-scoped worker counts as supporting it.
            ->assertJsonPath('operator_metrics.workers.active_workers_supporting_required', 3)
            ->assertJsonCount(3, 'operator_metrics.workers.fleet')
            ->assertJsonFragment([
                'worker_id' => 'worker-billing-a',
                'namespace' => 'billing',
                'connection' => 'redis',
                'queue' => 'default',
                'supported' => ['build-a'],
                'supports_required' => true,
            ])
            ->assertJsonFragment([
                'worker_id' => 'worker-billing-legacy',
                'namespace' => 'billing',
                'connection' => 'redis',
                'queue' => 'priority',
                'supported' => ['build-legacy'],
                'supports_required' => true,
            ])
            ->assertJsonFragment([
                'worker_id' => 'worker-billing-empty',
                'namespace' => 'billing',
                'connection' => 'redis',
                'queue' => 'background',
                'supported' => [],
                'supports_required' => true,
            ])
            ->assertJsonMissing(['worker_id' => 'worker-shipping']);
    }
}
