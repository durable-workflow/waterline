<?php

namespace Waterline\Tests\Feature;

use Carbon\CarbonInterval;
use Waterline\Tests\TestCase;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
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
        config()->set('workflows.v2.task_repair.redispatch_after_seconds', 8);
        config()->set('workflows.v2.task_repair.loop_throttle_seconds', 12);
        config()->set('workflows.v2.task_repair.scan_limit', 16);
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

        WorkerCompatibilityFleet::record(['build-a'], 'redis', 'default', 'waterline-worker-a');

        $this->get('/waterline/api/stats')
            ->assertStatus(200)
            ->assertJsonPath('operator_metrics.runs.running', 1)
            ->assertJsonPath('operator_metrics.backlog.runnable_tasks', 1)
            ->assertJsonPath('operator_metrics.backlog.repair_needed_runs', 1)
            ->assertJsonPath('operator_metrics.starts.pending_runs', 1)
            ->assertJsonPath('operator_metrics.starts.pending_commands', 1)
            ->assertJsonPath('operator_metrics.starts.ready_tasks', 1)
            ->assertJsonPath('operator_metrics.history.continue_as_new_recommended_runs', 1)
            ->assertJsonPath('operator_metrics.history.max_event_count', 12)
            ->assertJsonPath('operator_metrics.history.event_threshold', 10)
            ->assertJsonPath('operator_metrics.workers.compatibility_namespace', 'waterline-metrics-test')
            ->assertJsonPath('operator_metrics.workers.required_compatibility', 'build-a')
            ->assertJsonPath('operator_metrics.workers.active_workers', 1)
            ->assertJsonPath('operator_metrics.workers.active_worker_scopes', 1)
            ->assertJsonPath('operator_metrics.workers.active_workers_supporting_required', 1)
            ->assertJsonPath('operator_metrics.backend.queue.connection', 'sync')
            ->assertJsonPath('operator_metrics.backend.queue.driver', 'sync')
            ->assertJsonPath('operator_metrics.backend.supported', false)
            ->assertJsonFragment(['code' => 'queue_sync_unsupported'])
            ->assertJsonPath('operator_metrics.repair_policy.redispatch_after_seconds', 8)
            ->assertJsonPath('operator_metrics.repair_policy.loop_throttle_seconds', 12)
            ->assertJsonPath('operator_metrics.repair_policy.scan_limit', 16);
    }
}
