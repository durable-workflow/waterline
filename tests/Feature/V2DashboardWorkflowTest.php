<?php

namespace Waterline\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Waterline\Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\RunSummaryProjector;

class V2DashboardWorkflowTest extends TestCase
{
    public function testShowReturnsV2CompatibilityPayload()
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => '01JTESTFLOWINSTANCE00000001',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUN000000000001',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'completed',
            'closed_reason' => 'completed',
            'arguments' => Serializer::serialize(['name' => 'Taylor']),
            'output' => Serializer::serialize(['ok' => true]),
            'connection' => 'redis',
            'queue' => 'default',
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
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => $run->started_at,
            'closed_at' => $run->closed_at,
            'duration_ms' => 300000,
            'exception_count' => 1,
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(5),
        ]);

        ActivityExecution::create([
            'id' => '01JTESTACTIVITY00000000000',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => 'ActivityClass',
            'activity_type' => 'activity.test',
            'status' => 'completed',
            'arguments' => Serializer::serialize(['Taylor']),
            'result' => Serializer::serialize('Hello, Taylor!'),
            'started_at' => now()->subMinutes(9),
            'closed_at' => now()->subMinutes(8),
        ]);

        WorkflowFailure::create([
            'id' => '01JTESTFAILURE000000000001',
            'workflow_run_id' => $run->id,
            'source_kind' => 'activity_execution',
            'source_id' => '01JTESTACTIVITY00000000000',
            'propagation_kind' => 'activity',
            'handled' => false,
            'exception_class' => \RuntimeException::class,
            'message' => 'boom',
            'file' => __FILE__,
            'line' => 42,
            'trace_preview' => 'trace',
        ]);

        $response = $this->get('/waterline/api/flows/' . $run->id);

        $response
            ->assertStatus(200)
            ->assertJsonPath('id', $run->id)
            ->assertJsonPath('instance_id', $instance->id)
            ->assertJsonPath('selected_run_id', $run->id)
            ->assertJsonPath('run_id', $run->id)
            ->assertJsonPath('is_current_run', true)
            ->assertJsonPath('current_run_id', $run->id)
            ->assertJsonPath('current_run_status', 'completed')
            ->assertJsonPath('current_run_status_bucket', 'completed')
            ->assertJsonPath('engine_source', 'v2')
            ->assertJsonPath('class', 'WorkflowClass')
            ->assertJsonPath('connection', 'redis')
            ->assertJsonPath('queue', 'default')
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('closed_reason', 'completed')
            ->assertJsonPath('closed_at', $run->closed_at?->jsonSerialize())
            ->assertJsonPath('duration_ms', 300000)
            ->assertJsonPath('exception_count', 1)
            ->assertJsonPath('exceptions_count', 1)
            ->assertJsonPath('can_issue_terminal_commands', false)
            ->assertJsonPath('can_repair', false)
            ->assertJsonPath('read_only_reason', 'Run is closed.')
            ->assertJsonPath('activities.0.class', 'ActivityClass')
            ->assertJsonPath('logs.0.class', 'ActivityClass')
            ->assertJsonPath('exceptions.0.class', 'ActivityClass')
            ->assertJsonPath('commands', [])
            ->assertJsonPath('timeline', [])
            ->assertJsonPath('chartData.0.type', 'Workflow')
            ->assertJsonPath('chartData.1.type', 'Activity');
    }

    public function testShowCanResolveCurrentRunFromInstanceId(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-123',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNCURRENT000001',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(2),
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
            'status' => 'waiting',
            'status_bucket' => 'running',
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => $run->started_at,
            'wait_kind' => 'signal',
            'wait_reason' => 'Waiting for signal approved-by',
            'wait_started_at' => now()->subSeconds(30),
            'next_task_at' => now()->subSeconds(5),
            'liveness_state' => 'waiting_for_signal',
            'liveness_reason' => 'Waiting for signal approved-by.',
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinute(),
        ]);

        $this->get('/waterline/api/flows/' . $instance->id)
            ->assertStatus(200)
            ->assertJsonPath('id', $run->id)
            ->assertJsonPath('instance_id', $instance->id)
            ->assertJsonPath('selected_run_id', $run->id)
            ->assertJsonPath('run_id', $run->id)
            ->assertJsonPath('current_run_id', $run->id)
            ->assertJsonPath('current_run_status', 'waiting')
            ->assertJsonPath('current_run_status_bucket', 'running')
            ->assertJsonPath('wait_kind', 'signal')
            ->assertJsonPath('wait_reason', 'Waiting for signal approved-by')
            ->assertJsonPath('liveness_state', 'waiting_for_signal')
            ->assertJsonPath('liveness_reason', 'Waiting for signal approved-by.')
            ->assertJsonPath('can_issue_terminal_commands', true)
            ->assertJsonPath('can_repair', false)
            ->assertJsonPath('read_only_reason', null);
    }

    public function testShowMarksExpiredLeasedTaskAsRepairNeeded(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-expired-lease',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNEXPIREDLEASE1',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(2),
            'last_progress_at' => now()->subMinute(),
        ]);

        $instance->update(['current_run_id' => $run->id]);

        WorkflowTask::create([
            'id' => '01JTESTFLOWTASKEXPIREDLEASE1',
            'workflow_run_id' => $run->id,
            'task_type' => 'workflow',
            'status' => 'leased',
            'queue' => 'default',
            'available_at' => now()->subSeconds(30),
            'leased_at' => now()->subSeconds(25),
            'lease_owner' => 'worker-1',
            'lease_expires_at' => now()->subSecond(),
            'last_dispatched_at' => now()->subSeconds(25),
            'attempt_count' => 1,
        ]);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->get('/waterline/api/flows/' . $run->id)
            ->assertStatus(200)
            ->assertJsonPath('wait_kind', 'workflow-task')
            ->assertJsonPath('liveness_state', 'repair_needed')
            ->assertJsonPath('can_repair', true)
            ->assertJsonPath('tasks.0.lease_expired', true)
            ->assertJsonPath('tasks.0.summary', 'Workflow task lease expired; waiting for recovery.');
    }

    public function testShowIncludesSelectedRunWaitsAndTasks(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-detail-waits',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNWAITTASK0001',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(2),
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
            'status' => 'waiting',
            'status_bucket' => 'running',
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => $run->started_at,
            'wait_kind' => 'signal',
            'wait_reason' => 'Waiting for signal approved-by',
            'wait_started_at' => now()->subSeconds(45),
            'liveness_state' => 'waiting_for_signal',
            'liveness_reason' => 'Waiting for signal approved-by.',
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinute(),
        ]);

        WorkflowTask::create([
            'id' => '01JTESTFLOWTASKWAITTASK0001',
            'workflow_run_id' => $run->id,
            'task_type' => 'workflow',
            'status' => 'completed',
            'queue' => 'default',
            'available_at' => now()->subMinutes(2),
            'leased_at' => now()->subMinutes(2),
            'lease_owner' => 'worker-1',
            'attempt_count' => 1,
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinute(),
        ]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYWAITTASK0001',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => 'SignalWaitOpened',
            'payload' => [
                'signal_name' => 'approved-by',
                'sequence' => 1,
            ],
            'recorded_at' => now()->subSeconds(45),
            'created_at' => now()->subSeconds(45),
            'updated_at' => now()->subSeconds(45),
        ]);

        $this->get('/waterline/api/flows/' . $run->id)
            ->assertStatus(200)
            ->assertJsonPath('waits_scope', 'selected_run')
            ->assertJsonPath('tasks_scope', 'selected_run')
            ->assertJsonPath('timeline_scope', 'selected_run')
            ->assertJsonPath('lineage_scope', 'selected_run')
            ->assertJsonPath('waits.0.kind', 'signal')
            ->assertJsonPath('waits.0.status', 'open')
            ->assertJsonPath('waits.0.target_name', 'approved-by')
            ->assertJsonPath('waits.0.task_backed', false)
            ->assertJsonPath('waits.0.external_only', true)
            ->assertJsonPath('tasks.0.type', 'workflow')
            ->assertJsonPath('tasks.0.status', 'completed')
            ->assertJsonPath('tasks.0.is_open', false);
    }

    public function testShowMarksReceivedSignalWithoutWorkflowTaskAsRepairNeeded(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-signal-repair-needed',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNSIGNALREPAIR1',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(2),
            'last_progress_at' => now()->subMinute(),
        ]);

        $instance->update(['current_run_id' => $run->id]);

        WorkflowCommand::create([
            'id' => '01JTESTCOMMANDSIGNALREPAIR01',
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'command_sequence' => 2,
            'command_type' => 'signal',
            'target_scope' => 'instance',
            'status' => 'accepted',
            'outcome' => 'signal_received',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'payload_codec' => Serializer::class,
            'payload' => Serializer::serialize([
                'name' => 'approved-by',
                'arguments' => ['Taylor'],
            ]),
            'accepted_at' => now()->subSeconds(30),
            'created_at' => now()->subSeconds(30),
            'updated_at' => now()->subSeconds(30),
        ]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYSIGNALWAIT001',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => 'SignalWaitOpened',
            'payload' => [
                'signal_name' => 'approved-by',
                'sequence' => 1,
            ],
            'recorded_at' => now()->subSeconds(45),
            'created_at' => now()->subSeconds(45),
            'updated_at' => now()->subSeconds(45),
        ]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYSIGNALRCVD001',
            'workflow_run_id' => $run->id,
            'sequence' => 2,
            'event_type' => 'SignalReceived',
            'payload' => [
                'workflow_command_id' => '01JTESTCOMMANDSIGNALREPAIR01',
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'signal_name' => 'approved-by',
            ],
            'workflow_command_id' => '01JTESTCOMMANDSIGNALREPAIR01',
            'recorded_at' => now()->subSeconds(30),
            'created_at' => now()->subSeconds(30),
            'updated_at' => now()->subSeconds(30),
        ]);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->get('/waterline/api/flows/' . $run->id)
            ->assertStatus(200)
            ->assertJsonPath('wait_kind', null)
            ->assertJsonPath('wait_reason', null)
            ->assertJsonPath('liveness_state', 'repair_needed')
            ->assertJsonPath('liveness_reason', 'Run is non-terminal but has no durable next-resume source.')
            ->assertJsonPath('can_repair', true)
            ->assertJsonPath('waits.0.kind', 'signal')
            ->assertJsonPath('waits.0.status', 'resolved')
            ->assertJsonPath('waits.0.source_status', 'received')
            ->assertJsonPath('waits.0.summary', 'Signal approved-by received.')
            ->assertJsonPath('waits.0.task_backed', false)
            ->assertJsonPath('waits.0.external_only', true)
            ->assertJsonPath('waits.0.resume_source_kind', 'signal')
            ->assertJsonPath('waits.0.command_sequence', 2)
            ->assertJsonPath('waits.0.command_outcome', 'signal_received');
    }

    public function testShowIncludesChildWaitAndChildWorkflowLineage(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $parentInstance = WorkflowInstance::create([
            'id' => 'order-detail-child-parent',
            'workflow_class' => 'ParentWorkflowClass',
            'workflow_type' => 'workflow.parent',
            'run_count' => 1,
        ]);

        $childInstance = WorkflowInstance::create([
            'id' => 'order-detail-child-run',
            'workflow_class' => 'ChildWorkflowClass',
            'workflow_type' => 'workflow.child',
            'run_count' => 1,
        ]);

        $parentRun = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNCHILDPARENT01',
            'workflow_instance_id' => $parentInstance->id,
            'run_number' => 1,
            'workflow_class' => 'ParentWorkflowClass',
            'workflow_type' => 'workflow.parent',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(4),
            'last_progress_at' => now()->subMinute(),
        ]);

        $childRun = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNCHILDCHILD001',
            'workflow_instance_id' => $childInstance->id,
            'run_number' => 1,
            'workflow_class' => 'ChildWorkflowClass',
            'workflow_type' => 'workflow.child',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(3),
            'last_progress_at' => now()->subMinutes(2),
        ]);

        $parentInstance->update(['current_run_id' => $parentRun->id]);
        $childInstance->update(['current_run_id' => $childRun->id]);

        WorkflowRunSummary::create([
            'id' => $childRun->id,
            'workflow_instance_id' => $childInstance->id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'ChildWorkflowClass',
            'workflow_type' => 'workflow.child',
            'status' => 'waiting',
            'status_bucket' => 'running',
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => $childRun->started_at,
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(2),
        ]);

        $link = WorkflowLink::create([
            'id' => '01JTESTFLOWLINKCHILDWAIT001',
            'link_type' => 'child_workflow',
            'sequence' => 1,
            'parent_workflow_instance_id' => $parentInstance->id,
            'parent_workflow_run_id' => $parentRun->id,
            'child_workflow_instance_id' => $childInstance->id,
            'child_workflow_run_id' => $childRun->id,
            'is_primary_parent' => true,
            'created_at' => now()->subSeconds(90),
            'updated_at' => now()->subSeconds(90),
        ]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYCHILDWAIT0001',
            'workflow_run_id' => $parentRun->id,
            'sequence' => 1,
            'event_type' => 'ChildWorkflowScheduled',
            'payload' => [
                'workflow_link_id' => $link->id,
                'sequence' => 1,
                'child_workflow_instance_id' => $childInstance->id,
                'child_workflow_run_id' => $childRun->id,
                'child_workflow_type' => $childRun->workflow_type,
                'child_workflow_class' => $childRun->workflow_class,
                'child_run_number' => $childRun->run_number,
            ],
            'recorded_at' => now()->subSeconds(90),
            'created_at' => now()->subSeconds(90),
            'updated_at' => now()->subSeconds(90),
        ]);

        RunSummaryProjector::project($parentRun->fresh());

        $this->get('/waterline/api/flows/' . $parentRun->id)
            ->assertStatus(200)
            ->assertJsonPath('wait_kind', 'child')
            ->assertJsonPath('wait_reason', 'Waiting for child workflow workflow.child')
            ->assertJsonPath('waits.0.kind', 'child')
            ->assertJsonPath('waits.0.status', 'open')
            ->assertJsonPath('waits.0.target_name', $childInstance->id)
            ->assertJsonPath('waits.0.target_type', 'workflow.child')
            ->assertJsonPath('waits.0.task_backed', false)
            ->assertJsonPath('waits.0.external_only', false)
            ->assertJsonPath('waits.0.resume_source_kind', 'child_workflow_run')
            ->assertJsonPath('waits.0.resume_source_id', $childRun->id)
            ->assertJsonPath('continuedWorkflows.0.link_type', 'child_workflow')
            ->assertJsonPath('continuedWorkflows.0.child_workflow_run_id', $childRun->id)
            ->assertJsonPath('continuedWorkflows.0.workflow_run_id', $childRun->id)
            ->assertJsonPath('continuedWorkflows.0.status', 'waiting')
            ->assertJsonPath('continuedWorkflows.0.status_bucket', 'running');

        $this->get('/waterline/api/flows/' . $childRun->id)
            ->assertStatus(200)
            ->assertJsonPath('parents.0.link_type', 'child_workflow')
            ->assertJsonPath('parents.0.parent_workflow_run_id', $parentRun->id)
            ->assertJsonPath('parents.0.workflow_run_id', $parentRun->id)
            ->assertJsonPath('parents.0.status', 'waiting')
            ->assertJsonPath('parents.0.status_bucket', 'running');
    }

    public function testShowExposesRepairNeededTimerWaitWithoutBackingTask(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-detail-repair-timer',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNREPAIRWAIT001',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(2),
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
            'status' => 'waiting',
            'status_bucket' => 'running',
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => $run->started_at,
            'wait_kind' => 'timer',
            'wait_reason' => 'Waiting for timer',
            'wait_started_at' => now()->subSeconds(30),
            'wait_deadline_at' => now()->addMinute(),
            'liveness_state' => 'repair_needed',
            'liveness_reason' => 'Timer 01JTESTFLOWTIMERREPAIR0001 is pending without an open timer task.',
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinute(),
        ]);

        WorkflowTask::create([
            'id' => '01JTESTFLOWTASKREPAIRWAIT01',
            'workflow_run_id' => $run->id,
            'task_type' => 'workflow',
            'status' => 'completed',
            'queue' => 'default',
            'available_at' => now()->subMinutes(2),
            'leased_at' => now()->subMinutes(2),
            'lease_owner' => 'worker-1',
            'attempt_count' => 1,
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinute(),
        ]);

        WorkflowTimer::create([
            'id' => '01JTESTFLOWTIMERREPAIR0001',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'status' => 'pending',
            'delay_seconds' => 60,
            'fire_at' => now()->addMinute(),
            'created_at' => now()->subSeconds(30),
            'updated_at' => now()->subSeconds(30),
        ]);

        $this->get('/waterline/api/flows/' . $run->id)
            ->assertStatus(200)
            ->assertJsonPath('can_repair', true)
            ->assertJsonPath('waits.0.kind', 'timer')
            ->assertJsonPath('waits.0.status', 'open')
            ->assertJsonPath('waits.0.task_backed', false)
            ->assertJsonPath('waits.0.task_id', null)
            ->assertJsonPath('waits.0.task_type', null)
            ->assertJsonPath('waits.0.task_status', null)
            ->assertJsonPath('tasks.0.type', 'workflow')
            ->assertJsonMissingPath('tasks.1');
    }

    public function testShowExposesHistoricalTimerTaskMetadataWhenOpenTimerWaitLostItsBackingTask(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-detail-stale-timer-task',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNSTALETIMER01',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(2),
            'last_progress_at' => now()->subMinute(),
        ]);

        $instance->update(['current_run_id' => $run->id]);

        $timer = WorkflowTimer::create([
            'id' => '01JTESTFLOWTIMERSTALE0001',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'status' => 'pending',
            'delay_seconds' => 60,
            'fire_at' => now()->addMinute(),
            'created_at' => now()->subSeconds(30),
            'updated_at' => now()->subSeconds(30),
        ]);

        $task = WorkflowTask::create([
            'id' => '01JTESTFLOWTASKSTALETIMER1',
            'workflow_run_id' => $run->id,
            'task_type' => 'timer',
            'status' => 'completed',
            'queue' => 'default',
            'payload' => ['timer_id' => $timer->id],
            'available_at' => now()->subMinutes(2),
            'leased_at' => now()->subMinutes(2),
            'attempt_count' => 1,
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinute(),
        ]);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->get('/waterline/api/flows/' . $run->id)
            ->assertStatus(200)
            ->assertJsonPath('wait_kind', 'timer')
            ->assertJsonPath('liveness_state', 'repair_needed')
            ->assertJsonPath('waits.0.kind', 'timer')
            ->assertJsonPath('waits.0.status', 'open')
            ->assertJsonPath('waits.0.task_backed', false)
            ->assertJsonPath('waits.0.task_id', $task->id)
            ->assertJsonPath('waits.0.task_type', 'timer')
            ->assertJsonPath('waits.0.task_status', 'completed')
            ->assertJsonPath('tasks.0.id', $task->id)
            ->assertJsonPath('tasks.0.status', 'completed');
    }

    public function testShowIncludesCompletedUpdateResultsInCommandHistory(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-update-command',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNUPDATECOMMAND1',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(2),
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
            'status' => 'waiting',
            'status_bucket' => 'running',
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => $run->started_at,
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinute(),
        ]);

        WorkflowCommand::create([
            'id' => '01JTESTCOMMANDUPDATECOMPLETE',
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'command_sequence' => 2,
            'command_type' => 'update',
            'target_scope' => 'instance',
            'source' => 'webhook',
            'context' => [
                'caller' => [
                    'type' => 'webhook',
                    'label' => 'Webhook',
                ],
                'auth' => [
                    'status' => 'not_configured',
                    'method' => 'none',
                ],
                'request' => [
                    'method' => 'POST',
                    'path' => '/webhooks/instances/order-update-command/updates/approve',
                    'route_name' => 'workflows.v2.update',
                    'fingerprint' => 'sha256:test-update-command',
                ],
            ],
            'status' => 'accepted',
            'outcome' => 'update_completed',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'payload_codec' => Serializer::class,
            'payload' => Serializer::serialize([
                'name' => 'approve',
                'arguments' => [true, 'waterline'],
            ]),
            'accepted_at' => now()->subSeconds(50),
            'applied_at' => now()->subSeconds(49),
            'created_at' => now()->subSeconds(50),
            'updated_at' => now()->subSeconds(49),
        ]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYUPDATECOMPLETE',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => 'UpdateCompleted',
            'payload' => [
                'workflow_command_id' => '01JTESTCOMMANDUPDATECOMPLETE',
                'update_name' => 'approve',
                'sequence' => 1,
                'result' => Serializer::serialize([
                    'approved' => true,
                    'events' => ['started', 'approved:yes:waterline'],
                ]),
            ],
            'workflow_command_id' => '01JTESTCOMMANDUPDATECOMPLETE',
            'recorded_at' => now()->subSeconds(49),
            'created_at' => now()->subSeconds(49),
            'updated_at' => now()->subSeconds(49),
        ]);

        $this->get('/waterline/api/flows/' . $run->id)
            ->assertStatus(200)
            ->assertJsonPath('can_update', true)
            ->assertJsonPath('can_signal', true)
            ->assertJsonPath('commands.0.type', 'update')
            ->assertJsonPath('commands.0.target_name', 'approve')
            ->assertJsonPath('commands.0.source', 'webhook')
            ->assertJsonPath('commands.0.caller_label', 'Webhook')
            ->assertJsonPath('commands.0.auth_status', 'not_configured')
            ->assertJsonPath('commands.0.auth_method', 'none')
            ->assertJsonPath('commands.0.request_method', 'POST')
            ->assertJsonPath('commands.0.request_path', '/webhooks/instances/order-update-command/updates/approve')
            ->assertJsonPath('commands.0.request_route_name', 'workflows.v2.update')
            ->assertJsonPath('commands.0.request_fingerprint', 'sha256:test-update-command')
            ->assertJsonPath('commands.0.result_available', true)
            ->assertJsonPath('commands.0.failure_id', null)
            ->assertJsonPath('commands.0.failure_message', null)
            ->assertJsonPath('commands.0.completed_at', now()->subSeconds(49)->jsonSerialize())
            ->assertJsonPath('commands.0.result', serialize([
                'approved' => true,
                'events' => ['started', 'approved:yes:waterline'],
            ]));
    }

    public function testShowOrdersCommandsByDurableCommandSequence(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-command-sequence',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNCOMMANDSEQ01',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(2),
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
            'status' => 'waiting',
            'status_bucket' => 'running',
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => $run->started_at,
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinute(),
        ]);

        WorkflowCommand::create([
            'id' => '01JTESTCOMMANDSEQUENCE0002',
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'command_sequence' => 2,
            'command_type' => 'signal',
            'target_scope' => 'instance',
            'status' => 'accepted',
            'outcome' => 'signal_received',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'payload_codec' => Serializer::class,
            'payload' => Serializer::serialize([
                'name' => 'approved-by',
                'arguments' => ['Taylor'],
            ]),
            'accepted_at' => now()->subMinutes(2),
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);

        WorkflowCommand::create([
            'id' => '01JTESTCOMMANDSEQUENCE0001',
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'command_sequence' => 1,
            'command_type' => 'start',
            'target_scope' => 'instance',
            'status' => 'accepted',
            'outcome' => 'started_new',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'payload_codec' => Serializer::class,
            'payload' => Serializer::serialize([]),
            'accepted_at' => now()->subMinute(),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $this->get('/waterline/api/flows/' . $run->id)
            ->assertStatus(200)
            ->assertJsonPath('commands.0.sequence', 1)
            ->assertJsonPath('commands.0.type', 'start')
            ->assertJsonPath('commands.1.sequence', 2)
            ->assertJsonPath('commands.1.type', 'signal')
            ->assertJsonPath('commands.1.target_name', 'approved-by');
    }

    public function testShowMarksRepairableCurrentRun(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-repairable',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNREPAIRABLE01',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(2),
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
            'status' => 'waiting',
            'status_bucket' => 'running',
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => $run->started_at,
            'liveness_state' => 'repair_needed',
            'liveness_reason' => 'Run is non-terminal but has no durable next-resume source.',
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinute(),
        ]);

        $this->get('/waterline/api/flows/' . $instance->id)
            ->assertStatus(200)
            ->assertJsonPath('id', $run->id)
            ->assertJsonPath('can_issue_terminal_commands', true)
            ->assertJsonPath('can_repair', true)
            ->assertJsonPath('liveness_state', 'repair_needed')
            ->assertJsonPath('read_only_reason', null);
    }

    public function testShowMarksRunningActivityWithoutTaskAsNonRepairable(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-running-activity',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNRUNNINGACT01',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(2),
            'last_progress_at' => now()->subSeconds(30),
        ]);

        $instance->update(['current_run_id' => $run->id]);

        $execution = ActivityExecution::create([
            'id' => '01JTESTACTIVITYRUNNING00001',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => 'ActivityClass',
            'activity_type' => 'activity.test',
            'status' => 'running',
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'activities',
            'started_at' => now()->subSeconds(20),
        ]);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->get('/waterline/api/flows/' . $instance->id)
            ->assertStatus(200)
            ->assertJsonPath('id', $run->id)
            ->assertJsonPath('wait_kind', 'activity')
            ->assertJsonPath('liveness_state', 'activity_running_without_task')
            ->assertJsonPath(
                'liveness_reason',
                sprintf(
                    'Activity %s is already running without an open activity task. Repair is deferred to avoid duplicating in-flight work.',
                    $execution->id,
                ),
            )
            ->assertJsonPath('can_issue_terminal_commands', true)
            ->assertJsonPath('can_repair', false)
            ->assertJsonPath('waits.0.kind', 'activity')
            ->assertJsonPath('waits.0.status', 'open')
            ->assertJsonPath('waits.0.source_status', 'running')
            ->assertJsonPath('waits.0.task_backed', false)
            ->assertJsonPath('waits.0.resume_source_kind', 'activity_execution')
            ->assertJsonPath('waits.0.resume_source_id', $execution->id)
            ->assertJsonPath('tasks', []);
    }

    public function testShowHistoricalRunIncludesPointerToCurrentRun(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-historical',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 2,
        ]);

        $historicalRun = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNHISTORY00001',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'completed',
            'closed_reason' => 'completed',
            'arguments' => Serializer::serialize(['step' => 1]),
            'output' => Serializer::serialize(['ok' => true]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(10),
            'closed_at' => now()->subMinutes(8),
            'last_progress_at' => now()->subMinutes(8),
        ]);

        $currentRun = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNCURRENT00002',
            'workflow_instance_id' => $instance->id,
            'run_number' => 2,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'arguments' => Serializer::serialize(['step' => 2]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(2),
            'last_progress_at' => now()->subMinute(),
        ]);

        $instance->update(['current_run_id' => $currentRun->id]);

        WorkflowRunSummary::create([
            'id' => $historicalRun->id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'is_current_run' => false,
            'engine_source' => 'v2',
            'class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'completed',
            'status_bucket' => 'completed',
            'closed_reason' => 'completed',
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => $historicalRun->started_at,
            'closed_at' => $historicalRun->closed_at,
            'duration_ms' => 120000,
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(8),
        ]);

        WorkflowRunSummary::create([
            'id' => $currentRun->id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 2,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'status_bucket' => 'running',
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => $currentRun->started_at,
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinute(),
        ]);

        $this->get('/waterline/api/flows/' . $historicalRun->id)
            ->assertStatus(200)
            ->assertJsonPath('id', $historicalRun->id)
            ->assertJsonPath('selected_run_id', $historicalRun->id)
            ->assertJsonPath('run_id', $historicalRun->id)
            ->assertJsonPath('is_current_run', false)
            ->assertJsonPath('current_run_id', $currentRun->id)
            ->assertJsonPath('current_run_status', 'waiting')
            ->assertJsonPath('current_run_status_bucket', 'running')
            ->assertJsonPath('can_issue_terminal_commands', false)
            ->assertJsonPath('can_repair', false)
            ->assertJsonPath('read_only_reason', 'Selected run is historical. Issue commands against the current active run.');
    }

    public function testShowIncludesContinueAsNewLineageForHistoricalAndCurrentRuns(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-continued',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 2,
        ]);

        $historicalRun = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNCONTINUED0001',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'completed',
            'closed_reason' => 'continued',
            'arguments' => Serializer::serialize(['step' => 1]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(10),
            'closed_at' => now()->subMinutes(9),
            'last_progress_at' => now()->subMinutes(9),
        ]);

        $currentRun = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNCONTINUED0002',
            'workflow_instance_id' => $instance->id,
            'run_number' => 2,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'completed',
            'closed_reason' => 'completed',
            'arguments' => Serializer::serialize(['step' => 2]),
            'output' => Serializer::serialize(['ok' => true]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(8),
            'closed_at' => now()->subMinutes(7),
            'last_progress_at' => now()->subMinutes(7),
        ]);

        $instance->update(['current_run_id' => $currentRun->id]);

        WorkflowRunSummary::create([
            'id' => $historicalRun->id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'is_current_run' => false,
            'engine_source' => 'v2',
            'class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'completed',
            'status_bucket' => 'completed',
            'closed_reason' => 'continued',
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => $historicalRun->started_at,
            'closed_at' => $historicalRun->closed_at,
            'duration_ms' => 60000,
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(9),
        ]);

        WorkflowRunSummary::create([
            'id' => $currentRun->id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 2,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'completed',
            'status_bucket' => 'completed',
            'closed_reason' => 'completed',
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => $currentRun->started_at,
            'closed_at' => $currentRun->closed_at,
            'duration_ms' => 60000,
            'created_at' => now()->subMinutes(8),
            'updated_at' => now()->subMinutes(7),
        ]);

        WorkflowLink::create([
            'id' => '01JTESTFLOWLINKCONTINUED001',
            'link_type' => 'continue_as_new',
            'parent_workflow_instance_id' => $instance->id,
            'parent_workflow_run_id' => $historicalRun->id,
            'child_workflow_instance_id' => $instance->id,
            'child_workflow_run_id' => $currentRun->id,
            'is_primary_parent' => true,
        ]);

        $this->get('/waterline/api/flows/' . $historicalRun->id)
            ->assertStatus(200)
            ->assertJsonPath('closed_reason', 'continued')
            ->assertJsonPath('continuedWorkflows.0.link_type', 'continue_as_new')
            ->assertJsonPath('continuedWorkflows.0.child_workflow_run_id', $currentRun->id)
            ->assertJsonPath('continuedWorkflows.0.status', 'completed')
            ->assertJsonPath('continuedWorkflows.0.status_bucket', 'completed');

        $this->get('/waterline/api/flows/' . $currentRun->id)
            ->assertStatus(200)
            ->assertJsonPath('parents.0.link_type', 'continue_as_new')
            ->assertJsonPath('parents.0.parent_workflow_run_id', $historicalRun->id)
            ->assertJsonPath('parents.0.status', 'completed')
            ->assertJsonPath('parents.0.status_bucket', 'completed');
    }

    public function testCancelTargetsSelectedCurrentRunAndReturnsAcceptedResponse(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-cancel-current',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNCANCEL000001',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subMinute(),
        ]);

        $instance->update(['current_run_id' => $run->id]);

        $response = $this->post('/waterline/api/flows/' . $instance->id . '/cancel');

        $response
            ->assertStatus(200)
            ->assertJsonPath('outcome', 'cancelled')
            ->assertJsonPath('workflow_id', $instance->id)
            ->assertJsonPath('run_id', $run->id)
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('command_source', 'waterline')
            ->assertJsonPath('rejection_reason', null);

        $commandId = $response->json('command_id');

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $commandId,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'command_type' => 'cancel',
            'source' => 'waterline',
            'target_scope' => 'run',
            'status' => 'accepted',
            'outcome' => 'cancelled',
        ]);

        $command = WorkflowCommand::query()->findOrFail($commandId);

        $this->assertSame('Waterline UI', $command->callerLabel());
        $this->assertSame('authorized', $command->authStatus());
        $this->assertSame('waterline', $command->authMethod());
        $this->assertSame('POST', $command->requestMethod());
        $this->assertSame('/waterline/api/flows/'.$instance->id.'/cancel', $command->requestPath());
        $this->assertSame('waterline.cancel', $command->requestRouteName());
    }

    public function testRepairTargetsSelectedCurrentRunAndReturnsAcceptedResponse(): void
    {
        config()->set('waterline.engine_source', 'v2');
        Queue::fake();

        $instance = WorkflowInstance::create([
            'id' => 'order-repair-current',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNREPAIRCURR01',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subMinute(),
        ]);

        $instance->update(['current_run_id' => $run->id]);

        $response = $this->post('/waterline/api/flows/' . $instance->id . '/repair');

        $response
            ->assertStatus(200)
            ->assertJsonPath('outcome', 'repair_dispatched')
            ->assertJsonPath('workflow_id', $instance->id)
            ->assertJsonPath('run_id', $run->id)
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('rejection_reason', null);

        $commandId = $response->json('command_id');

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $commandId,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'command_type' => 'repair',
            'target_scope' => 'run',
            'status' => 'accepted',
            'outcome' => 'repair_dispatched',
        ]);

        $this->assertDatabaseHas('workflow_tasks', [
            'workflow_run_id' => $run->id,
            'task_type' => 'workflow',
            'status' => 'ready',
            'repair_count' => 1,
        ]);
    }

    public function testRepairRedispatchesOverdueReadyWorkflowTaskAndClearsRepairNeededFromFlowDetail(): void
    {
        config()->set('waterline.engine_source', 'v2');
        Queue::fake();

        $instance = WorkflowInstance::create([
            'id' => 'order-repair-ready-task',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNREPAIRREADY01',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subSeconds(30),
        ]);

        $instance->update(['current_run_id' => $run->id]);

        $originalLastDispatchedAt = now()->subSeconds(20);

        $task = WorkflowTask::create([
            'workflow_run_id' => $run->id,
            'task_type' => 'workflow',
            'status' => 'ready',
            'payload' => [],
            'available_at' => now()->subSeconds(30),
            'last_dispatched_at' => $originalLastDispatchedAt,
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->get('/waterline/api/flows/' . $instance->id)
            ->assertStatus(200)
            ->assertJsonPath('liveness_state', 'repair_needed')
            ->assertJsonPath('can_repair', true)
            ->assertJsonPath('next_task_id', $task->id);

        $this->post('/waterline/api/flows/' . $instance->id . '/repair')
            ->assertStatus(200)
            ->assertJsonPath('outcome', 'repair_dispatched')
            ->assertJsonPath('workflow_id', $instance->id)
            ->assertJsonPath('run_id', $run->id)
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('rejection_reason', null);

        /** @var WorkflowTask $repairedTask */
        $repairedTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->sole();

        $this->assertSame($task->id, $repairedTask->id);
        $this->assertSame('workflow', $repairedTask->task_type->value);
        $this->assertSame('ready', $repairedTask->status->value);
        $this->assertSame(1, $repairedTask->repair_count);
        $this->assertNotNull($repairedTask->last_dispatched_at);
        $this->assertTrue($repairedTask->last_dispatched_at->gt($originalLastDispatchedAt));

        Queue::assertPushed(
            RunWorkflowTask::class,
            static fn (RunWorkflowTask $job): bool => $job->taskId === $repairedTask->id
        );

        $this->get('/waterline/api/flows/' . $instance->id)
            ->assertStatus(200)
            ->assertJsonPath('next_task_id', $repairedTask->id)
            ->assertJsonPath('liveness_state', 'workflow_task_ready')
            ->assertJsonPath('can_repair', false);
    }

    public function testTerminateRejectsHistoricalRunSelection(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-terminate-historical',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 2,
        ]);

        $historicalRun = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNTERMHIST0001',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'completed',
            'closed_reason' => 'completed',
            'arguments' => Serializer::serialize([]),
            'started_at' => now()->subMinutes(10),
            'closed_at' => now()->subMinutes(8),
            'last_progress_at' => now()->subMinutes(8),
        ]);

        $currentRun = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNTERMCURR0001',
            'workflow_instance_id' => $instance->id,
            'run_number' => 2,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subMinute(),
        ]);

        $instance->update(['current_run_id' => $currentRun->id]);

        WorkflowRunSummary::create([
            'id' => $historicalRun->id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'is_current_run' => false,
            'engine_source' => 'v2',
            'class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'completed',
            'status_bucket' => 'completed',
            'closed_reason' => 'completed',
            'started_at' => $historicalRun->started_at,
            'closed_at' => $historicalRun->closed_at,
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(8),
        ]);

        WorkflowRunSummary::create([
            'id' => $currentRun->id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 2,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'status_bucket' => 'running',
            'started_at' => $currentRun->started_at,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $response = $this->post('/waterline/api/flows/' . $historicalRun->id . '/terminate');

        $response
            ->assertStatus(409)
            ->assertJsonPath('outcome', 'rejected_not_current')
            ->assertJsonPath('workflow_id', $instance->id)
            ->assertJsonPath('run_id', $historicalRun->id)
            ->assertJsonPath('command_status', 'rejected')
            ->assertJsonPath('rejection_reason', 'selected_run_not_current');

        $commandId = $response->json('command_id');

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $commandId,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $historicalRun->id,
            'command_type' => 'terminate',
            'target_scope' => 'run',
            'status' => 'rejected',
            'outcome' => 'rejected_not_current',
            'rejection_reason' => 'selected_run_not_current',
        ]);
    }
}
