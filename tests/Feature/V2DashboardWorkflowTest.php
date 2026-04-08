<?php

namespace Waterline\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Waterline\Tests\Fixtures\V2\TestCommandContractWorkflow;
use Waterline\Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
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
use Workflow\V2\Support\WorkflowInstanceId;
use Workflow\V2\TaskWatchdog;

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

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYFAILUREDETAIL01',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::ActivityFailed->value,
            'payload' => [
                'activity_execution_id' => '01JTESTACTIVITY00000000000',
                'activity_class' => 'ActivityClass',
                'activity_type' => 'activity.test',
                'sequence' => 1,
                'failure_id' => '01JTESTFAILURE000000000001',
                'exception_class' => \RuntimeException::class,
                'message' => 'boom',
                'exception' => [
                    'class' => \RuntimeException::class,
                    'message' => 'boom',
                    'code' => 422,
                    'file' => __FILE__,
                    'line' => 42,
                    'trace' => [[
                        'class' => 'Tests\\Fixtures\\Workflow',
                        'type' => '->',
                        'function' => 'handle',
                        'file' => __FILE__,
                        'line' => 99,
                    ]],
                    'properties' => [[
                        'declaring_class' => 'Tests\\Fixtures\\Workflow',
                        'name' => 'orderId',
                        'value' => 'order-123',
                    ]],
                ],
            ],
            'recorded_at' => now()->subMinutes(7),
        ]);

        $response = $this->get('/waterline/api/flows/' . $run->id);
        $exception = unserialize($response->json('exceptions.0.exception'));

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
            ->assertJsonPath('declared_signals', [])
            ->assertJsonPath('declared_updates', [])
            ->assertJsonPath('can_issue_terminal_commands', false)
            ->assertJsonPath('can_repair', false)
            ->assertJsonPath('read_only_reason', 'Run is closed.')
            ->assertJsonPath('activities.0.class', 'ActivityClass')
            ->assertJsonPath('logs.0.class', 'ActivityClass')
            ->assertJsonPath('exceptions.0.class', 'ActivityClass')
            ->assertJsonPath('exceptions.0.code', 'trace')
            ->assertJsonPath('commands', [])
            ->assertJsonPath('timeline.0.type', 'ActivityFailed')
            ->assertJsonPath('timeline.0.entry_kind', 'point')
            ->assertJsonPath('timeline.0.source_kind', 'activity_execution')
            ->assertJsonPath('timeline.0.source_id', '01JTESTACTIVITY00000000000')
            ->assertJsonPath('timeline.0.failure_id', '01JTESTFAILURE000000000001')
            ->assertJsonPath('timeline.0.activity_status', 'failed')
            ->assertJsonPath('timeline.0.failure.handled', false)
            ->assertJsonPath('chartData.0.type', 'Workflow')
            ->assertJsonPath('chartData.1.type', 'Activity');

        $this->assertSame(\RuntimeException::class, $exception['__constructor']);
        $this->assertSame('boom', $exception['message']);
        $this->assertSame(422, $exception['code']);
        $this->assertCount(1, $exception['trace']);
        $this->assertSame('handle', $exception['trace'][0]['function']);
        $this->assertSame('orderId', $exception['properties'][0]['name']);
        $this->assertSame('order-123', $exception['properties'][0]['value']);
    }

    public function testShowReturnsSideEffectTimelineEntries(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-side-effect-history',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNSIDEEFFECT001',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subMinute(),
        ]);

        $instance->update(['current_run_id' => $run->id]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYSIDEEFFECT001A',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::StartAccepted->value,
            'payload' => [],
            'recorded_at' => now()->subMinute(),
        ]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYSIDEEFFECT001B',
            'workflow_run_id' => $run->id,
            'sequence' => 2,
            'event_type' => HistoryEventType::WorkflowStarted->value,
            'payload' => [],
            'recorded_at' => now()->subSeconds(55),
        ]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYSIDEEFFECT001C',
            'workflow_run_id' => $run->id,
            'sequence' => 3,
            'event_type' => HistoryEventType::SideEffectRecorded->value,
            'payload' => [
                'sequence' => 1,
                'result' => Serializer::serialize(1),
            ],
            'recorded_at' => now()->subSeconds(50),
        ]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYSIDEEFFECT001D',
            'workflow_run_id' => $run->id,
            'sequence' => 4,
            'event_type' => HistoryEventType::SignalWaitOpened->value,
            'payload' => [
                'sequence' => 2,
                'signal_name' => 'finish',
                'signal_wait_id' => 'signal-wait-side-effect',
            ],
            'recorded_at' => now()->subSeconds(45),
        ]);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $response = $this->get('/waterline/api/instances/' . $instance->id);

        $response
            ->assertOk()
            ->assertJsonPath('instance_id', $instance->id)
            ->assertJsonPath('timeline.2.type', 'SideEffectRecorded')
            ->assertJsonPath('timeline.2.kind', 'side_effect')
            ->assertJsonPath('timeline.2.source_kind', 'workflow_run')
            ->assertJsonPath('timeline.2.source_id', $run->id)
            ->assertJsonPath('timeline.2.workflow_sequence', 1)
            ->assertJsonPath('timeline.2.summary', 'Recorded side effect.')
            ->assertJsonPath('timeline.3.type', 'SignalWaitOpened')
            ->assertJsonPath('timeline.3.signal_wait_id', 'signal-wait-side-effect')
            ->assertJsonPath('timeline.3.workflow_sequence', 2);
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
            'open_wait_id' => 'signal-wait-1',
            'resume_source_kind' => 'signal',
            'next_task_at' => now()->subSeconds(5),
            'liveness_state' => 'waiting_for_signal',
            'liveness_reason' => 'Waiting for signal approved-by.',
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinute(),
        ]);

        $this->get('/waterline/api/instances/' . $instance->id)
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
            ->assertJsonPath('open_wait_id', 'signal-wait-1')
            ->assertJsonPath('resume_source_kind', 'signal')
            ->assertJsonPath('resume_source_id', null)
            ->assertJsonPath('liveness_state', 'waiting_for_signal')
            ->assertJsonPath('liveness_reason', 'Waiting for signal approved-by.')
            ->assertJsonPath('declared_signals', [])
            ->assertJsonPath('declared_updates', [])
            ->assertJsonPath('can_issue_terminal_commands', true)
            ->assertJsonPath('can_repair', false)
            ->assertJsonPath('read_only_reason', null)
            ->assertJsonPath('run_navigation.0.instance_id', $instance->id)
            ->assertJsonPath('run_navigation.0.run_id', $run->id)
            ->assertJsonPath('run_navigation.0.run_number', 1)
            ->assertJsonPath('run_navigation.0.is_current_run', true)
            ->assertJsonPath('run_navigation.0.is_selected_run', true);
    }

    public function testShowSelectionAcceptsLongRouteSafeInstanceId(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instanceId = 'tenant.alpha:' . str_repeat('z', WorkflowInstanceId::MAX_LENGTH - strlen('tenant.alpha:'));

        $instance = WorkflowInstance::create([
            'id' => $instanceId,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNLONGINSTANCE01',
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

        $this->get('/waterline/api/instances/' . $instanceId . '/runs/' . $run->id)
            ->assertStatus(200)
            ->assertJsonPath('id', $run->id)
            ->assertJsonPath('instance_id', $instanceId)
            ->assertJsonPath('selected_run_id', $run->id)
            ->assertJsonPath('run_id', $run->id)
            ->assertJsonPath('current_run_id', $run->id);
    }

    public function testShowCanResolveHistoricalSelectedRunFromCanonicalInstanceRoute(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-history',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 2,
        ]);

        $historicalRun = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNHISTORY000001',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'completed',
            'closed_reason' => 'continued',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(10),
            'closed_at' => now()->subMinutes(9),
            'last_progress_at' => now()->subMinutes(9),
        ]);

        $currentRun = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNHISTORY000002',
            'workflow_instance_id' => $instance->id,
            'run_number' => 2,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(2),
            'last_progress_at' => now()->subMinute(),
        ]);

        $instance->update([
            'current_run_id' => $currentRun->id,
            'run_count' => 2,
        ]);

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
            'status' => 'waiting',
            'status_bucket' => 'running',
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => $currentRun->started_at,
            'wait_kind' => 'signal',
            'wait_reason' => 'Waiting for signal approved-by',
            'wait_started_at' => now()->subSeconds(30),
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinute(),
        ]);

        $this->get('/waterline/api/instances/' . $instance->id . '/runs/' . $historicalRun->id)
            ->assertStatus(200)
            ->assertJsonPath('id', $historicalRun->id)
            ->assertJsonPath('instance_id', $instance->id)
            ->assertJsonPath('selected_run_id', $historicalRun->id)
            ->assertJsonPath('run_id', $historicalRun->id)
            ->assertJsonPath('is_current_run', false)
            ->assertJsonPath('current_run_id', $currentRun->id)
            ->assertJsonPath('current_run_status', 'waiting')
            ->assertJsonPath('current_run_status_bucket', 'running')
            ->assertJsonPath(
                'read_only_reason',
                'Selected run is historical. Issue commands against the current active run.',
            )
            ->assertJsonPath('run_navigation.0.run_id', $historicalRun->id)
            ->assertJsonPath('run_navigation.0.run_number', 1)
            ->assertJsonPath('run_navigation.0.closed_reason', 'continued')
            ->assertJsonPath('run_navigation.0.is_current_run', false)
            ->assertJsonPath('run_navigation.0.is_selected_run', true)
            ->assertJsonPath('run_navigation.1.run_id', $currentRun->id)
            ->assertJsonPath('run_navigation.1.run_number', 2)
            ->assertJsonPath('run_navigation.1.is_current_run', true)
            ->assertJsonPath('run_navigation.1.is_selected_run', false);
    }

    public function testInstanceDetailResolvesLatestRunWhenCurrentRunPointerIsMissing()
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-history-pointer-drift',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 2,
        ]);

        $historicalRun = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNHISTORYDRIFT001',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'completed',
            'closed_reason' => 'continued',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(10),
            'closed_at' => now()->subMinutes(9),
            'last_progress_at' => now()->subMinutes(9),
        ]);

        $currentRun = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNHISTORYDRIFT002',
            'workflow_instance_id' => $instance->id,
            'run_number' => 2,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(2),
            'last_progress_at' => now()->subMinute(),
        ]);

        $instance->update([
            'current_run_id' => null,
            'run_count' => 2,
        ]);

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
            'status' => 'waiting',
            'status_bucket' => 'running',
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => $currentRun->started_at,
            'wait_kind' => 'signal',
            'wait_reason' => 'Waiting for signal approved-by',
            'wait_started_at' => now()->subSeconds(30),
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinute(),
        ]);

        $this->get('/waterline/api/instances/' . $instance->id)
            ->assertStatus(200)
            ->assertJsonPath('id', $currentRun->id)
            ->assertJsonPath('instance_id', $instance->id)
            ->assertJsonPath('selected_run_id', $currentRun->id)
            ->assertJsonPath('run_id', $currentRun->id)
            ->assertJsonPath('is_current_run', true)
            ->assertJsonPath('current_run_id', $currentRun->id);

        $this->get('/waterline/api/instances/' . $instance->id . '/runs/' . $historicalRun->id)
            ->assertStatus(200)
            ->assertJsonPath('id', $historicalRun->id)
            ->assertJsonPath('is_current_run', false)
            ->assertJsonPath('current_run_id', $currentRun->id)
            ->assertJsonPath('run_navigation.1.run_id', $currentRun->id)
            ->assertJsonPath('run_navigation.1.is_current_run', true);
    }

    public function testShowIncludesTaskDispatchFailureMetadata(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()->set('workflows.v2.compatibility.supported', ['build-a']);

        $instance = WorkflowInstance::create([
            'id' => 'dispatch-failure-instance',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNDISPATCHFAIL01',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'started_at' => now()->subMinutes(2),
            'last_progress_at' => now()->subMinute(),
        ]);

        $instance->update(['current_run_id' => $run->id]);

        $task = WorkflowTask::create([
            'id' => '01JTESTFLOWTASKDISPATCHFAIL1',
            'workflow_run_id' => $run->id,
            'task_type' => 'workflow',
            'status' => 'ready',
            'available_at' => now()->subMinute(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'last_dispatch_attempt_at' => now()->subSeconds(5),
            'last_dispatch_error' => 'Queue transport unavailable.',
        ]);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->get('/waterline/api/flows/' . $run->id)
            ->assertStatus(200)
            ->assertJsonPath('wait_reason', 'Workflow task dispatch failed')
            ->assertJsonPath('liveness_state', 'repair_needed')
            ->assertJsonPath(
                'liveness_reason',
                sprintf(
                    'Workflow task %s could not be dispatched at %s. Queue transport unavailable.',
                    $task->id,
                    $task->last_dispatch_attempt_at?->toJSON(),
                ),
            )
            ->assertJsonPath('tasks.0.transport_state', 'dispatch_failed')
            ->assertJsonPath('tasks.0.dispatch_failed', true)
            ->assertJsonPath('tasks.0.dispatch_overdue', false)
            ->assertJsonPath('tasks.0.last_dispatched_at', null)
            ->assertJsonPath('tasks.0.last_dispatch_attempt_at', $task->last_dispatch_attempt_at?->jsonSerialize())
            ->assertJsonPath('tasks.0.last_dispatch_error', 'Queue transport unavailable.')
            ->assertJsonPath('tasks.0.summary', 'Workflow task dispatch failed; waiting for recovery.');
    }

    public function testShowIncludesDeclaredCommandContractAndRejectedReason(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-command-contract',
            'workflow_class' => TestCommandContractWorkflow::class,
            'workflow_type' => 'workflow.command-contract',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNCOMMANDCON001',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestCommandContractWorkflow::class,
            'workflow_type' => 'workflow.command-contract',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subSeconds(20),
        ]);

        $instance->update(['current_run_id' => $run->id]);

        WorkflowRunSummary::create([
            'id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => TestCommandContractWorkflow::class,
            'workflow_type' => 'workflow.command-contract',
            'status' => 'waiting',
            'status_bucket' => 'running',
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => $run->started_at,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subSeconds(20),
        ]);

        WorkflowCommand::create([
            'id' => '01JTESTCOMMANDREJECTEDSIGNAL1',
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'command_sequence' => 1,
            'command_type' => 'signal',
            'target_scope' => 'instance',
            'source' => 'webhook',
            'status' => 'rejected',
            'outcome' => 'rejected_unknown_signal',
            'rejection_reason' => 'unknown_signal',
            'payload' => Serializer::serialize([
                'name' => 'not-declared',
                'arguments' => ['Taylor'],
            ]),
            'workflow_class' => TestCommandContractWorkflow::class,
            'workflow_type' => 'workflow.command-contract',
            'rejected_at' => now()->subSeconds(10),
            'created_at' => now()->subSeconds(10),
            'updated_at' => now()->subSeconds(10),
        ]);

        $this->get('/waterline/api/flows/' . $run->id)
            ->assertStatus(200)
            ->assertJsonPath('declared_signals', ['approved-by', 'rejected-by'])
            ->assertJsonPath('declared_updates', ['approve'])
            ->assertJsonPath('declared_contract_source', 'live_definition')
            ->assertJsonPath('commands.0.type', 'signal')
            ->assertJsonPath('commands.0.target_name', 'not-declared')
            ->assertJsonPath('commands.0.outcome', 'rejected_unknown_signal')
            ->assertJsonPath('commands.0.rejection_reason', 'unknown_signal');
    }

    public function testShowBackfillsWorkflowStartedCommandContractFromLiveDefinition(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-command-contract-backfill',
            'workflow_class' => TestCommandContractWorkflow::class,
            'workflow_type' => 'workflow.command-contract',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNCOMMANDCONBAC1',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestCommandContractWorkflow::class,
            'workflow_type' => 'workflow.command-contract',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subSeconds(20),
        ]);

        $instance->update(['current_run_id' => $run->id]);

        WorkflowRunSummary::create([
            'id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => TestCommandContractWorkflow::class,
            'workflow_type' => 'workflow.command-contract',
            'status' => 'waiting',
            'status_bucket' => 'running',
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => $run->started_at,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subSeconds(20),
        ]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYCOMMANDCONBAC1',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::WorkflowStarted->value,
            'payload' => [
                'workflow_class' => TestCommandContractWorkflow::class,
                'workflow_type' => 'workflow.command-contract',
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
            ],
            'recorded_at' => now()->subSeconds(19),
        ]);

        $this->get('/waterline/api/flows/' . $run->id)
            ->assertStatus(200)
            ->assertJsonPath('declared_signals', ['approved-by', 'rejected-by'])
            ->assertJsonPath('declared_updates', ['approve'])
            ->assertJsonPath('declared_contract_source', 'durable_history');

        /** @var WorkflowHistoryEvent $started */
        $started = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->sole();

        $this->assertSame(['approved-by', 'rejected-by'], $started->payload['declared_signals'] ?? null);
        $this->assertSame(['approve'], $started->payload['declared_updates'] ?? null);
    }

    public function testShowUsesDurableCommandContractHistoryWhenWorkflowClassCannotBeResolved(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-command-contract-history',
            'workflow_class' => TestCommandContractWorkflow::class,
            'workflow_type' => 'workflow.command-contract',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNCOMMANDCONHIS1',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'Missing\\Workflow\\CommandContractWorkflow',
            'workflow_type' => 'workflow.command-contract',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subSeconds(20),
        ]);

        $instance->update(['current_run_id' => $run->id]);

        WorkflowRunSummary::create([
            'id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'Missing\\Workflow\\CommandContractWorkflow',
            'workflow_type' => 'workflow.command-contract',
            'status' => 'waiting',
            'status_bucket' => 'running',
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => $run->started_at,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subSeconds(20),
        ]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYCOMMANDCONTRACT',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::WorkflowStarted->value,
            'payload' => [
                'declared_signals' => ['approved-by', 'rejected-by'],
                'declared_updates' => ['approve'],
            ],
            'recorded_at' => now()->subSeconds(19),
        ]);

        $this->get('/waterline/api/flows/' . $run->id)
            ->assertStatus(200)
            ->assertJsonPath('declared_signals', ['approved-by', 'rejected-by'])
            ->assertJsonPath('declared_updates', ['approve'])
            ->assertJsonPath('declared_contract_source', 'durable_history');
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

    public function testShowUsesHistoryToRenderCurrentContinuedChildWithoutLinks(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $parentInstance = WorkflowInstance::create([
            'id' => 'order-child-parent',
            'workflow_class' => 'ParentWorkflowClass',
            'workflow_type' => 'workflow.parent',
            'run_count' => 1,
        ]);

        $childInstance = WorkflowInstance::create([
            'id' => 'order-child-chain',
            'workflow_class' => 'ChildWorkflowClass',
            'workflow_type' => 'workflow.child',
            'run_count' => 2,
        ]);

        $parentRun = WorkflowRun::create([
            'id' => '01JTESTPARENTCHILDHIST0001',
            'workflow_instance_id' => $parentInstance->id,
            'run_number' => 1,
            'workflow_class' => 'ParentWorkflowClass',
            'workflow_type' => 'workflow.parent',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(5),
            'last_progress_at' => now()->subMinute(),
        ]);

        $historicalChildRun = WorkflowRun::create([
            'id' => '01JTESTCHILDCONTINUED0001',
            'workflow_instance_id' => $childInstance->id,
            'run_number' => 1,
            'workflow_class' => 'ChildWorkflowClass',
            'workflow_type' => 'workflow.child',
            'status' => 'completed',
            'closed_reason' => 'continued',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(4),
            'closed_at' => now()->subMinutes(3),
            'last_progress_at' => now()->subMinutes(3),
        ]);

        $currentChildRun = WorkflowRun::create([
            'id' => '01JTESTCHILDCURRENT000001',
            'workflow_instance_id' => $childInstance->id,
            'run_number' => 2,
            'workflow_class' => 'ChildWorkflowClass',
            'workflow_type' => 'workflow.child',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(2),
            'last_progress_at' => now()->subMinute(),
        ]);

        $parentInstance->update(['current_run_id' => $parentRun->id]);
        $childInstance->update(['current_run_id' => $currentChildRun->id]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTPARENTCHILDSCHED001',
            'workflow_run_id' => $parentRun->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::ChildWorkflowScheduled->value,
            'payload' => [
                'sequence' => 1,
                'child_workflow_instance_id' => $childInstance->id,
                'child_workflow_run_id' => $historicalChildRun->id,
                'child_workflow_class' => $historicalChildRun->workflow_class,
                'child_workflow_type' => $historicalChildRun->workflow_type,
                'child_run_number' => 1,
            ],
            'recorded_at' => now()->subMinutes(4),
        ]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTPARENTCHILDSTART001',
            'workflow_run_id' => $parentRun->id,
            'sequence' => 2,
            'event_type' => HistoryEventType::ChildRunStarted->value,
            'payload' => [
                'sequence' => 1,
                'child_workflow_instance_id' => $childInstance->id,
                'child_workflow_run_id' => $historicalChildRun->id,
                'child_workflow_class' => $historicalChildRun->workflow_class,
                'child_workflow_type' => $historicalChildRun->workflow_type,
                'child_run_number' => 1,
            ],
            'recorded_at' => now()->subMinutes(4),
        ]);

        WorkflowRunSummary::create([
            'id' => $currentChildRun->id,
            'workflow_instance_id' => $childInstance->id,
            'run_number' => 2,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'ChildWorkflowClass',
            'workflow_type' => 'workflow.child',
            'status' => 'waiting',
            'status_bucket' => 'running',
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => $currentChildRun->started_at,
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinute(),
        ]);

        RunSummaryProjector::project($parentRun->fresh());

        $this->get('/waterline/api/flows/' . $parentRun->id)
            ->assertStatus(200)
            ->assertJsonPath('wait_kind', 'child')
            ->assertJsonPath('continuedWorkflows.0.link_type', 'child_workflow')
            ->assertJsonPath('continuedWorkflows.0.child_workflow_run_id', $currentChildRun->id)
            ->assertJsonPath('continuedWorkflows.0.status', 'waiting')
            ->assertJsonPath('continuedWorkflows.0.status_bucket', 'running')
            ->assertJsonPath('waits.0.kind', 'child')
            ->assertJsonPath('waits.0.resume_source_id', $currentChildRun->id)
            ->assertJsonPath('waits.0.target_name', $childInstance->id);
    }

    public function testShowPrefersTypedChildResolutionHistoryWhenChildRunRowDrifts(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $parentInstance = WorkflowInstance::create([
            'id' => 'order-child-history-parent',
            'workflow_class' => 'ParentWorkflowClass',
            'workflow_type' => 'workflow.parent',
            'run_count' => 1,
        ]);

        $childInstance = WorkflowInstance::create([
            'id' => 'order-child-history-child',
            'workflow_class' => 'ChildWorkflowClass',
            'workflow_type' => 'workflow.child',
            'run_count' => 1,
        ]);

        $parentRun = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNCHILDHISTORY01',
            'workflow_instance_id' => $parentInstance->id,
            'run_number' => 1,
            'workflow_class' => 'ParentWorkflowClass',
            'workflow_type' => 'workflow.parent',
            'status' => 'completed',
            'closed_reason' => 'completed',
            'arguments' => Serializer::serialize([]),
            'output' => Serializer::serialize(['ok' => true]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(4),
            'closed_at' => now()->subMinutes(1),
            'last_progress_at' => now()->subMinutes(1),
        ]);

        $childRun = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNCHILDHISTORY02',
            'workflow_instance_id' => $childInstance->id,
            'run_number' => 1,
            'workflow_class' => 'ChildWorkflowClass',
            'workflow_type' => 'workflow.child',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'output' => Serializer::serialize('corrupted-child-output'),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(3),
            'last_progress_at' => now()->subMinutes(1),
        ]);

        $parentInstance->update(['current_run_id' => $parentRun->id]);
        $childInstance->update(['current_run_id' => $childRun->id]);

        WorkflowRunSummary::create([
            'id' => $parentRun->id,
            'workflow_instance_id' => $parentInstance->id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'ParentWorkflowClass',
            'workflow_type' => 'workflow.parent',
            'status' => 'completed',
            'status_bucket' => 'completed',
            'closed_reason' => 'completed',
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => $parentRun->started_at,
            'closed_at' => $parentRun->closed_at,
            'duration_ms' => 180000,
            'created_at' => now()->subMinutes(4),
            'updated_at' => now()->subMinutes(1),
        ]);

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
            'updated_at' => now()->subMinutes(1),
        ]);

        $link = WorkflowLink::create([
            'id' => '01JTESTFLOWLINKCHILDHISTORY1',
            'link_type' => 'child_workflow',
            'sequence' => 1,
            'parent_workflow_instance_id' => $parentInstance->id,
            'parent_workflow_run_id' => $parentRun->id,
            'child_workflow_instance_id' => $childInstance->id,
            'child_workflow_run_id' => $childRun->id,
            'is_primary_parent' => true,
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(3),
        ]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYCHILDHISTORY01',
            'workflow_run_id' => $parentRun->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::ChildWorkflowScheduled->value,
            'payload' => [
                'workflow_link_id' => $link->id,
                'sequence' => 1,
                'child_workflow_instance_id' => $childInstance->id,
                'child_workflow_run_id' => $childRun->id,
                'child_workflow_type' => 'workflow.child',
                'child_workflow_class' => 'ChildWorkflowClass',
                'child_run_number' => 1,
            ],
            'recorded_at' => now()->subMinutes(3),
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(3),
        ]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYCHILDHISTORY02',
            'workflow_run_id' => $parentRun->id,
            'sequence' => 2,
            'event_type' => HistoryEventType::ChildRunCompleted->value,
            'payload' => [
                'workflow_link_id' => $link->id,
                'sequence' => 1,
                'child_workflow_instance_id' => $childInstance->id,
                'child_workflow_run_id' => $childRun->id,
                'child_workflow_type' => 'workflow.child',
                'child_workflow_class' => 'ChildWorkflowClass',
                'child_run_number' => 1,
                'child_status' => 'completed',
                'closed_reason' => 'completed',
                'output' => Serializer::serialize(['ok' => true]),
            ],
            'recorded_at' => now()->subMinutes(2),
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);

        $this->get('/waterline/api/flows/' . $parentRun->id)
            ->assertStatus(200)
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('waits.0.kind', 'child')
            ->assertJsonPath('waits.0.status', 'resolved')
            ->assertJsonPath('waits.0.source_status', 'completed')
            ->assertJsonPath('waits.0.summary', 'Child workflow workflow.child completed.')
            ->assertJsonPath('waits.0.target_name', $childInstance->id)
            ->assertJsonPath('waits.0.resume_source_id', $childRun->id);
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
            ->assertJsonPath('update_blocked_reason', null)
            ->assertJsonPath('can_signal', true)
            ->assertJsonPath('commands.0.context', [])
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

    public function testShowMarksUpdateAsBlockedWhenAnEarlierSignalIsPending(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-update-blocked',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNUPDBLOCKED001',
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

        WorkflowCommand::create([
            'id' => '01JTESTCOMMANDSTARTBLOCKED01',
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'command_sequence' => 1,
            'command_type' => 'start',
            'target_scope' => 'instance',
            'source' => 'php',
            'status' => 'accepted',
            'outcome' => 'started_new',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'accepted_at' => now()->subMinutes(2),
            'applied_at' => now()->subMinutes(2),
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);

        WorkflowCommand::create([
            'id' => '01JTESTCOMMANDSIGNALBLOCK01',
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'command_sequence' => 2,
            'command_type' => 'signal',
            'target_scope' => 'instance',
            'source' => 'webhook',
            'status' => 'accepted',
            'outcome' => 'signal_received',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'payload_codec' => Serializer::class,
            'payload' => Serializer::serialize([
                'name' => 'name-provided',
                'arguments' => ['Taylor'],
            ]),
            'accepted_at' => now()->subSeconds(20),
            'created_at' => now()->subSeconds(20),
            'updated_at' => now()->subSeconds(20),
        ]);

        WorkflowCommand::create([
            'id' => '01JTESTCOMMANDUPDATEBLOCK01',
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'command_sequence' => 3,
            'command_type' => 'update',
            'target_scope' => 'instance',
            'source' => 'webhook',
            'status' => 'rejected',
            'outcome' => 'rejected_pending_signal',
            'rejection_reason' => 'earlier_signal_pending',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'payload_codec' => Serializer::class,
            'payload' => Serializer::serialize([
                'name' => 'approve',
                'arguments' => [true, 'waterline'],
            ]),
            'rejected_at' => now()->subSeconds(18),
            'created_at' => now()->subSeconds(18),
            'updated_at' => now()->subSeconds(18),
        ]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYSIGNALWAIT001',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => 'SignalWaitOpened',
            'payload' => [
                'signal_name' => 'name-provided',
                'signal_wait_id' => 'signal-wait-1',
                'sequence' => 1,
            ],
            'recorded_at' => now()->subSeconds(25),
            'created_at' => now()->subSeconds(25),
            'updated_at' => now()->subSeconds(25),
        ]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYSIGNALRECV001',
            'workflow_run_id' => $run->id,
            'sequence' => 2,
            'event_type' => 'SignalReceived',
            'payload' => [
                'signal_name' => 'name-provided',
                'signal_wait_id' => 'signal-wait-1',
            ],
            'workflow_command_id' => '01JTESTCOMMANDSIGNALBLOCK01',
            'recorded_at' => now()->subSeconds(20),
            'created_at' => now()->subSeconds(20),
            'updated_at' => now()->subSeconds(20),
        ]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYUPDATEREJ001',
            'workflow_run_id' => $run->id,
            'sequence' => 3,
            'event_type' => HistoryEventType::UpdateRejected->value,
            'payload' => [
                'workflow_command_id' => '01JTESTCOMMANDUPDATEBLOCK01',
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'update_name' => 'approve',
                'arguments' => Serializer::serialize([true, 'waterline']),
                'command' => [
                    'id' => '01JTESTCOMMANDUPDATEBLOCK01',
                    'sequence' => 3,
                    'type' => 'update',
                    'target_scope' => 'instance',
                    'target_name' => 'approve',
                    'source' => 'webhook',
                    'status' => 'rejected',
                    'outcome' => 'rejected_pending_signal',
                    'rejection_reason' => 'earlier_signal_pending',
                    'rejected_at' => now()->subSeconds(18)->jsonSerialize(),
                ],
            ],
            'workflow_command_id' => '01JTESTCOMMANDUPDATEBLOCK01',
            'recorded_at' => now()->subSeconds(18),
            'created_at' => now()->subSeconds(18),
            'updated_at' => now()->subSeconds(18),
        ]);

        WorkflowTask::create([
            'id' => '01JTESTTASKUPDBLOCKED000001',
            'workflow_run_id' => $run->id,
            'task_type' => 'workflow',
            'status' => 'ready',
            'available_at' => now()->subSeconds(20),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'created_at' => now()->subSeconds(20),
            'updated_at' => now()->subSeconds(20),
        ]);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->get('/waterline/api/flows/' . $run->id)
            ->assertStatus(200)
            ->assertJsonPath('can_signal', true)
            ->assertJsonPath('can_update', false)
            ->assertJsonPath('update_blocked_reason', 'earlier_signal_pending')
            ->assertJsonPath('commands.1.type', 'signal')
            ->assertJsonPath('commands.1.target_name', 'name-provided')
            ->assertJsonPath('commands.1.outcome', 'signal_received')
            ->assertJsonPath('commands.2.type', 'update')
            ->assertJsonPath('commands.2.target_name', 'approve')
            ->assertJsonPath('commands.2.status', 'rejected')
            ->assertJsonPath('commands.2.outcome', 'rejected_pending_signal')
            ->assertJsonPath('waits.0.kind', 'signal')
            ->assertJsonPath('waits.0.source_status', 'received')
            ->assertJsonPath('waits.0.command_sequence', 2)
            ->assertJsonPath('timeline.2.type', 'UpdateRejected')
            ->assertJsonPath('timeline.2.source_kind', 'workflow_command')
            ->assertJsonPath('timeline.2.source_id', '01JTESTCOMMANDUPDATEBLOCK01')
            ->assertJsonPath('timeline.2.update_name', 'approve')
            ->assertJsonPath('timeline.2.command_status', 'rejected')
            ->assertJsonPath('timeline.2.command_outcome', 'rejected_pending_signal')
            ->assertJsonPath('timeline.2.summary', 'Rejected update approve: earlier_signal_pending.');
    }

    public function testShowKeepsSignalWaitCommandMetadataWhenCommandRowsDrift(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-signal-history-snapshot',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWSIGNALSNAPSHOT01',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(2),
            'last_progress_at' => now()->subSeconds(15),
            'last_history_sequence' => 0,
        ]);

        $instance->update(['current_run_id' => $run->id]);

        $startCommand = WorkflowCommand::create([
            'id' => '01JTESTCOMMANDSIGNALSNAP001',
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'command_sequence' => 1,
            'command_type' => 'start',
            'target_scope' => 'instance',
            'source' => 'php',
            'status' => 'accepted',
            'outcome' => 'started_new',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'accepted_at' => now()->subMinutes(2),
            'applied_at' => now()->subMinutes(2),
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);

        $signalCommand = WorkflowCommand::create([
            'id' => '01JTESTCOMMANDSIGNALSNAP002',
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'command_sequence' => 2,
            'command_type' => 'signal',
            'target_scope' => 'instance',
            'source' => 'webhook',
            'status' => 'accepted',
            'outcome' => 'signal_received',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'payload_codec' => Serializer::class,
            'payload' => Serializer::serialize([
                'name' => 'name-provided',
                'arguments' => ['Taylor'],
            ]),
            'accepted_at' => now()->subSeconds(20),
            'created_at' => now()->subSeconds(20),
            'updated_at' => now()->subSeconds(20),
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::StartAccepted, [
            'workflow_command_id' => $startCommand->id,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'workflow_class' => $run->workflow_class,
            'workflow_type' => $run->workflow_type,
            'outcome' => $startCommand->outcome,
        ], null, $startCommand);

        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowStarted, [
            'workflow_command_id' => $startCommand->id,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'workflow_class' => $run->workflow_class,
            'workflow_type' => $run->workflow_type,
            'declared_signals' => ['name-provided'],
            'declared_updates' => [],
        ], null, $startCommand);

        WorkflowHistoryEvent::record($run, HistoryEventType::SignalWaitOpened, [
            'signal_name' => 'name-provided',
            'signal_wait_id' => 'signal-wait-1',
            'sequence' => 1,
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::SignalReceived, [
            'signal_name' => 'name-provided',
            'signal_wait_id' => 'signal-wait-1',
        ], null, $signalCommand);

        WorkflowTask::create([
            'id' => '01JTESTTASKSIGNALSNAPSHOT1',
            'workflow_run_id' => $run->id,
            'task_type' => 'workflow',
            'status' => 'ready',
            'available_at' => now()->subSeconds(20),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'created_at' => now()->subSeconds(20),
            'updated_at' => now()->subSeconds(20),
        ]);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $signalCommand->delete();

        $this->get('/waterline/api/flows/' . $run->id)
            ->assertStatus(200)
            ->assertJsonPath('waits.0.kind', 'signal')
            ->assertJsonPath('waits.0.signal_wait_id', 'signal-wait-1')
            ->assertJsonPath('waits.0.command_sequence', 2)
            ->assertJsonPath('waits.0.command_status', 'accepted')
            ->assertJsonPath('waits.0.command_outcome', 'signal_received');
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

    public function testShowPreservesBufferedSignalWaitIdsWhenSignalWasReceivedBeforeWaitOpened(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'buffered-signal-detail',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNBUFFERSIGNAL1',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'completed',
            'closed_reason' => 'completed',
            'arguments' => Serializer::serialize([]),
            'output' => Serializer::serialize(['ok' => true]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinute(),
            'closed_at' => now()->subSeconds(5),
            'last_progress_at' => now()->subSeconds(5),
        ]);

        $instance->update(['current_run_id' => $run->id]);

        WorkflowCommand::create([
            'id' => '01JTESTCOMMANDBUFFERSTART001',
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'command_sequence' => 1,
            'command_type' => 'start',
            'target_scope' => 'instance',
            'source' => 'php',
            'status' => 'accepted',
            'outcome' => 'started_new',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'accepted_at' => now()->subMinute(),
            'applied_at' => now()->subMinute(),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        WorkflowCommand::create([
            'id' => '01JTESTCOMMANDBUFFERSIGNAL01',
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'command_sequence' => 2,
            'command_type' => 'signal',
            'target_scope' => 'instance',
            'source' => 'php',
            'status' => 'accepted',
            'outcome' => 'signal_received',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'payload_codec' => Serializer::class,
            'payload' => Serializer::serialize([
                'name' => 'message',
                'arguments' => ['first'],
            ]),
            'accepted_at' => now()->subSeconds(50),
            'applied_at' => now()->subSeconds(40),
            'created_at' => now()->subSeconds(50),
            'updated_at' => now()->subSeconds(40),
        ]);

        WorkflowCommand::create([
            'id' => '01JTESTCOMMANDBUFFERSIGNAL02',
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'command_sequence' => 3,
            'command_type' => 'signal',
            'target_scope' => 'instance',
            'source' => 'php',
            'status' => 'accepted',
            'outcome' => 'signal_received',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'payload_codec' => Serializer::class,
            'payload' => Serializer::serialize([
                'name' => 'message',
                'arguments' => ['second'],
            ]),
            'accepted_at' => now()->subSeconds(30),
            'applied_at' => now()->subSeconds(10),
            'created_at' => now()->subSeconds(30),
            'updated_at' => now()->subSeconds(10),
        ]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYBUFFSIGOPEN001',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => 'SignalWaitOpened',
            'payload' => [
                'signal_name' => 'message',
                'signal_wait_id' => 'signal-wait-1',
                'sequence' => 1,
            ],
            'recorded_at' => now()->subSeconds(55),
            'created_at' => now()->subSeconds(55),
            'updated_at' => now()->subSeconds(55),
        ]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYBUFFSIGRECV01',
            'workflow_run_id' => $run->id,
            'sequence' => 2,
            'event_type' => 'SignalReceived',
            'payload' => [
                'signal_name' => 'message',
                'signal_wait_id' => 'signal-wait-1',
            ],
            'workflow_command_id' => '01JTESTCOMMANDBUFFERSIGNAL01',
            'recorded_at' => now()->subSeconds(50),
            'created_at' => now()->subSeconds(50),
            'updated_at' => now()->subSeconds(50),
        ]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYBUFFSIGAPPLY1',
            'workflow_run_id' => $run->id,
            'sequence' => 3,
            'event_type' => 'SignalApplied',
            'payload' => [
                'signal_name' => 'message',
                'signal_wait_id' => 'signal-wait-1',
                'sequence' => 1,
            ],
            'workflow_command_id' => '01JTESTCOMMANDBUFFERSIGNAL01',
            'recorded_at' => now()->subSeconds(40),
            'created_at' => now()->subSeconds(40),
            'updated_at' => now()->subSeconds(40),
        ]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYBUFFSIGRECV02',
            'workflow_run_id' => $run->id,
            'sequence' => 4,
            'event_type' => 'SignalReceived',
            'payload' => [
                'signal_name' => 'message',
                'signal_wait_id' => 'signal-wait-2',
            ],
            'workflow_command_id' => '01JTESTCOMMANDBUFFERSIGNAL02',
            'recorded_at' => now()->subSeconds(30),
            'created_at' => now()->subSeconds(30),
            'updated_at' => now()->subSeconds(30),
        ]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYBUFFSIGOPEN002',
            'workflow_run_id' => $run->id,
            'sequence' => 5,
            'event_type' => 'SignalWaitOpened',
            'payload' => [
                'signal_name' => 'message',
                'signal_wait_id' => 'signal-wait-2',
                'sequence' => 2,
            ],
            'recorded_at' => now()->subSeconds(20),
            'created_at' => now()->subSeconds(20),
            'updated_at' => now()->subSeconds(20),
        ]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYBUFFSIGAPPLY2',
            'workflow_run_id' => $run->id,
            'sequence' => 6,
            'event_type' => 'SignalApplied',
            'payload' => [
                'signal_name' => 'message',
                'signal_wait_id' => 'signal-wait-2',
                'sequence' => 2,
            ],
            'workflow_command_id' => '01JTESTCOMMANDBUFFERSIGNAL02',
            'recorded_at' => now()->subSeconds(10),
            'created_at' => now()->subSeconds(10),
            'updated_at' => now()->subSeconds(10),
        ]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYBUFFSIGDONE001',
            'workflow_run_id' => $run->id,
            'sequence' => 7,
            'event_type' => 'WorkflowCompleted',
            'payload' => [
                'output' => $run->output,
            ],
            'recorded_at' => now()->subSeconds(5),
            'created_at' => now()->subSeconds(5),
            'updated_at' => now()->subSeconds(5),
        ]);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $response = $this->get('/waterline/api/flows/' . $run->id)
            ->assertStatus(200)
            ->assertJsonPath('waits.0.kind', 'signal')
            ->assertJsonPath('waits.0.status', 'resolved')
            ->assertJsonPath('waits.0.signal_wait_id', 'signal-wait-1')
            ->assertJsonPath('waits.0.command_sequence', 2)
            ->assertJsonPath('waits.1.signal_wait_id', 'signal-wait-2')
            ->assertJsonPath('waits.1.command_sequence', 3);

        $this->assertSame(
            ['signal-wait-1', 'signal-wait-2'],
            collect($response->json('waits'))
                ->pluck('signal_wait_id')
                ->all(),
        );
        $this->assertSame(
            ['signal-wait-1', 'signal-wait-2'],
            collect($response->json('timeline'))
                ->filter(static fn (array $entry): bool => ($entry['type'] ?? null) === 'SignalWaitOpened')
                ->pluck('signal_wait_id')
                ->all(),
        );
        $this->assertSame(
            ['signal-wait-1', 'signal-wait-2'],
            collect($response->json('timeline'))
                ->filter(static fn (array $entry): bool => ($entry['type'] ?? null) === 'SignalReceived')
                ->pluck('signal_wait_id')
                ->all(),
        );
    }

    public function testShowIncludesWorkflowSourceMetadataForWorkflowOriginatedStartCommand(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-continued-current',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 2,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNWORKFLOWSRC1',
            'workflow_instance_id' => $instance->id,
            'run_number' => 2,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'completed',
            'closed_reason' => 'completed',
            'arguments' => Serializer::serialize([]),
            'output' => Serializer::serialize(['ok' => true]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(2),
            'closed_at' => now()->subMinute(),
            'last_progress_at' => now()->subMinute(),
        ]);

        $instance->update(['current_run_id' => $run->id]);

        WorkflowRunSummary::create([
            'id' => $run->id,
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
            'started_at' => $run->started_at,
            'closed_at' => $run->closed_at,
            'duration_ms' => 60000,
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinute(),
        ]);

        WorkflowCommand::create([
            'id' => '01JTESTCOMMANDWORKFLOWSRC1',
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'command_sequence' => 1,
            'command_type' => 'start',
            'target_scope' => 'instance',
            'status' => 'accepted',
            'outcome' => 'started_new',
            'source' => 'workflow',
            'context' => [
                'caller' => [
                    'type' => 'workflow',
                    'label' => 'Workflow',
                ],
                'auth' => [
                    'status' => 'not_applicable',
                    'method' => 'none',
                ],
                'workflow' => [
                    'parent_instance_id' => 'order-continued-current',
                    'parent_run_id' => '01JTESTFLOWRUNWORKFLOWSRC0',
                    'sequence' => 2,
                ],
            ],
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'payload_codec' => Serializer::class,
            'payload' => Serializer::serialize([]),
            'accepted_at' => now()->subMinutes(2),
            'applied_at' => now()->subMinutes(2),
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);

        $this->get('/waterline/api/flows/' . $run->id)
            ->assertStatus(200)
            ->assertJsonPath('commands.0.sequence', 1)
            ->assertJsonPath('commands.0.type', 'start')
            ->assertJsonPath('commands.0.source', 'workflow')
            ->assertJsonPath('commands.0.caller_label', 'Workflow')
            ->assertJsonPath('commands.0.context.workflow.parent_instance_id', 'order-continued-current')
            ->assertJsonPath('commands.0.context.workflow.parent_run_id', '01JTESTFLOWRUNWORKFLOWSRC0')
            ->assertJsonPath('commands.0.context.workflow.sequence', 2);
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

        $response = $this->post('/waterline/api/instances/' . $instance->id . '/cancel');

        $response
            ->assertStatus(200)
            ->assertJsonPath('outcome', 'cancelled')
            ->assertJsonPath('workflow_id', $instance->id)
            ->assertJsonPath('run_id', $run->id)
            ->assertJsonPath('target_scope', 'instance')
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
            'target_scope' => 'instance',
            'status' => 'accepted',
            'outcome' => 'cancelled',
        ]);

        $command = WorkflowCommand::query()->findOrFail($commandId);

        $this->assertSame('Waterline UI', $command->callerLabel());
        $this->assertSame('authorized', $command->authStatus());
        $this->assertSame('waterline', $command->authMethod());
        $this->assertSame('POST', $command->requestMethod());
        $this->assertSame('/waterline/api/instances/'.$instance->id.'/cancel', $command->requestPath());
        $this->assertSame('waterline.instances.cancel', $command->requestRouteName());
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

        $response = $this->post('/waterline/api/instances/' . $instance->id . '/repair');

        $response
            ->assertStatus(200)
            ->assertJsonPath('outcome', 'repair_dispatched')
            ->assertJsonPath('workflow_id', $instance->id)
            ->assertJsonPath('run_id', $run->id)
            ->assertJsonPath('target_scope', 'instance')
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('rejection_reason', null);

        $commandId = $response->json('command_id');

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $commandId,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'command_type' => 'repair',
            'target_scope' => 'instance',
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

    public function testTerminateTargetsCurrentInstanceRouteAndReturnsAcceptedResponse(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-terminate-current',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNTERMCURRENT01',
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

        $response = $this->post('/waterline/api/instances/' . $instance->id . '/terminate');

        $response
            ->assertStatus(200)
            ->assertJsonPath('outcome', 'terminated')
            ->assertJsonPath('workflow_id', $instance->id)
            ->assertJsonPath('run_id', $run->id)
            ->assertJsonPath('target_scope', 'instance')
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('command_source', 'waterline')
            ->assertJsonPath('rejection_reason', null);

        $commandId = $response->json('command_id');

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $commandId,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'command_type' => 'terminate',
            'source' => 'waterline',
            'target_scope' => 'instance',
            'status' => 'accepted',
            'outcome' => 'terminated',
        ]);

        $command = WorkflowCommand::query()->findOrFail($commandId);

        $this->assertSame('Waterline UI', $command->callerLabel());
        $this->assertSame('authorized', $command->authStatus());
        $this->assertSame('waterline', $command->authMethod());
        $this->assertSame('POST', $command->requestMethod());
        $this->assertSame('/waterline/api/instances/'.$instance->id.'/terminate', $command->requestPath());
        $this->assertSame('waterline.instances.terminate', $command->requestRouteName());
    }

    public function testTerminateTargetsSelectedRunRouteAndReturnsAcceptedResponse(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-terminate-selected-run',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNTERMSEL0001',
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

        $response = $this->post('/waterline/api/instances/' . $instance->id . '/runs/' . $run->id . '/terminate');

        $response
            ->assertStatus(200)
            ->assertJsonPath('outcome', 'terminated')
            ->assertJsonPath('workflow_id', $instance->id)
            ->assertJsonPath('run_id', $run->id)
            ->assertJsonPath('target_scope', 'run')
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('command_source', 'waterline')
            ->assertJsonPath('rejection_reason', null);

        $commandId = $response->json('command_id');

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $commandId,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'command_type' => 'terminate',
            'source' => 'waterline',
            'target_scope' => 'run',
            'status' => 'accepted',
            'outcome' => 'terminated',
        ]);

        $command = WorkflowCommand::query()->findOrFail($commandId);

        $this->assertSame(
            '/waterline/api/instances/'.$instance->id.'/runs/'.$run->id.'/terminate',
            $command->requestPath(),
        );
        $this->assertSame('waterline.instances.runs.terminate', $command->requestRouteName());
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

        $this->post('/waterline/api/instances/' . $instance->id . '/repair')
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

    public function testAutomaticWorkerRecoveryRecreatesMissingWorkflowTaskAndClearsRepairNeededFromFlowDetail(): void
    {
        config()->set('waterline.engine_source', 'v2');
        Queue::fake();

        $instance = WorkflowInstance::create([
            'id' => 'order-repair-watchdog-current',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNREPAIRWATCH01',
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

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->get('/waterline/api/flows/' . $instance->id)
            ->assertStatus(200)
            ->assertJsonPath('liveness_state', 'repair_needed')
            ->assertJsonPath('can_repair', true)
            ->assertJsonPath('next_task_id', null);

        $this->wakeTaskWatchdog();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->sole();

        Queue::assertPushed(
            RunWorkflowTask::class,
            static fn (RunWorkflowTask $job): bool => $job->taskId === $task->id
        );

        $this->get('/waterline/api/flows/' . $instance->id)
            ->assertStatus(200)
            ->assertJsonPath('next_task_id', $task->id)
            ->assertJsonPath('next_task_type', 'workflow')
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

    public function testTerminateSelectionRouteRejectsHistoricalRunSelection(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-terminate-selection-historical',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 2,
        ]);

        $historicalRun = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNTERMHISTRUN1',
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
            'id' => '01JTESTFLOWRUNTERMCURRRUN1',
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

        $response = $this->post(
            '/waterline/api/instances/' . $instance->id . '/runs/' . $historicalRun->id . '/terminate'
        );

        $response
            ->assertStatus(409)
            ->assertJsonPath('outcome', 'rejected_not_current')
            ->assertJsonPath('workflow_id', $instance->id)
            ->assertJsonPath('run_id', $historicalRun->id)
            ->assertJsonPath('target_scope', 'run')
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

        $command = WorkflowCommand::query()->findOrFail($commandId);

        $this->assertSame(
            '/waterline/api/instances/'.$instance->id.'/runs/'.$historicalRun->id.'/terminate',
            $command->requestPath(),
        );
        $this->assertSame('waterline.instances.runs.terminate', $command->requestRouteName());
    }

    private function wakeTaskWatchdog(): void
    {
        Cache::forget(TaskWatchdog::LOOP_THROTTLE_KEY);
        TaskWatchdog::wake();
    }
}
