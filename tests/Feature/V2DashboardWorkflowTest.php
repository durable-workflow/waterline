<?php

namespace Waterline\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Waterline\Tests\TestCase;
use Workflow\Serializers\Serializer;
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
            ->assertJsonPath('rejection_reason', null);

        $commandId = $response->json('command_id');

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $commandId,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'command_type' => 'cancel',
            'target_scope' => 'run',
            'status' => 'accepted',
            'outcome' => 'cancelled',
        ]);
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
