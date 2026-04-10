<?php

namespace Waterline\Tests\Feature;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Waterline\Tests\Fixtures\V2\TestAbstractWaterlineException;
use Waterline\Tests\Fixtures\V2\TestCommandContractWorkflow;
use Waterline\Tests\Fixtures\V2\TestLinearizedOperatorWorkflow;
use Waterline\Tests\Fixtures\V2\TestOperatorCommandWorkflow;
use Waterline\Tests\Fixtures\V2\TestNestedParallelActivityWorkflow;
use Waterline\Tests\Fixtures\V2\TestParallelActivityWorkflow;
use Waterline\Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\OperatorObservabilityRepository;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\Support\ActivityLease;
use Workflow\V2\Support\ActivitySnapshot;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Support\WorkflowDefinition;
use Workflow\V2\Support\WorkflowInstanceId;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\WorkflowStub;

class V2DashboardWorkflowTest extends TestCase
{
    public function testV2OperatorPayloadsUseWorkflowObservabilityRepositoryContract(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'waterline-observability-contract',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.contract',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTWATERLINECONTRACT01',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.contract',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subSeconds(10),
        ]);

        $instance->update([
            'current_run_id' => $run->id,
        ]);

        $this->app->instance(OperatorObservabilityRepository::class, new class implements OperatorObservabilityRepository
        {
            public function runDetail(WorkflowRun $run): array
            {
                return [
                    'id' => $run->id,
                    'run_id' => $run->id,
                    'contract_boundary' => 'detail',
                ];
            }

            public function runHistoryExport(
                WorkflowRun $run,
                ?\Carbon\CarbonInterface $exportedAt = null,
                \Workflow\V2\Contracts\HistoryExportRedactor|callable|null $redactor = null,
            ): array {
                return [
                    'schema' => 'test.operator-observability',
                    'run_id' => $run->id,
                    'contract_boundary' => 'history_export',
                ];
            }

            public function dashboardSummary(?\Carbon\CarbonInterface $now = null): array
            {
                return [
                    'flows' => 12,
                    'flows_per_minute' => 0.2,
                    'flows_past_hour' => 12,
                    'exceptions_past_hour' => 0,
                    'failed_flows_past_week' => 0,
                    'max_wait_time_workflow' => null,
                    'max_duration_workflow' => null,
                    'max_exceptions_workflow' => null,
                    'operator_metrics' => [
                        'contract_boundary' => 'dashboard_summary',
                    ],
                    'contract_boundary' => 'dashboard_summary',
                ];
            }

            public function metrics(?\Carbon\CarbonInterface $now = null): array
            {
                return [
                    'contract_boundary' => 'metrics',
                ];
            }
        });

        $this->get('/waterline/api/flows/' . $run->id)
            ->assertOk()
            ->assertJsonPath('contract_boundary', 'detail')
            ->assertJsonPath('run_id', $run->id);

        $this->get('/waterline/api/flows/' . $run->id . '/history-export')
            ->assertOk()
            ->assertJsonPath('contract_boundary', 'history_export')
            ->assertJsonPath('run_id', $run->id);

        $this->get('/waterline/api/stats')
            ->assertOk()
            ->assertJsonPath('contract_boundary', 'dashboard_summary')
            ->assertJsonPath('operator_metrics.contract_boundary', 'dashboard_summary');
    }

    public function testShowSurfacesReplayBlockWhenActivityHistoryShapeDrifts(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('queue.default', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestParallelActivityWorkflow::class, 'waterline-activity-shape-replay-blocked');
        $workflow->start('Ada', 'Grace');

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());

        WorkflowHistoryEvent::record($run, HistoryEventType::TimerScheduled, [
            'timer_id' => 'timer-from-older-definition',
            'sequence' => 1,
            'delay_seconds' => 60,
            'fire_at' => now()->addMinute()->toJSON(),
        ]);

        $this->runReadyWorkflowTask($workflow->runId());

        $response = $this->get('/waterline/api/flows/' . $workflow->runId());

        $response
            ->assertOk()
            ->assertJsonPath('liveness_state', 'workflow_replay_blocked')
            ->assertJsonPath('can_repair', true)
            ->assertJsonPath('tasks.0.status', 'failed')
            ->assertJsonPath('tasks.0.transport_state', 'replay_blocked')
            ->assertJsonPath('tasks.0.replay_blocked', true)
            ->assertJsonPath('tasks.0.replay_blocked_reason', 'history_shape_mismatch')
            ->assertJsonPath('tasks.0.replay_blocked_workflow_sequence', 1)
            ->assertJsonPath('tasks.0.replay_blocked_expected_history_shape', 'activity')
            ->assertJsonPath('tasks.0.replay_blocked_recorded_event_types', ['TimerScheduled']);

        $this->assertStringContainsString(
            'history recorded [TimerScheduled]',
            (string) $response->json('liveness_reason')
        );
    }

    public function testShowSurfacesReplayBlockWhenParallelBarrierTopologyDrifts(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('queue.default', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(
            TestNestedParallelActivityWorkflow::class,
            'waterline-parallel-topology-replay-blocked',
        );
        $workflow->start('Taylor', 'Abigail', 'Selena');
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->runReadyWorkflowTask($runId);
        $this->replaceActivityScheduledParallelPath($runId, 2, [[
            'parallel_group_id' => 'parallel-activities:1:3',
            'parallel_group_kind' => 'activity',
            'parallel_group_base_sequence' => 1,
            'parallel_group_size' => 3,
            'parallel_group_index' => 1,
        ]]);

        $this->runReadyActivityTaskForSequence($runId, 1);
        $this->runReadyActivityTaskForSequence($runId, 2);
        $this->runReadyActivityTaskForSequence($runId, 3);
        $this->runReadyWorkflowTask($runId);

        $response = $this->get('/waterline/api/flows/' . $runId);

        $response
            ->assertOk()
            ->assertJsonPath('liveness_state', 'workflow_replay_blocked')
            ->assertJsonPath('can_repair', true)
            ->assertJsonPath('tasks.0.status', 'failed')
            ->assertJsonPath('tasks.0.transport_state', 'replay_blocked')
            ->assertJsonPath('tasks.0.replay_blocked', true)
            ->assertJsonPath('tasks.0.replay_blocked_reason', 'history_shape_mismatch')
            ->assertJsonPath('tasks.0.replay_blocked_workflow_sequence', 2)
            ->assertJsonPath(
                'tasks.0.replay_blocked_expected_history_shape',
                'parallel all barrier matching current topology',
            )
            ->assertJsonPath(
                'tasks.0.replay_blocked_recorded_event_types',
                ['ActivityScheduled', 'ActivityStarted', 'ActivityCompleted'],
            );

        $this->assertStringContainsString(
            'parallel all barrier matching current topology',
            (string) $response->json('liveness_reason')
        );
    }

    public function testShowSurfacesReplayBlockWhenParallelBarrierMetadataIsMissing(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('queue.default', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(
            TestParallelActivityWorkflow::class,
            'waterline-parallel-missing-metadata-replay-blocked',
        );
        $workflow->start('Taylor', 'Abigail');
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->runReadyWorkflowTask($runId);
        $this->runReadyActivityTaskForSequence($runId, 1);
        $this->runReadyActivityTaskForSequence($runId, 2);
        $this->removeActivityHistoryParallelMetadata($runId, 1);
        $this->runReadyWorkflowTask($runId);

        $response = $this->get('/waterline/api/flows/' . $runId);

        $response
            ->assertOk()
            ->assertJsonPath('liveness_state', 'workflow_replay_blocked')
            ->assertJsonPath('can_repair', true)
            ->assertJsonPath('tasks.0.status', 'failed')
            ->assertJsonPath('tasks.0.transport_state', 'replay_blocked')
            ->assertJsonPath('tasks.0.replay_blocked', true)
            ->assertJsonPath('tasks.0.replay_blocked_reason', 'history_shape_mismatch')
            ->assertJsonPath('tasks.0.replay_blocked_workflow_sequence', 1)
            ->assertJsonPath(
                'tasks.0.replay_blocked_expected_history_shape',
                'parallel all barrier matching current topology',
            )
            ->assertJsonPath(
                'tasks.0.replay_blocked_recorded_event_types',
                ['ActivityScheduled', 'ActivityStarted', 'ActivityCompleted'],
            );
    }

    public function testShowSurfacesReplayBlockWhenChildParentHistoryIsMissing(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $parentInstance = WorkflowInstance::create([
            'id' => 'waterline-child-replay-block-parent',
            'workflow_class' => 'ParentWorkflowClass',
            'workflow_type' => 'workflow.parent',
            'run_count' => 1,
        ]);

        $childInstance = WorkflowInstance::create([
            'id' => 'waterline-child-replay-block-child',
            'workflow_class' => 'ChildWorkflowClass',
            'workflow_type' => 'workflow.child',
            'run_count' => 1,
        ]);

        $parentRun = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNCHILDBLOCK1',
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
            'id' => '01JTESTFLOWRUNCHILDBLOCK2',
            'workflow_instance_id' => $childInstance->id,
            'run_number' => 1,
            'workflow_class' => 'ChildWorkflowClass',
            'workflow_type' => 'workflow.child',
            'status' => 'completed',
            'closed_reason' => 'completed',
            'arguments' => Serializer::serialize([]),
            'output' => Serializer::serialize(['ok' => true]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(3),
            'closed_at' => now()->subMinute(),
            'last_progress_at' => now()->subMinute(),
        ]);

        $parentInstance->update(['current_run_id' => $parentRun->id]);
        $childInstance->update(['current_run_id' => $childRun->id]);

        $link = WorkflowLink::create([
            'id' => '01JTESTFLOWLINKCHILDBLOCK',
            'link_type' => 'child_workflow',
            'sequence' => 1,
            'parent_workflow_instance_id' => $parentInstance->id,
            'parent_workflow_run_id' => $parentRun->id,
            'child_workflow_instance_id' => $childInstance->id,
            'child_workflow_run_id' => $childRun->id,
            'is_primary_parent' => true,
        ]);

        WorkflowTask::create([
            'workflow_run_id' => $parentRun->id,
            'task_type' => 'workflow',
            'status' => 'failed',
            'available_at' => now()->subMinute(),
            'payload' => [
                'workflow_wait_kind' => 'child',
                'open_wait_id' => 'child:' . $link->id,
                'resume_source_kind' => 'child_workflow_run',
                'resume_source_id' => $childRun->id,
                'workflow_sequence' => 1,
                'child_call_id' => $link->id,
                'child_workflow_run_id' => $childRun->id,
                'replay_blocked' => true,
                'replay_blocked_reason' => 'history_shape_mismatch',
                'replay_blocked_workflow_sequence' => 1,
                'replay_blocked_expected_history_shape' => 'child workflow',
                'replay_blocked_recorded_event_types' => ['no typed history'],
            ],
            'last_error' => 'Workflow history at workflow sequence 1 recorded [no typed history], but the current workflow yielded child workflow.',
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        RunSummaryProjector::project($parentRun->fresh([
            'instance',
            'tasks',
            'activityExecutions',
            'timers',
            'failures',
            'historyEvents',
            'childLinks.childRun.instance.currentRun',
            'childLinks.childRun.failures',
            'childLinks.childRun.historyEvents',
        ]));

        $response = $this->get('/waterline/api/flows/' . $parentRun->id);

        $response
            ->assertOk()
            ->assertJsonPath('liveness_state', 'workflow_replay_blocked')
            ->assertJsonPath('can_repair', true)
            ->assertJsonPath('tasks.0.status', 'failed')
            ->assertJsonPath('tasks.0.transport_state', 'replay_blocked')
            ->assertJsonPath('tasks.0.workflow_wait_kind', 'child')
            ->assertJsonPath('tasks.0.workflow_open_wait_id', 'child:' . $link->id)
            ->assertJsonPath('tasks.0.workflow_resume_source_kind', 'child_workflow_run')
            ->assertJsonPath('tasks.0.workflow_resume_source_id', $childRun->id)
            ->assertJsonPath('tasks.0.child_call_id', $link->id)
            ->assertJsonPath('tasks.0.child_workflow_run_id', $childRun->id)
            ->assertJsonPath('tasks.0.replay_blocked', true)
            ->assertJsonPath('tasks.0.replay_blocked_reason', 'history_shape_mismatch')
            ->assertJsonPath('tasks.0.replay_blocked_workflow_sequence', 1)
            ->assertJsonPath('tasks.0.replay_blocked_expected_history_shape', 'child workflow')
            ->assertJsonPath('tasks.0.replay_blocked_recorded_event_types', ['no typed history']);

        $this->assertStringContainsString(
            'history recorded [no typed history]',
            (string) $response->json('liveness_reason')
        );
    }

    public function testShowMarksRowOnlyTerminalActivityFallbackAsUnsupported(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'waterline-row-only-terminal-activity',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNACTROWONLY1',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(4),
            'last_progress_at' => now()->subMinute(),
        ]);

        $instance->update(['current_run_id' => $run->id]);

        $execution = ActivityExecution::create([
            'id' => '01JTESTACTROWONLY0000001',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => 'ActivityClass',
            'activity_type' => 'activity.test',
            'status' => 'completed',
            'arguments' => Serializer::serialize(['Taylor']),
            'result' => Serializer::serialize('mutable result'),
            'connection' => 'redis',
            'queue' => 'activities',
            'closed_at' => now()->subMinute(),
        ]);

        RunSummaryProjector::project($run->fresh([
            'instance',
            'tasks',
            'activityExecutions',
            'timers',
            'failures',
            'historyEvents',
            'childLinks.childRun.instance.currentRun',
            'childLinks.childRun.failures',
            'childLinks.childRun.historyEvents',
        ]));

        $this->get('/waterline/api/flows/' . $run->id)
            ->assertOk()
            ->assertJsonPath('liveness_state', 'workflow_replay_blocked')
            ->assertJsonPath('can_repair', true)
            ->assertJsonPath('activities.0.id', $execution->id)
            ->assertJsonPath('activities.0.status', 'unsupported')
            ->assertJsonPath('activities.0.history_authority', 'unsupported_terminal_without_history')
            ->assertJsonPath(
                'activities.0.history_unsupported_reason',
                'terminal_activity_row_without_typed_history',
            )
            ->assertJsonPath('activities.0.row_status', 'completed')
            ->assertJsonPath('activities.0.result', serialize(null))
            ->assertJsonPath('waits.0.kind', 'activity')
            ->assertJsonPath('waits.0.status', 'unsupported')
            ->assertJsonPath('waits.0.source_status', 'completed')
            ->assertJsonPath(
                'waits.0.history_unsupported_reason',
                'terminal_activity_row_without_typed_history',
            )
            ->assertJsonPath('tasks.0.transport_state', 'missing');
    }

    public function testShowMarksRowOnlyTerminalTimerFallbackAsUnsupported(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'waterline-row-only-terminal-timer',
            'workflow_class' => 'TimerWorkflowClass',
            'workflow_type' => 'workflow.timer',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNTIMERROW01',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'TimerWorkflowClass',
            'workflow_type' => 'workflow.timer',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([60]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(4),
            'last_progress_at' => now()->subMinute(),
        ]);

        $instance->update(['current_run_id' => $run->id]);

        $timer = WorkflowTimer::create([
            'id' => '01JTESTFLOWTIMERROWONLY01',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'status' => 'fired',
            'delay_seconds' => 60,
            'fire_at' => now()->subMinute(),
            'fired_at' => now()->subSeconds(30),
            'created_at' => now()->subMinutes(2),
        ]);

        RunSummaryProjector::project($run->fresh([
            'instance',
            'tasks',
            'activityExecutions',
            'timers',
            'failures',
            'historyEvents',
            'childLinks.childRun.instance.currentRun',
            'childLinks.childRun.failures',
            'childLinks.childRun.historyEvents',
        ]));

        $this->get('/waterline/api/flows/' . $run->id)
            ->assertOk()
            ->assertJsonPath('liveness_state', 'workflow_replay_blocked')
            ->assertJsonPath('can_repair', true)
            ->assertJsonPath('waits.0.kind', 'timer')
            ->assertJsonPath('waits.0.status', 'unsupported')
            ->assertJsonPath('waits.0.source_status', 'fired')
            ->assertJsonPath('waits.0.history_authority', 'unsupported_terminal_without_history')
            ->assertJsonPath('waits.0.history_unsupported_reason', 'terminal_timer_row_without_typed_history')
            ->assertJsonPath('waits.0.row_status', 'fired')
            ->assertJsonPath('timers.0.id', $timer->id)
            ->assertJsonPath('timers.0.status', 'unsupported')
            ->assertJsonPath('timers.0.source_status', 'fired')
            ->assertJsonPath('timers.0.row_status', 'fired')
            ->assertJsonPath('timers.0.history_authority', 'unsupported_terminal_without_history')
            ->assertJsonPath('timers.0.history_unsupported_reason', 'terminal_timer_row_without_typed_history')
            ->assertJsonPath('timers.0.history_event_types', []);
    }

    public function testShowMarksTerminalChildFallbackWithoutParentHistoryAsUnsupported(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $parentInstance = WorkflowInstance::create([
            'id' => 'waterline-row-only-child-parent',
            'workflow_class' => 'ParentWorkflowClass',
            'workflow_type' => 'workflow.parent',
            'run_count' => 1,
        ]);

        $childInstance = WorkflowInstance::create([
            'id' => 'waterline-row-only-child-child',
            'workflow_class' => 'ChildWorkflowClass',
            'workflow_type' => 'workflow.child',
            'run_count' => 1,
        ]);

        $parentRun = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNCHILDROW01',
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
            'id' => '01JTESTFLOWRUNCHILDROW02',
            'workflow_instance_id' => $childInstance->id,
            'run_number' => 1,
            'workflow_class' => 'ChildWorkflowClass',
            'workflow_type' => 'workflow.child',
            'status' => 'completed',
            'closed_reason' => 'completed',
            'arguments' => Serializer::serialize([]),
            'output' => Serializer::serialize(['ok' => true]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(3),
            'closed_at' => now()->subMinute(),
            'last_progress_at' => now()->subMinute(),
        ]);

        $parentInstance->update(['current_run_id' => $parentRun->id]);
        $childInstance->update(['current_run_id' => $childRun->id]);

        $link = WorkflowLink::create([
            'id' => '01JTESTFLOWLINKCHILDROW',
            'link_type' => 'child_workflow',
            'sequence' => 1,
            'parent_workflow_instance_id' => $parentInstance->id,
            'parent_workflow_run_id' => $parentRun->id,
            'child_workflow_instance_id' => $childInstance->id,
            'child_workflow_run_id' => $childRun->id,
            'is_primary_parent' => true,
        ]);

        RunSummaryProjector::project($parentRun->fresh([
            'instance',
            'tasks',
            'activityExecutions',
            'timers',
            'failures',
            'historyEvents',
            'childLinks.childRun.instance.currentRun',
            'childLinks.childRun.failures',
            'childLinks.childRun.historyEvents',
        ]));

        $this->get('/waterline/api/flows/' . $parentRun->id)
            ->assertOk()
            ->assertJsonPath('liveness_state', 'workflow_replay_blocked')
            ->assertJsonPath('can_repair', true)
            ->assertJsonPath('waits.0.kind', 'child')
            ->assertJsonPath('waits.0.status', 'unsupported')
            ->assertJsonPath('waits.0.source_status', 'completed')
            ->assertJsonPath('waits.0.history_authority', 'unsupported_terminal_without_history')
            ->assertJsonPath(
                'waits.0.history_unsupported_reason',
                'terminal_child_link_without_typed_parent_history',
            )
            ->assertJsonPath('waits.0.child_call_id', $link->id)
            ->assertJsonPath('tasks.0.transport_state', 'missing')
            ->assertJsonPath('tasks.0.workflow_wait_kind', 'child')
            ->assertJsonPath('tasks.0.child_call_id', $link->id)
            ->assertJsonPath('tasks.0.child_workflow_run_id', $childRun->id);
    }

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
            'history_event_count' => 42,
            'history_size_bytes' => 65536,
            'continue_as_new_recommended' => true,
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
            'retry_policy' => [
                'snapshot_version' => 1,
                'max_attempts' => 2,
                'backoff_seconds' => [1, 5],
            ],
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
                'exception_type' => 'runtime.failure',
                'exception_class' => \RuntimeException::class,
                'message' => 'boom',
                'exception' => [
                    'type' => 'runtime.failure',
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
            ->assertJsonPath('history_event_count', 42)
            ->assertJsonPath('history_size_bytes', 65536)
            ->assertJsonPath('continue_as_new_recommended', true)
            ->assertJsonPath('declared_signals', [])
            ->assertJsonPath('declared_updates', [])
            ->assertJsonPath('can_issue_terminal_commands', false)
            ->assertJsonPath('can_cancel', false)
            ->assertJsonPath('cancel_blocked_reason', 'run_closed')
            ->assertJsonPath('can_terminate', false)
            ->assertJsonPath('terminate_blocked_reason', 'run_closed')
            ->assertJsonPath('can_signal', false)
            ->assertJsonPath('signal_blocked_reason', 'run_closed')
            ->assertJsonPath('can_update', false)
            ->assertJsonPath('update_blocked_reason', 'run_closed')
            ->assertJsonPath('can_repair', false)
            ->assertJsonPath('repair_blocked_reason', 'run_closed')
            ->assertJsonPath('read_only_reason', 'Run is closed.')
            ->assertJsonPath('activities.0.class', 'ActivityClass')
            ->assertJsonPath('activities.0.idempotency_key', '01JTESTACTIVITY00000000000')
            ->assertJsonPath('activities.0.retry_policy.max_attempts', 2)
            ->assertJsonPath('activities.0.retry_policy.backoff_seconds.1', 5)
            ->assertJsonPath('logs.0.class', 'ActivityClass')
            ->assertJsonPath('exceptions.0.class', 'ActivityClass')
            ->assertJsonPath('exceptions.0.exception_type', 'runtime.failure')
            ->assertJsonPath('exceptions.0.exception_class', \RuntimeException::class)
            ->assertJsonPath('exceptions.0.exception_resolved_class', \RuntimeException::class)
            ->assertJsonPath('exceptions.0.exception_resolution_source', 'recorded_class')
            ->assertJsonPath('exceptions.0.exception_replay_blocked', false)
            ->assertJsonPath('exceptions.0.code', 'trace')
            ->assertJsonPath('commands', [])
            ->assertJsonPath('timeline.0.type', 'ActivityFailed')
            ->assertJsonPath('timeline.0.entry_kind', 'point')
            ->assertJsonPath('timeline.0.source_kind', 'activity_execution')
            ->assertJsonPath('timeline.0.source_id', '01JTESTACTIVITY00000000000')
            ->assertJsonPath('timeline.0.failure_id', '01JTESTFAILURE000000000001')
            ->assertJsonPath('timeline.0.exception_type', 'runtime.failure')
            ->assertJsonPath('timeline.0.exception_resolved_class', \RuntimeException::class)
            ->assertJsonPath('timeline.0.exception_resolution_source', 'recorded_class')
            ->assertJsonPath('timeline.0.failure.exception_type', 'runtime.failure')
            ->assertJsonPath('timeline.0.failure.exception_resolved_class', \RuntimeException::class)
            ->assertJsonPath('timeline.0.failure.exception_resolution_source', 'recorded_class')
            ->assertJsonPath('timeline.0.failure.exception_replay_blocked', false)
            ->assertJsonPath('timeline.0.activity_status', 'failed')
            ->assertJsonPath('timeline.0.failure.handled', false)
            ->assertJsonPath('chartData.0.type', 'Workflow')
            ->assertJsonPath('chartData.1.type', 'Activity');

        $this->assertSame(\RuntimeException::class, $exception['__constructor']);
        $this->assertSame('runtime.failure', $exception['type']);
        $this->assertSame('boom', $exception['message']);
        $this->assertSame(422, $exception['code']);
        $this->assertCount(1, $exception['trace']);
        $this->assertSame('handle', $exception['trace'][0]['function']);
        $this->assertSame('orderId', $exception['properties'][0]['name']);
        $this->assertSame('order-123', $exception['properties'][0]['value']);
    }

    public function testShowIncludesWorkflowDefinitionFingerprintState(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => '01JTESTFLOWINSTANCEDEFSTATE01',
            'workflow_class' => TestOperatorCommandWorkflow::class,
            'workflow_type' => 'workflow.operator-command',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNDEFSTATE0001',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestOperatorCommandWorkflow::class,
            'workflow_type' => 'workflow.operator-command',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(10),
            'last_progress_at' => now()->subMinute(),
        ]);

        $instance->update(['current_run_id' => $run->id]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYDEFSTATE000001',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::WorkflowStarted->value,
            'payload' => [
                'workflow_class' => TestOperatorCommandWorkflow::class,
                'workflow_type' => 'workflow.operator-command',
                'workflow_definition_fingerprint' => WorkflowDefinition::fingerprint(TestCommandContractWorkflow::class),
            ],
            'recorded_at' => now()->subMinutes(10),
        ]);

        $run->forceFill([
            'last_history_sequence' => 1,
        ])->save();

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->get('/waterline/api/flows/' . $run->id)
            ->assertStatus(200)
            ->assertJsonPath('timeline_projection_source', 'workflow_run_timeline_entries')
            ->assertJsonPath(
                'workflow_definition_fingerprint',
                WorkflowDefinition::fingerprint(TestCommandContractWorkflow::class)
            )
            ->assertJsonPath(
                'workflow_definition_current_fingerprint',
                WorkflowDefinition::fingerprint(TestOperatorCommandWorkflow::class)
            )
            ->assertJsonPath('workflow_definition_matches_current', false);

        $this->get('/waterline/api/flows/' . $run->id)
            ->assertStatus(200)
            ->assertJsonPath('workflow_determinism_status', 'warning')
            ->assertJsonPath('workflow_determinism_source', 'definition_drift')
            ->assertJsonPath('workflow_determinism_findings.0.rule', 'workflow_definition_drift')
            ->assertJsonPath('workflow_determinism_findings.0.symbol', 'workflow.operator-command')
            ->assertJsonPath('workflow_determinism_findings.0.file', null)
            ->assertJsonPath('workflow_determinism_findings.0.line', null);
    }

    public function testShowExposesTypedFailureHandledTimelineEntries(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => '01JTESTFLOWINSTANCEHANDLED01',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNHANDLED000001',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'completed',
            'closed_reason' => 'completed',
            'arguments' => Serializer::serialize([]),
            'output' => Serializer::serialize('recovered'),
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
            'id' => '01JTESTACTIVITYHANDLED0001',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => 'ActivityClass',
            'activity_type' => 'activity.test',
            'status' => 'failed',
            'arguments' => Serializer::serialize([]),
            'exception' => Serializer::serialize([
                'class' => \RuntimeException::class,
                'message' => 'recoverable boom',
                'code' => 0,
                'file' => __FILE__,
                'line' => 42,
                'trace' => [],
                'properties' => [],
            ]),
            'started_at' => now()->subMinutes(9),
            'closed_at' => now()->subMinutes(8),
        ]);

        WorkflowFailure::create([
            'id' => '01JTESTFAILUREHANDLED00001',
            'workflow_run_id' => $run->id,
            'source_kind' => 'activity_execution',
            'source_id' => '01JTESTACTIVITYHANDLED0001',
            'propagation_kind' => 'activity',
            'handled' => false,
            'exception_class' => \RuntimeException::class,
            'message' => 'recoverable boom',
            'file' => __FILE__,
            'line' => 42,
            'trace_preview' => '',
        ]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYHANDLEDFAIL01',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::ActivityFailed->value,
            'payload' => [
                'activity_execution_id' => '01JTESTACTIVITYHANDLED0001',
                'activity_class' => 'ActivityClass',
                'activity_type' => 'activity.test',
                'sequence' => 1,
                'failure_id' => '01JTESTFAILUREHANDLED00001',
                'exception_type' => 'runtime.failure',
                'exception_class' => \RuntimeException::class,
                'message' => 'recoverable boom',
                'exception' => [
                    'type' => 'runtime.failure',
                    'class' => \RuntimeException::class,
                    'message' => 'recoverable boom',
                    'code' => 0,
                    'file' => __FILE__,
                    'line' => 42,
                    'trace' => [],
                    'properties' => [],
                ],
            ],
            'recorded_at' => now()->subMinutes(7),
        ]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYHANDLEDOK001',
            'workflow_run_id' => $run->id,
            'sequence' => 2,
            'event_type' => HistoryEventType::FailureHandled->value,
            'payload' => [
                'failure_id' => '01JTESTFAILUREHANDLED00001',
                'sequence' => 1,
                'source_kind' => 'activity_execution',
                'source_id' => '01JTESTACTIVITYHANDLED0001',
                'propagation_kind' => 'activity',
                'exception_type' => 'runtime.failure',
                'exception_class' => \RuntimeException::class,
                'message' => 'recoverable boom',
                'handled' => true,
            ],
            'recorded_at' => now()->subMinutes(6),
        ]);

        $this->get('/waterline/api/flows/' . $run->id)
            ->assertStatus(200)
            ->assertJsonPath('timeline.1.type', 'FailureHandled')
            ->assertJsonPath('timeline.1.kind', 'failure')
            ->assertJsonPath('timeline.1.source_kind', 'workflow_failure')
            ->assertJsonPath('timeline.1.source_id', '01JTESTFAILUREHANDLED00001')
            ->assertJsonPath('timeline.1.failure_id', '01JTESTFAILUREHANDLED00001')
            ->assertJsonPath('timeline.1.summary', 'Handled failure: recoverable boom.')
            ->assertJsonPath('timeline.1.exception_type', 'runtime.failure')
            ->assertJsonPath('timeline.1.failure.exception_type', 'runtime.failure')
            ->assertJsonPath('timeline.1.failure.handled', true);
    }

    public function testShowProjectsParentChildFailuresFromTypedHistoryWhenChildFailureRowIsMissing(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => '01JTESTCHILDFAILPARENT01',
            'workflow_class' => 'ParentWorkflowClass',
            'workflow_type' => 'workflow.parent',
            'run_count' => 1,
        ]);
        $childInstance = WorkflowInstance::create([
            'id' => '01JTESTCHILDFAILCHILD001',
            'workflow_class' => 'ChildWorkflowClass',
            'workflow_type' => 'workflow.child',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTCHILDFAILRUN00001',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'ParentWorkflowClass',
            'workflow_type' => 'workflow.parent',
            'status' => 'completed',
            'closed_reason' => 'completed',
            'arguments' => Serializer::serialize([]),
            'output' => Serializer::serialize('recovered'),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(10),
            'closed_at' => now()->subMinutes(5),
            'last_progress_at' => now()->subMinutes(5),
        ]);
        $childRun = WorkflowRun::create([
            'id' => '01JTESTCHILDFAILCHILDRUN1',
            'workflow_instance_id' => $childInstance->id,
            'run_number' => 1,
            'workflow_class' => 'ChildWorkflowClass',
            'workflow_type' => 'workflow.child',
            'status' => 'failed',
            'closed_reason' => 'failed',
            'arguments' => Serializer::serialize([]),
            'output' => null,
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(9),
            'closed_at' => now()->subMinutes(8),
            'last_progress_at' => now()->subMinutes(8),
        ]);

        $instance->update(['current_run_id' => $run->id]);
        $childInstance->update(['current_run_id' => $childRun->id]);

        WorkflowRunSummary::create([
            'id' => $run->id,
            'workflow_instance_id' => $instance->id,
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
            'started_at' => $run->started_at,
            'closed_at' => $run->closed_at,
            'duration_ms' => 300000,
            'exception_count' => 1,
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(5),
        ]);

        WorkflowLink::create([
            'id' => '01JTESTCHILDFAILLINK0001',
            'link_type' => 'child_workflow',
            'sequence' => 1,
            'parent_workflow_instance_id' => $instance->id,
            'parent_workflow_run_id' => $run->id,
            'child_workflow_instance_id' => $childInstance->id,
            'child_workflow_run_id' => $childRun->id,
            'is_primary_parent' => true,
        ]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTCHILDFAILHISTORY01',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::ChildRunFailed->value,
            'payload' => [
                'sequence' => 1,
                'failure_id' => '01JTESTCHILDFAILFAILURE01',
                'child_call_id' => '01JTESTCHILDFAILLINK0001',
                'child_workflow_instance_id' => $childInstance->id,
                'child_workflow_run_id' => $childRun->id,
                'child_workflow_class' => 'ChildWorkflowClass',
                'child_workflow_type' => 'workflow.child',
                'child_status' => 'failed',
                'exception_class' => \RuntimeException::class,
                'message' => 'child boom',
                'code' => 503,
                'exception' => [
                    'class' => \RuntimeException::class,
                    'message' => 'child boom',
                    'code' => 503,
                    'file' => __FILE__,
                    'line' => 42,
                    'trace' => [],
                    'properties' => [],
                ],
            ],
            'recorded_at' => now()->subMinutes(7),
        ]);
        WorkflowHistoryEvent::create([
            'id' => '01JTESTCHILDFAILHANDLED1',
            'workflow_run_id' => $run->id,
            'sequence' => 2,
            'event_type' => HistoryEventType::FailureHandled->value,
            'payload' => [
                'failure_id' => '01JTESTCHILDFAILFAILURE01',
                'sequence' => 1,
                'source_kind' => 'child_workflow_run',
                'source_id' => $childRun->id,
                'propagation_kind' => 'child',
                'exception_class' => \RuntimeException::class,
                'message' => 'child boom',
                'handled' => true,
            ],
            'recorded_at' => now()->subMinutes(6),
        ]);

        $response = $this->get('/waterline/api/flows/' . $run->id);
        $exception = unserialize($response->json('exceptions.0.exception'));

        $response
            ->assertStatus(200)
            ->assertJsonPath('exception_count', 1)
            ->assertJsonPath('exceptions_count', 1)
            ->assertJsonPath('exceptions.0.class', \RuntimeException::class)
            ->assertJsonPath('exceptions.0.exception_class', \RuntimeException::class)
            ->assertJsonPath('exceptions.0.exception_resolved_class', \RuntimeException::class)
            ->assertJsonPath('exceptions.0.exception_resolution_source', 'recorded_class')
            ->assertJsonPath('exceptions.0.exception_replay_blocked', false)
            ->assertJsonPath('timeline.0.type', 'ChildRunFailed')
            ->assertJsonPath('timeline.0.source_kind', 'child_workflow_run')
            ->assertJsonPath('timeline.0.source_id', $childRun->id)
            ->assertJsonPath('timeline.0.failure_id', '01JTESTCHILDFAILFAILURE01')
            ->assertJsonPath('timeline.0.failure.propagation_kind', 'child')
            ->assertJsonPath('timeline.0.failure.handled', true)
            ->assertJsonPath('timeline.1.type', 'FailureHandled')
            ->assertJsonPath('timeline.1.failure.handled', true);

        $this->assertSame(\RuntimeException::class, $exception['__constructor']);
        $this->assertSame('child boom', $exception['message']);
        $this->assertSame(503, $exception['code']);
    }

    public function testShowUsesTypedActivityHistoryWhenActivityRowIsMissing(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'activity-history-fallback',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNACTIVITYHIST001',
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
            'started_at' => now()->subMinutes(6),
            'closed_at' => now()->subMinutes(2),
            'last_progress_at' => now()->subMinutes(2),
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
            'duration_ms' => 240000,
            'created_at' => now()->subMinutes(6),
            'updated_at' => now()->subMinutes(2),
        ]);

        $scheduledAt = now()->subMinutes(5);
        $startedAt = now()->subMinutes(4);
        $closedAt = now()->subMinutes(3);
        $activityId = '01JTESTACTIVITYHISTORYONLY01';

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYACTIVITYONLY01A',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::ActivityScheduled->value,
            'payload' => [
                'activity_execution_id' => $activityId,
                'activity_class' => 'ActivityClass',
                'activity_type' => 'activity.test',
                'sequence' => 1,
                'activity' => [
                    'id' => $activityId,
                    'sequence' => 1,
                    'type' => 'activity.test',
                    'class' => 'ActivityClass',
                    'status' => 'pending',
                    'attempt_count' => 0,
                    'connection' => 'redis',
                    'queue' => 'default',
                    'created_at' => $scheduledAt->jsonSerialize(),
                    'arguments' => Serializer::serialize(['Taylor']),
                ],
            ],
            'recorded_at' => $scheduledAt,
        ]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYACTIVITYONLY01B',
            'workflow_run_id' => $run->id,
            'sequence' => 2,
            'event_type' => HistoryEventType::ActivityCompleted->value,
            'payload' => [
                'activity_execution_id' => $activityId,
                'activity_class' => 'ActivityClass',
                'activity_type' => 'activity.test',
                'sequence' => 1,
                'result' => Serializer::serialize('Hello, Taylor!'),
                'activity' => [
                    'id' => $activityId,
                    'sequence' => 1,
                    'type' => 'activity.test',
                    'class' => 'ActivityClass',
                    'attempt_id' => '01JTESTATTEMPT000000000001',
                    'status' => 'completed',
                    'attempt_count' => 1,
                    'connection' => 'redis',
                    'queue' => 'default',
                    'created_at' => $scheduledAt->jsonSerialize(),
                    'started_at' => $startedAt->jsonSerialize(),
                    'closed_at' => $closedAt->jsonSerialize(),
                    'arguments' => Serializer::serialize(['Taylor']),
                    'result' => Serializer::serialize('Hello, Taylor!'),
                ],
            ],
            'recorded_at' => $closedAt,
        ]);

        $response = $this->get('/waterline/api/instances/' . $instance->id);

        $response
            ->assertOk()
            ->assertJsonPath('activities.0.class', 'ActivityClass')
            ->assertJsonPath('activities.0.type', 'activity.test')
            ->assertJsonPath('activities.0.status', 'completed')
            ->assertJsonPath('activities.0.attempt_id', '01JTESTATTEMPT000000000001')
            ->assertJsonPath('activities.0.attempt_count', 1)
            ->assertJsonPath('activities.0.attempts.0.id', '01JTESTATTEMPT000000000001')
            ->assertJsonPath('activities.0.attempts.0.attempt_number', 1)
            ->assertJsonPath('activities.0.attempts.0.status', 'completed')
            ->assertJsonPath('activities.0.connection', 'redis')
            ->assertJsonPath('activities.0.queue', 'default')
            ->assertJsonPath('activities.0.started_at', $startedAt->jsonSerialize())
            ->assertJsonPath('activities.0.last_heartbeat_at', null)
            ->assertJsonPath('activities.0.closed_at', $closedAt->jsonSerialize())
            ->assertJsonPath('logs.0.class', 'ActivityClass')
            ->assertJsonPath('chartData.1.type', 'Activity')
            ->assertJsonPath('chartData.1.x', 'ActivityClass');

        $this->assertSame('Hello, Taylor!', unserialize($response->json('activities.0.result')));
        $this->assertSame(['Taylor'], unserialize($response->json('activities.0.arguments')));
        $this->assertSame('Hello, Taylor!', unserialize($response->json('logs.0.result')));
    }

    public function testShowKeepsTypedFailureDetailWhenFailureRowIsMissing(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'failure-history-fallback',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNFAILUREHIST001',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'failed',
            'closed_reason' => 'failed',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(6),
            'closed_at' => now()->subMinutes(3),
            'last_progress_at' => now()->subMinutes(3),
        ]);

        $instance->update(['current_run_id' => $run->id]);

        ActivityExecution::create([
            'id' => '01JTESTACTIVITYFAILHISTORY01',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => 'ActivityClass',
            'activity_type' => 'activity.test',
            'status' => 'failed',
            'arguments' => Serializer::serialize(['Taylor']),
            'started_at' => now()->subMinutes(5),
            'closed_at' => now()->subMinutes(4),
        ]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYFAILUREONLY01',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::ActivityFailed->value,
            'payload' => [
                'activity_execution_id' => '01JTESTACTIVITYFAILHISTORY01',
                'activity_class' => 'ActivityClass',
                'activity_type' => 'activity.test',
                'sequence' => 1,
                'failure_id' => '01JTESTFAILUREHISTORYONLY01',
                'exception_type' => 'runtime.failure',
                'exception_class' => \RuntimeException::class,
                'message' => 'history-only boom',
                'exception' => [
                    'type' => 'runtime.failure',
                    'class' => \RuntimeException::class,
                    'message' => 'history-only boom',
                    'code' => 422,
                    'file' => __FILE__,
                    'line' => 77,
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
                'activity' => [
                    'id' => '01JTESTACTIVITYFAILHISTORY01',
                    'sequence' => 1,
                    'type' => 'activity.test',
                    'class' => 'ActivityClass',
                    'status' => 'failed',
                    'attempt_count' => 1,
                    'connection' => 'redis',
                    'queue' => 'default',
                    'started_at' => now()->subMinutes(5)->jsonSerialize(),
                    'closed_at' => now()->subMinutes(4)->jsonSerialize(),
                ],
            ],
            'recorded_at' => now()->subMinutes(4),
        ]);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $response = $this->get('/waterline/api/flows/' . $run->id);
        $exception = unserialize($response->json('exceptions.0.exception'));

        $response
            ->assertStatus(200)
            ->assertJsonPath('exception_count', 1)
            ->assertJsonPath('exceptions_count', 1)
            ->assertJsonPath('exceptions.0.id', '01JTESTFAILUREHISTORYONLY01')
            ->assertJsonPath('exceptions.0.class', 'ActivityClass')
            ->assertJsonPath('exceptions.0.exception_type', 'runtime.failure')
            ->assertJsonPath('timeline.0.type', 'ActivityFailed')
            ->assertJsonPath('timeline.0.failure_id', '01JTESTFAILUREHISTORYONLY01')
            ->assertJsonPath('timeline.0.failure.exception_type', 'runtime.failure')
            ->assertJsonPath('timeline.0.failure.exception_class', \RuntimeException::class)
            ->assertJsonPath('timeline.0.failure.message', 'history-only boom')
            ->assertJsonPath('timeline.0.failure.file', __FILE__)
            ->assertJsonPath('timeline.0.failure.line', 77);

        $this->assertSame(\RuntimeException::class, $exception['__constructor']);
        $this->assertSame('runtime.failure', $exception['type']);
        $this->assertSame('history-only boom', $exception['message']);
        $this->assertSame(422, $exception['code']);
        $this->assertSame(
            'order-123',
            collect($exception['properties'] ?? [])->keyBy('name')->get('orderId')['value'] ?? null
        );
    }

    public function testShowFlagsMisconfiguredDurableFailureAliasesAsReplayBlocked(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('workflows.v2.types.exceptions.runtime.failure', \stdClass::class);

        $instance = WorkflowInstance::create([
            'id' => 'failure-misconfigured-alias',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNFAILALIAS01',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'failed',
            'closed_reason' => 'failed',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(6),
            'closed_at' => now()->subMinutes(3),
            'last_progress_at' => now()->subMinutes(3),
        ]);

        $instance->update(['current_run_id' => $run->id]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYFAILALIAS001',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::ActivityFailed->value,
            'payload' => [
                'activity_execution_id' => '01JTESTACTIVITYFAILALIAS1',
                'activity_class' => 'ActivityClass',
                'activity_type' => 'activity.test',
                'sequence' => 1,
                'failure_id' => '01JTESTFAILUREALIAS000001',
                'exception_type' => 'runtime.failure',
                'exception_class' => \RuntimeException::class,
                'message' => 'history-only boom',
                'exception' => [
                    'type' => 'runtime.failure',
                    'class' => \RuntimeException::class,
                    'message' => 'history-only boom',
                    'code' => 422,
                    'file' => __FILE__,
                    'line' => 77,
                    'trace' => [],
                    'properties' => [],
                ],
            ],
            'recorded_at' => now()->subMinutes(4),
        ]);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->get('/waterline/api/flows/' . $run->id)
            ->assertStatus(200)
            ->assertJsonPath('exceptions.0.id', '01JTESTFAILUREALIAS000001')
            ->assertJsonPath('exceptions.0.exception_type', 'runtime.failure')
            ->assertJsonPath('exceptions.0.exception_resolved_class', null)
            ->assertJsonPath('exceptions.0.exception_resolution_source', 'misconfigured')
            ->assertJsonPath('exceptions.0.exception_replay_blocked', true)
            ->assertJsonPath('timeline.0.failure.exception_resolution_source', 'misconfigured')
            ->assertJsonPath('timeline.0.failure.exception_replay_blocked', true)
            ->assertJsonPath('timeline.0.exception_resolution_source', 'misconfigured');
    }

    public function testShowFlagsUnrestorableFailureClassesAsReplayBlocked(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'failure-unrestorable-class',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNFAILUNREST01',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'failed',
            'closed_reason' => 'failed',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(6),
            'closed_at' => now()->subMinutes(3),
            'last_progress_at' => now()->subMinutes(3),
        ]);

        $instance->update(['current_run_id' => $run->id]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYFAILUNREST01',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::ActivityFailed->value,
            'payload' => [
                'activity_execution_id' => '01JTESTACTIVITYFAILUNREST',
                'activity_class' => 'ActivityClass',
                'activity_type' => 'activity.test',
                'sequence' => 1,
                'failure_id' => '01JTESTFAILUREUNREST0001',
                'exception_class' => TestAbstractWaterlineException::class,
                'message' => 'abstract history boom',
                'exception' => [
                    'class' => TestAbstractWaterlineException::class,
                    'message' => 'abstract history boom',
                    'code' => 500,
                    'file' => __FILE__,
                    'line' => 88,
                    'trace' => [],
                    'properties' => [],
                ],
            ],
            'recorded_at' => now()->subMinutes(4),
        ]);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $response = $this->get('/waterline/api/flows/' . $run->id);

        $response
            ->assertStatus(200)
            ->assertJsonPath('exceptions.0.id', '01JTESTFAILUREUNREST0001')
            ->assertJsonPath('exceptions.0.exception_class', TestAbstractWaterlineException::class)
            ->assertJsonPath('exceptions.0.exception_resolved_class', TestAbstractWaterlineException::class)
            ->assertJsonPath('exceptions.0.exception_resolution_source', 'unrestorable')
            ->assertJsonPath('exceptions.0.exception_replay_blocked', true)
            ->assertJsonPath('timeline.0.failure.exception_resolution_source', 'unrestorable')
            ->assertJsonPath('timeline.0.failure.exception_replay_blocked', true)
            ->assertJsonPath('timeline.0.exception_resolution_source', 'unrestorable');

        $this->assertStringContainsString(
            'abstract throwable',
            (string) $response->json('exceptions.0.exception_resolution_error')
        );
    }

    public function testShowKeepsMultipleReplayBlockedFailuresOrdered(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => (string) Str::ulid(),
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);
        $run = WorkflowRun::create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'failed',
            'closed_reason' => 'failed',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(10),
            'closed_at' => now()->subMinutes(2),
            'last_progress_at' => now()->subMinutes(2),
        ]);

        $instance->update(['current_run_id' => $run->id]);

        $earlierFailureId = (string) Str::ulid();
        $laterFailureId = (string) Str::ulid();

        WorkflowFailure::create([
            'id' => $earlierFailureId,
            'workflow_run_id' => $run->id,
            'source_kind' => 'activity_execution',
            'source_id' => 'activity-earlier',
            'propagation_kind' => 'activity',
            'handled' => false,
            'exception_class' => 'App\\Legacy\\EarlierFailure',
            'message' => 'earlier replay-blocked failure',
            'file' => __FILE__,
            'line' => 42,
            'trace_preview' => 'earlier trace',
            'created_at' => now()->subMinutes(8),
            'updated_at' => now()->subMinutes(8),
        ]);
        WorkflowFailure::create([
            'id' => $laterFailureId,
            'workflow_run_id' => $run->id,
            'source_kind' => 'activity_execution',
            'source_id' => 'activity-later',
            'propagation_kind' => 'activity',
            'handled' => false,
            'exception_class' => TestAbstractWaterlineException::class,
            'message' => 'later replay-blocked failure',
            'file' => __FILE__,
            'line' => 84,
            'trace_preview' => 'later trace',
            'created_at' => now()->subMinutes(6),
            'updated_at' => now()->subMinutes(6),
        ]);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $response = $this->get('/waterline/api/flows/' . $run->id);

        $response
            ->assertStatus(200)
            ->assertJsonPath('exception_count', 2)
            ->assertJsonPath('exceptions_count', 2)
            ->assertJsonPath('exceptions.0.id', $earlierFailureId)
            ->assertJsonPath('exceptions.0.exception_resolution_source', 'unresolved')
            ->assertJsonPath('exceptions.0.exception_replay_blocked', true)
            ->assertJsonPath('exceptions.1.id', $laterFailureId)
            ->assertJsonPath('exceptions.1.exception_resolution_source', 'unrestorable')
            ->assertJsonPath('exceptions.1.exception_replay_blocked', true);
    }

    public function testShowReturnsLiveHeartbeatMetadataForRunningActivity(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $startedAt = now()->subMinutes(4);
        $heartbeatAt = now()->subMinute();
        $leaseExpiresAt = now()->addMinutes(ActivityLease::DURATION_MINUTES);
        $runId = (string) Str::ulid();
        $activityId = (string) Str::ulid();
        $taskId = (string) Str::ulid();
        $attemptId = (string) Str::ulid();

        $instance = WorkflowInstance::create([
            'id' => 'order-heartbeat-live',
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
            'status' => 'running',
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => $startedAt,
            'last_progress_at' => $startedAt,
        ]);

        $instance->update(['current_run_id' => $run->id]);

        $activity = ActivityExecution::create([
            'id' => $activityId,
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => 'ActivityClass',
            'activity_type' => 'activity.test',
            'status' => 'running',
            'connection' => 'redis',
            'queue' => 'default',
            'attempt_count' => 1,
            'current_attempt_id' => $attemptId,
            'started_at' => $startedAt,
            'last_heartbeat_at' => $heartbeatAt,
        ]);

        ActivityAttempt::create([
            'id' => $attemptId,
            'workflow_run_id' => $run->id,
            'activity_execution_id' => $activity->id,
            'workflow_task_id' => $taskId,
            'attempt_number' => 1,
            'status' => 'running',
            'lease_owner' => 'heartbeat-worker',
            'started_at' => $startedAt,
            'last_heartbeat_at' => $heartbeatAt,
            'lease_expires_at' => $leaseExpiresAt,
        ]);

        WorkflowTask::create([
            'id' => $taskId,
            'workflow_run_id' => $run->id,
            'task_type' => 'activity',
            'status' => 'leased',
            'payload' => [
                'activity_execution_id' => $activity->id,
            ],
            'connection' => 'redis',
            'queue' => 'default',
            'leased_at' => $startedAt,
            'lease_owner' => 'heartbeat-worker',
            'lease_expires_at' => $leaseExpiresAt,
            'attempt_count' => 1,
        ]);

        WorkflowHistoryEvent::create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::ActivityHeartbeatRecorded->value,
            'payload' => [
                'activity_execution_id' => $activity->id,
                'activity_attempt_id' => $attemptId,
                'activity_class' => 'ActivityClass',
                'activity_type' => 'activity.test',
                'sequence' => 1,
                'attempt_number' => 1,
                'heartbeat_at' => $heartbeatAt->toJSON(),
                'lease_expires_at' => $leaseExpiresAt->toJSON(),
                'activity' => ActivitySnapshot::fromExecution($activity),
                'activity_attempt' => [
                    'id' => $attemptId,
                    'attempt_number' => 1,
                    'status' => 'running',
                    'task_id' => $taskId,
                    'lease_owner' => 'heartbeat-worker',
                    'started_at' => $startedAt->toJSON(),
                    'last_heartbeat_at' => $heartbeatAt->toJSON(),
                    'lease_expires_at' => $leaseExpiresAt->toJSON(),
                ],
            ],
            'workflow_task_id' => $taskId,
            'recorded_at' => $heartbeatAt,
        ]);

        RunSummaryProjector::project($run->fresh([
            'instance',
            'tasks',
            'activityExecutions',
            'timers',
            'failures',
            'historyEvents',
        ]));

        $response = $this->get('/waterline/api/instances/' . $instance->id);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'running')
            ->assertJsonPath('wait_kind', 'activity')
            ->assertJsonPath('liveness_state', 'activity_task_leased')
            ->assertJsonPath('activities.0.id', $activity->id)
            ->assertJsonPath('activities.0.status', 'running')
            ->assertJsonPath('activities.0.attempt_id', $attemptId)
            ->assertJsonPath('activities.0.attempt_count', 1)
            ->assertJsonPath('activities.0.last_heartbeat_at', $heartbeatAt->jsonSerialize())
            ->assertJsonPath('activities.0.attempts.0.id', $attemptId)
            ->assertJsonPath('activities.0.attempts.0.attempt_number', 1)
            ->assertJsonPath('activities.0.attempts.0.status', 'running')
            ->assertJsonPath('activities.0.attempts.0.lease_owner', 'heartbeat-worker')
            ->assertJsonPath('activities.0.attempts.0.lease_expires_at', $leaseExpiresAt->jsonSerialize())
            ->assertJsonPath('activities.0.attempts.0.can_continue', true)
            ->assertJsonPath('activities.0.attempts.0.cancel_requested', false)
            ->assertJsonPath('activities.0.attempts.0.stop_reason', null)
            ->assertJsonPath('tasks.0.id', $taskId)
            ->assertJsonPath('tasks.0.status', 'leased')
            ->assertJsonPath('tasks.0.lease_expires_at', $leaseExpiresAt->jsonSerialize())
            ->assertJsonPath('next_task_lease_expires_at', $leaseExpiresAt->jsonSerialize())
            ->assertJsonPath('timeline.0.type', 'ActivityHeartbeatRecorded')
            ->assertJsonPath('timeline.0.kind', 'activity')
            ->assertJsonPath('timeline.0.source_kind', 'activity_execution')
            ->assertJsonPath('timeline.0.source_id', $activity->id)
            ->assertJsonPath('timeline.0.activity_status', 'running')
            ->assertJsonPath('timeline.0.activity.last_heartbeat_at', $heartbeatAt->jsonSerialize())
            ->assertJsonPath('timeline.0.task.status', 'leased');
    }

    public function testShowReturnsActivityAttemptCancellationObservationFields(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $startedAt = now()->subMinutes(4);
        $closedAt = now()->subMinute();
        $runId = (string) Str::ulid();
        $activityId = (string) Str::ulid();
        $taskId = (string) Str::ulid();
        $attemptId = (string) Str::ulid();

        $instance = WorkflowInstance::create([
            'id' => 'order-activity-cancel-observed',
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
            'status' => 'cancelled',
            'closed_reason' => 'cancelled',
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => $startedAt,
            'closed_at' => $closedAt,
            'last_progress_at' => $closedAt,
        ]);

        $instance->update(['current_run_id' => $run->id]);

        $activity = ActivityExecution::create([
            'id' => $activityId,
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => 'ActivityClass',
            'activity_type' => 'activity.test',
            'status' => 'cancelled',
            'connection' => 'redis',
            'queue' => 'default',
            'attempt_count' => 1,
            'current_attempt_id' => $attemptId,
            'started_at' => $startedAt,
            'closed_at' => $closedAt,
        ]);

        ActivityAttempt::create([
            'id' => $attemptId,
            'workflow_run_id' => $run->id,
            'activity_execution_id' => $activity->id,
            'workflow_task_id' => $taskId,
            'attempt_number' => 1,
            'status' => 'cancelled',
            'lease_owner' => 'bridge-worker',
            'started_at' => $startedAt,
            'closed_at' => $closedAt,
        ]);

        WorkflowTask::create([
            'id' => $taskId,
            'workflow_run_id' => $run->id,
            'task_type' => 'activity',
            'status' => 'cancelled',
            'payload' => [
                'activity_execution_id' => $activity->id,
            ],
            'connection' => 'redis',
            'queue' => 'default',
            'leased_at' => $startedAt,
            'lease_owner' => 'bridge-worker',
            'attempt_count' => 1,
        ]);

        RunSummaryProjector::project($run->fresh([
            'instance',
            'tasks',
            'activityExecutions',
            'timers',
            'failures',
            'historyEvents',
        ]));

        $response = $this->get('/waterline/api/instances/' . $instance->id);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('activities.0.status', 'unsupported')
            ->assertJsonPath('activities.0.row_status', 'cancelled')
            ->assertJsonPath('activities.0.history_authority', 'unsupported_terminal_without_history')
            ->assertJsonPath('activities.0.history_unsupported_reason', 'terminal_activity_row_without_typed_history')
            ->assertJsonPath('activities.0.attempts.0.id', $attemptId)
            ->assertJsonPath('activities.0.attempts.0.status', 'cancelled')
            ->assertJsonPath('activities.0.attempts.0.can_continue', false)
            ->assertJsonPath('activities.0.attempts.0.cancel_requested', true)
            ->assertJsonPath('activities.0.attempts.0.stop_reason', 'attempt_cancelled')
            ->assertJsonPath('waits.0.status', 'unsupported')
            ->assertJsonPath('waits.0.source_status', 'cancelled')
            ->assertJsonPath('waits.0.history_unsupported_reason', 'terminal_activity_row_without_typed_history');
    }

    public function testShowReturnsBackfilledHeartbeatMetadataForLegacyRunningActivity(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $startedAt = now()->subMinutes(4);
        $heartbeatAt = now()->subMinute();
        $leaseExpiresAt = now()->addMinutes(ActivityLease::DURATION_MINUTES);

        $instance = WorkflowInstance::create([
            'id' => 'order-heartbeat-backfilled',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'running',
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => $startedAt,
            'last_progress_at' => $startedAt,
        ]);

        $instance->update(['current_run_id' => $run->id]);

        $activity = ActivityExecution::create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => 'ActivityClass',
            'activity_type' => 'activity.test',
            'status' => 'running',
            'connection' => 'redis',
            'queue' => 'default',
            'attempt_count' => 0,
            'started_at' => $startedAt,
            'last_heartbeat_at' => $heartbeatAt,
        ]);

        $task = WorkflowTask::create([
            'workflow_run_id' => $run->id,
            'task_type' => 'activity',
            'status' => 'leased',
            'payload' => [
                'activity_execution_id' => $activity->id,
            ],
            'connection' => 'redis',
            'queue' => 'default',
            'leased_at' => $startedAt,
            'lease_owner' => 'backfilled-heartbeat-worker',
            'lease_expires_at' => $leaseExpiresAt,
            'attempt_count' => 1,
        ]);

        $migration = require dirname(__DIR__, 3) . '/workflow/src/migrations/2026_04_08_000125_backfill_activity_attempt_identity.php';
        $migration->up();

        $activity->refresh();

        RunSummaryProjector::project($run->fresh([
            'instance',
            'tasks',
            'activityExecutions',
            'timers',
            'failures',
            'historyEvents',
        ]));

        $response = $this->get('/waterline/api/instances/' . $instance->id);

        $response
            ->assertOk()
            ->assertJsonPath('wait_kind', 'activity')
            ->assertJsonPath('liveness_state', 'activity_task_leased')
            ->assertJsonPath('activities.0.id', $activity->id)
            ->assertJsonPath('activities.0.status', 'running')
            ->assertJsonPath('activities.0.attempt_id', $activity->current_attempt_id)
            ->assertJsonPath('activities.0.attempt_count', 1)
            ->assertJsonPath('activities.0.last_heartbeat_at', $heartbeatAt->jsonSerialize())
            ->assertJsonPath('activities.0.attempts.0.id', $activity->current_attempt_id)
            ->assertJsonPath('activities.0.attempts.0.attempt_number', 1)
            ->assertJsonPath('activities.0.attempts.0.status', 'running')
            ->assertJsonPath('activities.0.attempts.0.lease_owner', 'backfilled-heartbeat-worker')
            ->assertJsonPath('activities.0.attempts.0.last_heartbeat_at', $heartbeatAt->jsonSerialize())
            ->assertJsonPath('activities.0.attempts.0.lease_expires_at', $leaseExpiresAt->jsonSerialize())
            ->assertJsonPath('tasks.0.id', $task->id)
            ->assertJsonPath('tasks.0.status', 'leased');
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

    public function testShowReturnsVersionMarkerTimelineEntries(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'version-marker-instance',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNVERSIONMARKER01',
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
            'id' => '01JTESTHISTORYVERSIONMARK01A',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::StartAccepted->value,
            'payload' => [],
            'recorded_at' => now()->subMinute(),
        ]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYVERSIONMARK01B',
            'workflow_run_id' => $run->id,
            'sequence' => 2,
            'event_type' => HistoryEventType::WorkflowStarted->value,
            'payload' => [],
            'recorded_at' => now()->subSeconds(55),
        ]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYVERSIONMARK01C',
            'workflow_run_id' => $run->id,
            'sequence' => 3,
            'event_type' => HistoryEventType::VersionMarkerRecorded->value,
            'payload' => [
                'sequence' => 1,
                'change_id' => 'step-1',
                'version' => 2,
                'min_supported' => -1,
                'max_supported' => 2,
            ],
            'recorded_at' => now()->subSeconds(50),
        ]);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $response = $this->get('/waterline/api/instances/' . $instance->id);

        $response
            ->assertOk()
            ->assertJsonPath('instance_id', $instance->id)
            ->assertJsonPath('selected_run_id', $run->id)
            ->assertJsonPath('timeline.2.type', 'VersionMarkerRecorded')
            ->assertJsonPath('timeline.2.kind', 'version')
            ->assertJsonPath('timeline.2.source_kind', 'version_marker')
            ->assertJsonPath('timeline.2.source_id', 'step-1')
            ->assertJsonPath('timeline.2.workflow_sequence', 1)
            ->assertJsonPath('timeline.2.version_change_id', 'step-1')
            ->assertJsonPath('timeline.2.version', 2)
            ->assertJsonPath('timeline.2.version_min_supported', -1)
            ->assertJsonPath('timeline.2.version_max_supported', 2)
            ->assertJsonPath('timeline.2.summary', 'Recorded version marker step-1 = 2.');
    }

    public function testShowCanResolveCurrentRunFromInstanceId(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-123',
            'workflow_class' => TestCommandContractWorkflow::class,
            'workflow_type' => 'workflow.command-contract',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNCURRENT000001',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestCommandContractWorkflow::class,
            'workflow_type' => 'workflow.command-contract',
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
            'class' => TestCommandContractWorkflow::class,
            'workflow_type' => 'workflow.command-contract',
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
            ->assertJsonPath('declared_signals', ['approved-by', 'rejected-by'])
            ->assertJsonPath('declared_updates', ['mark-approved'])
            ->assertJsonPath('can_issue_terminal_commands', true)
            ->assertJsonPath('can_cancel', true)
            ->assertJsonPath('cancel_blocked_reason', null)
            ->assertJsonPath('can_terminate', true)
            ->assertJsonPath('terminate_blocked_reason', null)
            ->assertJsonPath('can_signal', true)
            ->assertJsonPath('signal_blocked_reason', null)
            ->assertJsonPath('can_update', true)
            ->assertJsonPath('update_blocked_reason', null)
            ->assertJsonPath('can_repair', false)
            ->assertJsonPath('repair_blocked_reason', 'repair_not_needed')
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
            ->assertJsonPath('can_cancel', false)
            ->assertJsonPath('cancel_blocked_reason', 'selected_run_not_current')
            ->assertJsonPath('can_terminate', false)
            ->assertJsonPath('terminate_blocked_reason', 'selected_run_not_current')
            ->assertJsonPath('can_signal', false)
            ->assertJsonPath('signal_blocked_reason', 'selected_run_not_current')
            ->assertJsonPath('can_update', false)
            ->assertJsonPath('update_blocked_reason', 'selected_run_not_current')
            ->assertJsonPath('can_repair', false)
            ->assertJsonPath('repair_blocked_reason', 'selected_run_not_current')
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
            ->assertJsonPath('declared_queries', ['current-stage', 'stageMatches'])
            ->assertJsonPath('declared_query_contracts.0.name', 'current-stage')
            ->assertJsonPath('declared_query_targets.0.name', 'current-stage')
            ->assertJsonPath('declared_query_targets.0.has_contract', true)
            ->assertJsonPath('declared_query_targets.0.parameters', [])
            ->assertJsonPath('declared_query_targets.1.name', 'stageMatches')
            ->assertJsonPath('declared_query_targets.1.has_contract', true)
            ->assertJsonPath('declared_query_targets.1.parameters.0.name', 'stage')
            ->assertJsonPath('can_query', true)
            ->assertJsonPath('query_blocked_reason', null)
            ->assertJsonPath('declared_signals', ['approved-by', 'rejected-by'])
            ->assertJsonPath('declared_signal_contracts.0.name', 'approved-by')
            ->assertJsonPath('declared_signal_contracts.0.parameters.0.name', 'actor')
            ->assertJsonPath('declared_signal_targets.0.name', 'approved-by')
            ->assertJsonPath('declared_signal_targets.0.has_contract', true)
            ->assertJsonPath('declared_signal_targets.1.name', 'rejected-by')
            ->assertJsonPath('declared_signal_targets.1.has_contract', false)
            ->assertJsonPath('declared_signal_targets.1.parameters', [])
            ->assertJsonPath('declared_updates', ['mark-approved'])
            ->assertJsonPath('declared_update_contracts.0.name', 'mark-approved')
            ->assertJsonPath('declared_update_contracts.0.parameters.0.name', 'approved')
            ->assertJsonPath('declared_update_contracts.0.parameters.0.required', true)
            ->assertJsonPath('declared_update_targets.0.name', 'mark-approved')
            ->assertJsonPath('declared_update_targets.0.has_contract', true)
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
            ->assertJsonPath('declared_queries', ['current-stage', 'stageMatches'])
            ->assertJsonPath('declared_query_contracts.0.name', 'current-stage')
            ->assertJsonPath('declared_query_targets.0.name', 'current-stage')
            ->assertJsonPath('declared_query_targets.0.has_contract', true)
            ->assertJsonPath('declared_query_targets.1.name', 'stageMatches')
            ->assertJsonPath('declared_query_targets.1.has_contract', true)
            ->assertJsonPath('can_query', true)
            ->assertJsonPath('query_blocked_reason', null)
            ->assertJsonPath('declared_signals', ['approved-by', 'rejected-by'])
            ->assertJsonPath('declared_signal_contracts.0.name', 'approved-by')
            ->assertJsonPath('declared_signal_contracts.0.parameters.0.name', 'actor')
            ->assertJsonPath('declared_signal_targets.0.name', 'approved-by')
            ->assertJsonPath('declared_signal_targets.0.has_contract', true)
            ->assertJsonPath('declared_signal_targets.1.name', 'rejected-by')
            ->assertJsonPath('declared_signal_targets.1.has_contract', false)
            ->assertJsonPath('declared_updates', ['mark-approved'])
            ->assertJsonPath('declared_update_contracts.0.name', 'mark-approved')
            ->assertJsonPath('declared_update_contracts.0.parameters.0.name', 'approved')
            ->assertJsonPath('declared_update_targets.0.name', 'mark-approved')
            ->assertJsonPath('declared_update_targets.0.has_contract', true)
            ->assertJsonPath('declared_contract_source', 'durable_history');

        /** @var WorkflowHistoryEvent $started */
        $started = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->sole();

        $this->assertSame(['approved-by', 'rejected-by'], $started->payload['declared_signals'] ?? null);
        $this->assertSame('approved-by', $started->payload['declared_signal_contracts'][0]['name'] ?? null);
        $this->assertSame(
            'actor',
            $started->payload['declared_signal_contracts'][0]['parameters'][0]['name'] ?? null,
        );
        $this->assertSame(['current-stage', 'stageMatches'], $started->payload['declared_queries'] ?? null);
        $this->assertSame('current-stage', $started->payload['declared_query_contracts'][0]['name'] ?? null);
        $this->assertSame(['mark-approved'], $started->payload['declared_updates'] ?? null);
        $this->assertSame('mark-approved', $started->payload['declared_update_contracts'][0]['name'] ?? null);
        $this->assertSame(
            'approved',
            $started->payload['declared_update_contracts'][0]['parameters'][0]['name'] ?? null,
        );
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
                'declared_queries' => ['current-stage', 'stageMatches'],
                'declared_query_contracts' => [
                    [
                        'name' => 'current-stage',
                        'parameters' => [],
                    ],
                    [
                        'name' => 'stageMatches',
                        'parameters' => [
                            [
                                'name' => 'stage',
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
                                'allows_null' => true,
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
            ],
            'recorded_at' => now()->subSeconds(19),
        ]);

        $this->get('/waterline/api/flows/' . $run->id)
            ->assertStatus(200)
            ->assertJsonPath('declared_queries', ['current-stage', 'stageMatches'])
            ->assertJsonPath('declared_query_contracts.0.name', 'current-stage')
            ->assertJsonPath('declared_query_targets.0.name', 'current-stage')
            ->assertJsonPath('declared_query_targets.0.has_contract', true)
            ->assertJsonPath('declared_query_targets.1.name', 'stageMatches')
            ->assertJsonPath('declared_query_targets.1.has_contract', true)
            ->assertJsonPath('can_query', false)
            ->assertJsonPath('query_blocked_reason', 'workflow_definition_unavailable')
            ->assertJsonPath('declared_signals', ['approved-by', 'rejected-by'])
            ->assertJsonPath('declared_signal_contracts.0.name', 'approved-by')
            ->assertJsonPath('declared_signal_contracts.0.parameters.0.name', 'actor')
            ->assertJsonPath('declared_signal_targets.0.name', 'approved-by')
            ->assertJsonPath('declared_signal_targets.0.has_contract', true)
            ->assertJsonPath('declared_signal_targets.1.name', 'rejected-by')
            ->assertJsonPath('declared_signal_targets.1.has_contract', false)
            ->assertJsonPath('can_signal', true)
            ->assertJsonPath('signal_blocked_reason', null)
            ->assertJsonPath('declared_updates', ['mark-approved'])
            ->assertJsonPath('declared_update_contracts.0.name', 'mark-approved')
            ->assertJsonPath('declared_update_contracts.0.parameters.0.name', 'approved')
            ->assertJsonPath('declared_update_targets.0.name', 'mark-approved')
            ->assertJsonPath('declared_update_targets.0.has_contract', true)
            ->assertJsonPath('can_update', false)
            ->assertJsonPath('update_blocked_reason', 'workflow_definition_unavailable')
            ->assertJsonPath('declared_contract_source', 'durable_history');
    }

    public function testShowReturnsEmptyNormalizedTargetsWhenCommandContractIsUnavailable(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-command-contract-unavailable',
            'workflow_class' => 'Missing\\Workflow\\CommandContractWorkflow',
            'workflow_type' => 'workflow.command-contract',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNCOMMANDUNAVL1',
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

        $this->get('/waterline/api/flows/' . $run->id)
            ->assertStatus(200)
            ->assertJsonPath('declared_queries', [])
            ->assertJsonPath('declared_query_contracts', [])
            ->assertJsonPath('declared_query_targets', [])
            ->assertJsonPath('declared_signals', [])
            ->assertJsonPath('declared_signal_contracts', [])
            ->assertJsonPath('declared_signal_targets', [])
            ->assertJsonPath('declared_updates', [])
            ->assertJsonPath('declared_update_contracts', [])
            ->assertJsonPath('declared_update_targets', [])
            ->assertJsonPath('declared_contract_source', 'unavailable');
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

    public function testShowSurfacesProjectedSelectedRunWaitRows(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-detail-projected-waits',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNWAITPROJ001',
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

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYWAITPROJ0001',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => 'SignalWaitOpened',
            'payload' => [
                'signal_wait_id' => 'signal-wait-projected',
                'signal_name' => 'approved-by',
                'sequence' => 1,
            ],
            'recorded_at' => now()->subSeconds(45),
            'created_at' => now()->subSeconds(45),
            'updated_at' => now()->subSeconds(45),
        ]);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->get('/waterline/api/flows/' . $run->id)
            ->assertStatus(200)
            ->assertJsonPath('waits_projection_source', 'workflow_run_waits')
            ->assertJsonPath('waits.0.id', 'signal-wait-projected')
            ->assertJsonPath('waits.0.kind', 'signal')
            ->assertJsonPath('waits.0.status', 'open')
            ->assertJsonPath('waits.0.target_name', 'approved-by')
            ->assertJsonPath('waits.0.external_only', true);
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
            ->assertJsonPath('wait_kind', 'signal')
            ->assertJsonPath('wait_reason', 'Waiting to apply signal approved-by')
            ->assertJsonPath('open_wait_id', 'signal-application:01JTESTCOMMANDSIGNALREPAIR01')
            ->assertJsonPath('resume_source_kind', 'workflow_command')
            ->assertJsonPath('resume_source_id', '01JTESTCOMMANDSIGNALREPAIR01')
            ->assertJsonPath('liveness_state', 'repair_needed')
            ->assertJsonPath('liveness_reason', 'Accepted signal approved-by is received without an open workflow task.')
            ->assertJsonPath('can_repair', true)
            ->assertJsonPath('waits.0.kind', 'signal')
            ->assertJsonPath('waits.0.status', 'resolved')
            ->assertJsonPath('waits.0.source_status', 'received')
            ->assertJsonPath('waits.0.summary', 'Signal approved-by received.')
            ->assertJsonPath('waits.0.task_backed', false)
            ->assertJsonPath('waits.0.external_only', true)
            ->assertJsonPath('waits.0.resume_source_kind', 'signal')
            ->assertJsonPath('waits.0.command_sequence', 2)
            ->assertJsonPath('waits.0.command_outcome', 'signal_received')
            ->assertJsonPath('tasks.0.type', 'workflow')
            ->assertJsonPath('tasks.0.status', 'missing')
            ->assertJsonPath('tasks.0.transport_state', 'missing')
            ->assertJsonPath('tasks.0.task_missing', true)
            ->assertJsonPath('tasks.0.synthetic', true)
            ->assertJsonPath('tasks.0.workflow_wait_kind', 'signal')
            ->assertJsonPath('tasks.0.workflow_open_wait_id', 'signal-application:01JTESTCOMMANDSIGNALREPAIR01')
            ->assertJsonPath('tasks.0.workflow_resume_source_kind', 'workflow_command')
            ->assertJsonPath('tasks.0.workflow_resume_source_id', '01JTESTCOMMANDSIGNALREPAIR01')
            ->assertJsonPath('tasks.0.workflow_command_id', '01JTESTCOMMANDSIGNALREPAIR01');
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
                'child_call_id' => $link->id,
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
            ->assertJsonPath('open_wait_id', 'child:' . $link->id)
            ->assertJsonPath('waits.0.kind', 'child')
            ->assertJsonPath('waits.0.status', 'open')
            ->assertJsonPath('waits.0.child_call_id', $link->id)
            ->assertJsonPath('waits.0.target_name', $childInstance->id)
            ->assertJsonPath('waits.0.target_type', 'workflow.child')
            ->assertJsonPath('waits.0.task_backed', false)
            ->assertJsonPath('waits.0.external_only', false)
            ->assertJsonPath('waits.0.resume_source_kind', 'child_workflow_run')
            ->assertJsonPath('waits.0.resume_source_id', $childRun->id)
            ->assertJsonPath('continuedWorkflows.0.link_type', 'child_workflow')
            ->assertJsonPath('continuedWorkflows.0.child_call_id', $link->id)
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

    public function testShowIncludesMissingChildResolutionWorkflowTask(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $parentInstance = WorkflowInstance::create([
            'id' => 'order-child-resolution-parent',
            'workflow_class' => 'ParentWorkflowClass',
            'workflow_type' => 'workflow.parent',
            'run_count' => 1,
        ]);

        $childInstance = WorkflowInstance::create([
            'id' => 'order-child-resolution-child',
            'workflow_class' => 'ChildWorkflowClass',
            'workflow_type' => 'workflow.child',
            'run_count' => 1,
        ]);

        $parentRun = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNCHILDRES001',
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
            'id' => '01JTESTFLOWRUNCHILDRES002',
            'workflow_instance_id' => $childInstance->id,
            'run_number' => 1,
            'workflow_class' => 'ChildWorkflowClass',
            'workflow_type' => 'workflow.child',
            'status' => 'completed',
            'closed_reason' => 'completed',
            'arguments' => Serializer::serialize([]),
            'output' => Serializer::serialize(['ok' => true]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(3),
            'closed_at' => now()->subSeconds(30),
            'last_progress_at' => now()->subSeconds(30),
        ]);

        $parentInstance->update(['current_run_id' => $parentRun->id]);
        $childInstance->update(['current_run_id' => $childRun->id]);

        $link = WorkflowLink::create([
            'id' => '01JTESTFLOWLINKCHILDRES01',
            'link_type' => 'child_workflow',
            'sequence' => 1,
            'parent_workflow_instance_id' => $parentInstance->id,
            'parent_workflow_run_id' => $parentRun->id,
            'child_workflow_instance_id' => $childInstance->id,
            'child_workflow_run_id' => $childRun->id,
            'is_primary_parent' => true,
        ]);

        foreach ([
            ['01JTESTHISTORYCHILDRES001', 1, 'ChildWorkflowScheduled'],
            ['01JTESTHISTORYCHILDRES002', 2, 'ChildRunCompleted'],
        ] as [$id, $eventSequence, $eventType]) {
            WorkflowHistoryEvent::create([
                'id' => $id,
                'workflow_run_id' => $parentRun->id,
                'sequence' => $eventSequence,
                'event_type' => $eventType,
                'payload' => [
                    'workflow_link_id' => $link->id,
                    'child_call_id' => $link->id,
                    'sequence' => 1,
                    'child_workflow_instance_id' => $childInstance->id,
                    'child_workflow_run_id' => $childRun->id,
                    'child_workflow_type' => $childRun->workflow_type,
                    'child_workflow_class' => $childRun->workflow_class,
                    'child_status' => $childRun->status->value,
                    'output' => $eventType === 'ChildRunCompleted' ? $childRun->output : null,
                ],
                'recorded_at' => now()->subSeconds(90 - ($eventSequence * 30)),
            ]);
        }

        RunSummaryProjector::project($parentRun->fresh());

        $this->get('/waterline/api/flows/' . $parentRun->id)
            ->assertStatus(200)
            ->assertJsonPath('wait_kind', 'child')
            ->assertJsonPath('wait_reason', 'Waiting to apply child workflow workflow.child result')
            ->assertJsonPath('open_wait_id', 'child:' . $link->id)
            ->assertJsonPath('resume_source_kind', 'child_workflow_run')
            ->assertJsonPath('resume_source_id', $childRun->id)
            ->assertJsonPath('liveness_state', 'repair_needed')
            ->assertJsonPath('liveness_reason', 'Child workflow workflow.child is resolved without an open workflow task.')
            ->assertJsonPath('waits.0.kind', 'child')
            ->assertJsonPath('waits.0.status', 'resolved')
            ->assertJsonPath('waits.0.task_backed', false)
            ->assertJsonPath('tasks.0.type', 'workflow')
            ->assertJsonPath('tasks.0.status', 'missing')
            ->assertJsonPath('tasks.0.transport_state', 'missing')
            ->assertJsonPath('tasks.0.task_missing', true)
            ->assertJsonPath('tasks.0.workflow_wait_kind', 'child')
            ->assertJsonPath('tasks.0.workflow_open_wait_id', 'child:' . $link->id)
            ->assertJsonPath('tasks.0.workflow_resume_source_kind', 'child_workflow_run')
            ->assertJsonPath('tasks.0.workflow_resume_source_id', $childRun->id)
            ->assertJsonPath('tasks.0.child_call_id', $link->id)
            ->assertJsonPath('tasks.0.child_workflow_run_id', $childRun->id);
    }

    public function testShowKeepsOpenChildWaitWhenChildRowDriftsTerminalBeforeParentResolutionHistory(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $parentInstance = WorkflowInstance::create([
            'id' => 'order-child-authority-parent',
            'workflow_class' => 'ParentWorkflowClass',
            'workflow_type' => 'workflow.parent',
            'run_count' => 1,
        ]);

        $childInstance = WorkflowInstance::create([
            'id' => 'order-child-authority-child',
            'workflow_class' => 'ChildWorkflowClass',
            'workflow_type' => 'workflow.child',
            'run_count' => 1,
        ]);

        $parentRun = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNCHILDAUTH001',
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
            'id' => '01JTESTFLOWRUNCHILDAUTH002',
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
            'id' => '01JTESTFLOWLINKCHILDAUTH01',
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
            'id' => '01JTESTHISTORYCHILDAUTH001',
            'workflow_run_id' => $parentRun->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::ChildWorkflowScheduled->value,
            'payload' => [
                'workflow_link_id' => $link->id,
                'child_call_id' => $link->id,
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

        $childRun->forceFill([
            'status' => 'completed',
            'closed_reason' => 'completed',
            'output' => Serializer::serialize([
                'child' => 'corrupted-terminal-row',
            ]),
            'closed_at' => now()->subSeconds(30),
        ])->save();

        RunSummaryProjector::project($childRun->fresh());
        RunSummaryProjector::project($parentRun->fresh());

        $this->get('/waterline/api/flows/' . $parentRun->id)
            ->assertStatus(200)
            ->assertJsonPath('wait_kind', 'child')
            ->assertJsonPath('liveness_state', 'waiting_for_child')
            ->assertJsonPath('open_wait_id', 'child:' . $link->id)
            ->assertJsonPath('waits.0.kind', 'child')
            ->assertJsonPath('waits.0.status', 'open')
            ->assertJsonPath('waits.0.source_status', 'waiting')
            ->assertJsonPath('waits.0.resume_source_id', $childRun->id);
    }

    public function testShowIncludesOpenWaitCountAndParallelChildGroupMetadata(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $parentInstance = WorkflowInstance::create([
            'id' => 'order-parallel-child-parent',
            'workflow_class' => 'ParentWorkflowClass',
            'workflow_type' => 'workflow.parent',
            'run_count' => 1,
        ]);

        $firstChildInstance = WorkflowInstance::create([
            'id' => 'order-parallel-child-1',
            'workflow_class' => 'ChildWorkflowClass',
            'workflow_type' => 'workflow.child',
            'run_count' => 1,
        ]);

        $secondChildInstance = WorkflowInstance::create([
            'id' => 'order-parallel-child-2',
            'workflow_class' => 'ChildWorkflowClass',
            'workflow_type' => 'workflow.child',
            'run_count' => 1,
        ]);

        $parentRun = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNPARALLELCH001',
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

        $firstChildRun = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNPARALLELCH101',
            'workflow_instance_id' => $firstChildInstance->id,
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

        $secondChildRun = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNPARALLELCH201',
            'workflow_instance_id' => $secondChildInstance->id,
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
        $firstChildInstance->update(['current_run_id' => $firstChildRun->id]);
        $secondChildInstance->update(['current_run_id' => $secondChildRun->id]);

        WorkflowRunSummary::create([
            'id' => $firstChildRun->id,
            'workflow_instance_id' => $firstChildInstance->id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'ChildWorkflowClass',
            'workflow_type' => 'workflow.child',
            'status' => 'waiting',
            'status_bucket' => 'running',
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => $firstChildRun->started_at,
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(2),
        ]);

        WorkflowRunSummary::create([
            'id' => $secondChildRun->id,
            'workflow_instance_id' => $secondChildInstance->id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'ChildWorkflowClass',
            'workflow_type' => 'workflow.child',
            'status' => 'waiting',
            'status_bucket' => 'running',
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => $secondChildRun->started_at,
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(2),
        ]);

        $firstLink = WorkflowLink::create([
            'id' => '01JTESTFLOWLINKPARALLELCH01',
            'link_type' => 'child_workflow',
            'sequence' => 1,
            'parent_workflow_instance_id' => $parentInstance->id,
            'parent_workflow_run_id' => $parentRun->id,
            'child_workflow_instance_id' => $firstChildInstance->id,
            'child_workflow_run_id' => $firstChildRun->id,
            'is_primary_parent' => true,
            'created_at' => now()->subSeconds(90),
            'updated_at' => now()->subSeconds(90),
        ]);

        $secondLink = WorkflowLink::create([
            'id' => '01JTESTFLOWLINKPARALLELCH02',
            'link_type' => 'child_workflow',
            'sequence' => 2,
            'parent_workflow_instance_id' => $parentInstance->id,
            'parent_workflow_run_id' => $parentRun->id,
            'child_workflow_instance_id' => $secondChildInstance->id,
            'child_workflow_run_id' => $secondChildRun->id,
            'is_primary_parent' => true,
            'created_at' => now()->subSeconds(89),
            'updated_at' => now()->subSeconds(89),
        ]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYPARALLELCH001',
            'workflow_run_id' => $parentRun->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::ChildWorkflowScheduled->value,
            'payload' => [
                'workflow_link_id' => $firstLink->id,
                'sequence' => 1,
                'child_workflow_instance_id' => $firstChildInstance->id,
                'child_workflow_run_id' => $firstChildRun->id,
                'child_workflow_type' => $firstChildRun->workflow_type,
                'child_workflow_class' => $firstChildRun->workflow_class,
                'child_run_number' => $firstChildRun->run_number,
                'parallel_group_kind' => 'child',
                'parallel_group_id' => 'parallel-children:1:2',
                'parallel_group_base_sequence' => 1,
                'parallel_group_size' => 2,
                'parallel_group_index' => 0,
            ],
            'recorded_at' => now()->subSeconds(90),
            'created_at' => now()->subSeconds(90),
            'updated_at' => now()->subSeconds(90),
        ]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTHISTORYPARALLELCH002',
            'workflow_run_id' => $parentRun->id,
            'sequence' => 2,
            'event_type' => HistoryEventType::ChildWorkflowScheduled->value,
            'payload' => [
                'workflow_link_id' => $secondLink->id,
                'sequence' => 2,
                'child_workflow_instance_id' => $secondChildInstance->id,
                'child_workflow_run_id' => $secondChildRun->id,
                'child_workflow_type' => $secondChildRun->workflow_type,
                'child_workflow_class' => $secondChildRun->workflow_class,
                'child_run_number' => $secondChildRun->run_number,
                'parallel_group_kind' => 'child',
                'parallel_group_id' => 'parallel-children:1:2',
                'parallel_group_base_sequence' => 1,
                'parallel_group_size' => 2,
                'parallel_group_index' => 1,
            ],
            'recorded_at' => now()->subSeconds(89),
            'created_at' => now()->subSeconds(89),
            'updated_at' => now()->subSeconds(89),
        ]);

        RunSummaryProjector::project($parentRun->fresh());

        $response = $this->get('/waterline/api/flows/' . $parentRun->id)
            ->assertStatus(200)
            ->assertJsonPath('wait_kind', 'child')
            ->assertJsonPath('open_wait_count', 2)
            ->assertJsonPath('waits.0.parallel_group_kind', 'child')
            ->assertJsonPath('waits.0.parallel_group_id', 'parallel-children:1:2')
            ->assertJsonPath('waits.0.parallel_group_size', 2)
            ->assertJsonPath('waits.0.parallel_group_index', 0)
            ->assertJsonPath('waits.1.parallel_group_kind', 'child')
            ->assertJsonPath('waits.1.parallel_group_id', 'parallel-children:1:2')
            ->assertJsonPath('waits.1.parallel_group_size', 2)
            ->assertJsonPath('waits.1.parallel_group_index', 1);

        $this->assertSame(
            [0, 1],
            collect($response->json('waits'))
                ->pluck('parallel_group_index')
                ->all(),
        );
    }

    public function testShowUsesHistoryToRenderCurrentContinuedChildWithoutLinks(): void
    {
        config()->set('waterline.engine_source', 'v2');
        $childCallId = '01JTESTPARENTCHILDCALL001';

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

        WorkflowRun::create([
            'id' => '01JTESTCHILDBOGUS00000001',
            'workflow_instance_id' => $childInstance->id,
            'run_number' => 3,
            'workflow_class' => 'ChildWorkflowClass',
            'workflow_type' => 'workflow.child',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->addMinute(),
            'last_progress_at' => now()->addMinute(),
        ]);

        $parentInstance->update(['current_run_id' => $parentRun->id]);
        $childInstance->update(['current_run_id' => null]);

        WorkflowHistoryEvent::create([
            'id' => '01JTESTPARENTCHILDSCHED001',
            'workflow_run_id' => $parentRun->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::ChildWorkflowScheduled->value,
            'payload' => [
                'child_call_id' => $childCallId,
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
                'child_call_id' => $childCallId,
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
            'id' => '01JTESTPARENTCHILDSTART002',
            'workflow_run_id' => $parentRun->id,
            'sequence' => 3,
            'event_type' => HistoryEventType::ChildRunStarted->value,
            'payload' => [
                'child_call_id' => $childCallId,
                'sequence' => 1,
                'child_workflow_instance_id' => $childInstance->id,
                'child_workflow_run_id' => $currentChildRun->id,
                'child_workflow_class' => $currentChildRun->workflow_class,
                'child_workflow_type' => $currentChildRun->workflow_type,
                'child_run_number' => 2,
            ],
            'recorded_at' => now()->subMinutes(2),
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
            ->assertJsonPath('open_wait_id', 'child:' . $childCallId)
            ->assertJsonPath('continuedWorkflows.0.link_type', 'child_workflow')
            ->assertJsonPath('continuedWorkflows.0.child_call_id', $childCallId)
            ->assertJsonPath('continuedWorkflows.0.child_workflow_run_id', $currentChildRun->id)
            ->assertJsonPath('continuedWorkflows.0.status', 'waiting')
            ->assertJsonPath('continuedWorkflows.0.status_bucket', 'running')
            ->assertJsonCount(1, 'continuedWorkflows')
            ->assertJsonPath('waits.0.kind', 'child')
            ->assertJsonPath('waits.0.child_call_id', $childCallId)
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
            ->assertJsonPath('tasks.0.type', 'timer')
            ->assertJsonPath('tasks.0.status', 'missing')
            ->assertJsonPath('tasks.0.transport_state', 'missing')
            ->assertJsonPath('tasks.0.task_missing', true)
            ->assertJsonPath('tasks.0.timer_id', '01JTESTFLOWTIMERREPAIR0001')
            ->assertJsonPath('tasks.1.type', 'workflow');
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
            ->assertJsonPath('tasks.0.type', 'timer')
            ->assertJsonPath('tasks.0.status', 'missing')
            ->assertJsonPath('tasks.0.transport_state', 'missing')
            ->assertJsonPath('tasks.0.task_missing', true)
            ->assertJsonPath('tasks.0.timer_id', $timer->id)
            ->assertJsonPath('tasks.1.id', $task->id)
            ->assertJsonPath('tasks.1.status', 'completed');
    }

    public function testShowKeepsTimerWaitAndTaskMetadataFromTypedHistoryWhenTimerRowIsMissing(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-detail-history-timer',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNHISTORYTIMER1',
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

        $timerId = '01JTESTFLOWTIMERHISTORY0001';
        $deadlineAt = now()->addMinute()->startOfSecond();

        WorkflowHistoryEvent::record($run, HistoryEventType::TimerScheduled, [
            'timer_id' => $timerId,
            'sequence' => 1,
            'delay_seconds' => 60,
            'fire_at' => $deadlineAt->toJSON(),
        ]);

        $task = WorkflowTask::create([
            'id' => '01JTESTFLOWTASKHISTORYTMR1',
            'workflow_run_id' => $run->id,
            'task_type' => 'timer',
            'status' => 'ready',
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => ['timer_id' => $timerId],
            'available_at' => $deadlineAt,
            'created_at' => now()->subSeconds(30),
            'updated_at' => now()->subSeconds(30),
        ]);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->get('/waterline/api/flows/' . $run->id)
            ->assertStatus(200)
            ->assertJsonPath('wait_kind', 'timer')
            ->assertJsonPath('wait_reason', 'Waiting for timer')
            ->assertJsonPath('liveness_state', 'timer_scheduled')
            ->assertJsonPath('resume_source_kind', 'timer')
            ->assertJsonPath('resume_source_id', $timerId)
            ->assertJsonPath('wait_deadline_at', $deadlineAt->toJSON())
            ->assertJsonPath('waits.0.kind', 'timer')
            ->assertJsonPath('waits.0.status', 'open')
            ->assertJsonPath('waits.0.task_backed', true)
            ->assertJsonPath('waits.0.task_id', $task->id)
            ->assertJsonPath('waits.0.deadline_at', $deadlineAt->toJSON())
            ->assertJsonPath('tasks.0.id', $task->id)
            ->assertJsonPath('tasks.0.type', 'timer')
            ->assertJsonPath('tasks.0.timer_id', $timerId)
            ->assertJsonPath('tasks.0.timer_sequence', 1)
            ->assertJsonPath('tasks.0.timer_fire_at', $deadlineAt->toJSON())
            ->assertJsonPath('timers.0.id', $timerId)
            ->assertJsonPath('timers.0.status', 'pending')
            ->assertJsonPath('timers.0.fire_at', $deadlineAt->toJSON());
    }

    public function testShowIncludesCompletedUpdateResultsInCommandHistory(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-update-command',
            'workflow_class' => TestOperatorCommandWorkflow::class,
            'workflow_type' => 'workflow.operator-command',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNUPDATECOMMAND1',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestOperatorCommandWorkflow::class,
            'workflow_type' => 'workflow.operator-command',
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
            'class' => TestOperatorCommandWorkflow::class,
            'workflow_type' => 'workflow.operator-command',
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
                    'path' => '/webhooks/instances/order-update-command/updates/mark-approved',
                    'route_name' => 'workflows.v2.update',
                    'fingerprint' => 'sha256:test-update-command',
                ],
            ],
            'status' => 'accepted',
            'outcome' => 'update_completed',
            'workflow_class' => TestOperatorCommandWorkflow::class,
            'workflow_type' => 'workflow.operator-command',
            'payload_codec' => Serializer::class,
            'payload' => Serializer::serialize([
                'name' => 'mark-approved',
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
                'update_name' => 'mark-approved',
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

        WorkflowUpdate::create([
            'id' => '01JTESTUPDATECOMPLETE000001',
            'workflow_command_id' => '01JTESTCOMMANDUPDATECOMPLETE',
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'target_scope' => 'instance',
            'resolved_workflow_run_id' => $run->id,
            'update_name' => 'mark-approved',
            'status' => 'completed',
            'outcome' => 'update_completed',
            'command_sequence' => 2,
            'workflow_sequence' => 1,
            'payload_codec' => Serializer::class,
            'arguments' => Serializer::serialize([true, 'waterline']),
            'result' => Serializer::serialize([
                'approved' => true,
                'events' => ['started', 'approved:yes:waterline'],
            ]),
            'accepted_at' => now()->subSeconds(50),
            'applied_at' => now()->subSeconds(49),
            'closed_at' => now()->subSeconds(49),
            'created_at' => now()->subSeconds(50),
            'updated_at' => now()->subSeconds(49),
        ]);

        $this->get('/waterline/api/flows/' . $run->id)
            ->assertStatus(200)
            ->assertJsonPath('can_cancel', true)
            ->assertJsonPath('cancel_blocked_reason', null)
            ->assertJsonPath('can_terminate', true)
            ->assertJsonPath('terminate_blocked_reason', null)
            ->assertJsonPath('can_update', true)
            ->assertJsonPath('update_blocked_reason', null)
            ->assertJsonPath('can_signal', true)
            ->assertJsonPath('signal_blocked_reason', null)
            ->assertJsonPath('can_repair', false)
            ->assertJsonPath('repair_blocked_reason', 'repair_not_needed')
            ->assertJsonPath('commands.0.context', [])
            ->assertJsonPath('commands.0.type', 'update')
            ->assertJsonPath('commands.0.target_name', 'mark-approved')
            ->assertJsonPath('commands.0.source', 'webhook')
            ->assertJsonPath('commands.0.caller_label', 'Webhook')
            ->assertJsonPath('commands.0.auth_status', 'not_configured')
            ->assertJsonPath('commands.0.auth_method', 'none')
            ->assertJsonPath('commands.0.request_method', 'POST')
            ->assertJsonPath('commands.0.request_path', '/webhooks/instances/order-update-command/updates/mark-approved')
            ->assertJsonPath('commands.0.request_route_name', 'workflows.v2.update')
            ->assertJsonPath('commands.0.request_fingerprint', 'sha256:test-update-command')
            ->assertJsonPath('commands.0.payload_codec', Serializer::class)
            ->assertJsonPath('commands.0.payload_available', true)
            ->assertJsonPath('commands.0.payload', serialize([
                'name' => 'mark-approved',
                'arguments' => [true, 'waterline'],
            ]))
            ->assertJsonPath('commands.0.update_id', '01JTESTUPDATECOMPLETE000001')
            ->assertJsonPath('commands.0.update_status', 'completed')
            ->assertJsonPath('commands.0.result_available', true)
            ->assertJsonPath('commands.0.failure_id', null)
            ->assertJsonPath('commands.0.failure_message', null)
            ->assertJsonPath('commands.0.completed_at', now()->subSeconds(49)->jsonSerialize())
            ->assertJsonPath('updates.0.id', '01JTESTUPDATECOMPLETE000001')
            ->assertJsonPath('updates.0.command_id', '01JTESTCOMMANDUPDATECOMPLETE')
            ->assertJsonPath('updates.0.command_sequence', 2)
            ->assertJsonPath('updates.0.workflow_sequence', 1)
            ->assertJsonPath('updates.0.name', 'mark-approved')
            ->assertJsonPath('updates.0.status', 'completed')
            ->assertJsonPath('updates.0.outcome', 'update_completed')
            ->assertJsonPath('updates.0.result_available', true)
            ->assertJsonPath('commands.0.result', serialize([
                'approved' => true,
                'events' => ['started', 'approved:yes:waterline'],
            ]))
            ->assertJsonPath('updates.0.result', serialize([
                'approved' => true,
                'events' => ['started', 'approved:yes:waterline'],
            ]));
    }

    public function testShowProjectsFailedUpdateFromTypedHistoryWhenUpdateRowsDrift(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $commandId = (string) Str::ulid();
        $updateId = (string) Str::ulid();
        $failureId = (string) Str::ulid();

        $instance = WorkflowInstance::create([
            'id' => 'order-update-failure-history',
            'workflow_class' => TestOperatorCommandWorkflow::class,
            'workflow_type' => 'workflow.operator-command',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestOperatorCommandWorkflow::class,
            'workflow_type' => 'workflow.operator-command',
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
            'class' => TestOperatorCommandWorkflow::class,
            'workflow_type' => 'workflow.operator-command',
            'status' => 'waiting',
            'status_bucket' => 'running',
            'connection' => 'redis',
            'queue' => 'default',
            'exception_count' => 1,
            'started_at' => $run->started_at,
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinute(),
        ]);

        WorkflowCommand::create([
            'id' => $commandId,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'command_sequence' => 2,
            'command_type' => 'update',
            'target_scope' => 'instance',
            'source' => 'waterline',
            'status' => 'accepted',
            'outcome' => 'update_completed',
            'workflow_class' => TestOperatorCommandWorkflow::class,
            'workflow_type' => 'workflow.operator-command',
            'payload_codec' => Serializer::class,
            'payload' => Serializer::serialize([
                'name' => 'mark-approved',
                'arguments' => [true, 'waterline'],
            ]),
            'accepted_at' => now()->subSeconds(50),
            'applied_at' => now()->subSeconds(49),
            'created_at' => now()->subSeconds(50),
            'updated_at' => now()->subSeconds(49),
        ]);

        WorkflowFailure::create([
            'id' => $failureId,
            'workflow_run_id' => $run->id,
            'source_kind' => 'workflow_command',
            'source_id' => $commandId,
            'propagation_kind' => 'update',
            'handled' => false,
            'exception_class' => \RuntimeException::class,
            'message' => 'corrupted failure row',
            'file' => __FILE__,
            'line' => 1,
            'trace_preview' => '',
        ]);

        WorkflowHistoryEvent::create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::UpdateCompleted->value,
            'payload' => [
                'workflow_command_id' => $commandId,
                'update_id' => $updateId,
                'update_name' => 'mark-approved',
                'sequence' => 1,
                'failure_id' => $failureId,
                'exception_class' => 'App\\Legacy\\UpdateBoom',
                'message' => 'typed update boom',
                'code' => 42,
                'exception' => [
                    'class' => 'App\\Legacy\\UpdateBoom',
                    'message' => 'typed update boom',
                    'code' => 42,
                    'file' => __FILE__,
                    'line' => 444,
                    'trace' => [],
                    'properties' => [],
                ],
            ],
            'workflow_command_id' => $commandId,
            'recorded_at' => now()->subSeconds(49),
            'created_at' => now()->subSeconds(49),
            'updated_at' => now()->subSeconds(49),
        ]);

        WorkflowUpdate::create([
            'id' => $updateId,
            'workflow_command_id' => $commandId,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'target_scope' => 'instance',
            'resolved_workflow_run_id' => $run->id,
            'update_name' => 'mark-approved',
            'status' => 'completed',
            'outcome' => 'update_completed',
            'command_sequence' => 2,
            'workflow_sequence' => 1,
            'payload_codec' => Serializer::class,
            'arguments' => Serializer::serialize([true, 'waterline']),
            'result' => Serializer::serialize(['wrong' => true]),
            'accepted_at' => now()->subSeconds(50),
            'applied_at' => now()->subSeconds(49),
            'closed_at' => now()->subSeconds(49),
            'created_at' => now()->subSeconds(50),
            'updated_at' => now()->subSeconds(49),
        ]);

        $this->get('/waterline/api/flows/' . $run->id)
            ->assertStatus(200)
            ->assertJsonPath('commands.0.update_id', $updateId)
            ->assertJsonPath('commands.0.update_status', 'failed')
            ->assertJsonPath('commands.0.failure_id', $failureId)
            ->assertJsonPath('commands.0.failure_message', 'typed update boom')
            ->assertJsonPath('commands.0.result_available', false)
            ->assertJsonPath('updates.0.id', $updateId)
            ->assertJsonPath('updates.0.command_id', $commandId)
            ->assertJsonPath('updates.0.status', 'failed')
            ->assertJsonPath('updates.0.outcome', 'update_failed')
            ->assertJsonPath('updates.0.failure_id', $failureId)
            ->assertJsonPath('updates.0.failure_message', 'typed update boom')
            ->assertJsonPath('updates.0.result_available', false)
            ->assertJsonPath('updates.0.result', null)
            ->assertJsonPath('updates.0.exception_class', 'App\\Legacy\\UpdateBoom')
            ->assertJsonPath('updates.0.exception_resolution_source', 'unresolved')
            ->assertJsonPath('updates.0.exception_replay_blocked', true)
            ->assertJsonPath('exceptions.0.exception_class', 'App\\Legacy\\UpdateBoom')
            ->assertJsonPath('exceptions.0.exception_replay_blocked', true);
    }

    public function testShowProjectsFailedUpdateFromTypedHistoryWhenCommandAndUpdateRowsAreMissing(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $updateId = (string) Str::ulid();
        $failureId = (string) Str::ulid();

        $instance = WorkflowInstance::create([
            'id' => 'order-update-history-only',
            'workflow_class' => TestOperatorCommandWorkflow::class,
            'workflow_type' => 'workflow.operator-command',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestOperatorCommandWorkflow::class,
            'workflow_type' => 'workflow.operator-command',
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
            'class' => TestOperatorCommandWorkflow::class,
            'workflow_type' => 'workflow.operator-command',
            'status' => 'waiting',
            'status_bucket' => 'running',
            'connection' => 'redis',
            'queue' => 'default',
            'exception_count' => 1,
            'started_at' => $run->started_at,
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinute(),
        ]);

        WorkflowFailure::create([
            'id' => $failureId,
            'workflow_run_id' => $run->id,
            'source_kind' => 'workflow_command',
            'source_id' => $updateId,
            'propagation_kind' => 'update',
            'handled' => false,
            'exception_class' => \RuntimeException::class,
            'message' => 'corrupted failure row',
            'file' => __FILE__,
            'line' => 1,
            'trace_preview' => '',
        ]);

        WorkflowHistoryEvent::create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::UpdateAccepted->value,
            'payload' => [
                'update_id' => $updateId,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'update_name' => 'mark-approved',
                'arguments' => Serializer::serialize([true, 'waterline']),
            ],
            'workflow_command_id' => null,
            'recorded_at' => now()->subSeconds(50),
            'created_at' => now()->subSeconds(50),
            'updated_at' => now()->subSeconds(50),
        ]);

        WorkflowHistoryEvent::create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 2,
            'event_type' => HistoryEventType::UpdateCompleted->value,
            'payload' => [
                'update_id' => $updateId,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'update_name' => 'mark-approved',
                'sequence' => 1,
                'failure_id' => $failureId,
                'exception_class' => 'App\\Legacy\\UpdateBoom',
                'message' => 'typed update boom',
                'code' => 42,
                'exception' => [
                    'class' => 'App\\Legacy\\UpdateBoom',
                    'message' => 'typed update boom',
                    'code' => 42,
                    'file' => __FILE__,
                    'line' => 444,
                    'trace' => [],
                    'properties' => [],
                ],
            ],
            'workflow_command_id' => null,
            'recorded_at' => now()->subSeconds(49),
            'created_at' => now()->subSeconds(49),
            'updated_at' => now()->subSeconds(49),
        ]);

        $this->get('/waterline/api/flows/' . $run->id)
            ->assertStatus(200)
            ->assertJsonCount(0, 'commands')
            ->assertJsonPath('updates.0.id', $updateId)
            ->assertJsonPath('updates.0.command_id', null)
            ->assertJsonPath('updates.0.name', 'mark-approved')
            ->assertJsonPath('updates.0.status', 'failed')
            ->assertJsonPath('updates.0.outcome', 'update_failed')
            ->assertJsonPath('updates.0.failure_id', $failureId)
            ->assertJsonPath('updates.0.failure_message', 'typed update boom')
            ->assertJsonPath('updates.0.result_available', false)
            ->assertJsonPath('updates.0.result', null)
            ->assertJsonPath('updates.0.exception_class', 'App\\Legacy\\UpdateBoom')
            ->assertJsonPath('updates.0.exception_resolution_source', 'unresolved')
            ->assertJsonPath('updates.0.exception_replay_blocked', true)
            ->assertJsonPath('exceptions.0.exception_class', 'App\\Legacy\\UpdateBoom')
            ->assertJsonPath('exceptions.0.exception_replay_blocked', true);
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

        WorkflowSignal::create([
            'id' => '01JTESTSIGNALRECORD001',
            'workflow_command_id' => '01JTESTCOMMANDSIGNALBLOCK01',
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'target_scope' => 'instance',
            'resolved_workflow_run_id' => $run->id,
            'signal_name' => 'name-provided',
            'signal_wait_id' => 'signal-wait-1',
            'status' => 'received',
            'outcome' => 'signal_received',
            'command_sequence' => 2,
            'payload_codec' => Serializer::class,
            'arguments' => Serializer::serialize(['Taylor']),
            'received_at' => now()->subSeconds(20),
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

        WorkflowUpdate::create([
            'id' => '01JTESTUPDATEBLOCKED000001',
            'workflow_command_id' => '01JTESTCOMMANDUPDATEBLOCK01',
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'target_scope' => 'instance',
            'resolved_workflow_run_id' => $run->id,
            'update_name' => 'approve',
            'status' => 'rejected',
            'outcome' => 'rejected_pending_signal',
            'command_sequence' => 3,
            'payload_codec' => Serializer::class,
            'arguments' => Serializer::serialize([true, 'waterline']),
            'rejection_reason' => 'earlier_signal_pending',
            'rejected_at' => now()->subSeconds(18),
            'closed_at' => now()->subSeconds(18),
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
            ->assertJsonPath('can_cancel', true)
            ->assertJsonPath('cancel_blocked_reason', null)
            ->assertJsonPath('can_terminate', true)
            ->assertJsonPath('terminate_blocked_reason', null)
            ->assertJsonPath('can_signal', true)
            ->assertJsonPath('signal_blocked_reason', null)
            ->assertJsonPath('can_update', false)
            ->assertJsonPath('update_blocked_reason', 'earlier_signal_pending')
            ->assertJsonPath('can_repair', true)
            ->assertJsonPath('repair_blocked_reason', null)
            ->assertJsonPath('commands.1.type', 'signal')
            ->assertJsonPath('commands.1.target_name', 'name-provided')
            ->assertJsonPath('commands.1.outcome', 'signal_received')
            ->assertJsonPath('commands.1.signal_id', '01JTESTSIGNALRECORD001')
            ->assertJsonPath('commands.1.signal_status', 'received')
            ->assertJsonPath('signals_scope', 'selected_run')
            ->assertJsonPath('signals.0.id', '01JTESTSIGNALRECORD001')
            ->assertJsonPath('signals.0.command_id', '01JTESTCOMMANDSIGNALBLOCK01')
            ->assertJsonPath('signals.0.name', 'name-provided')
            ->assertJsonPath('signals.0.signal_wait_id', 'signal-wait-1')
            ->assertJsonPath('signals.0.status', 'received')
            ->assertJsonPath('signals.0.outcome', 'signal_received')
            ->assertJsonPath('signals.0.arguments_available', true)
            ->assertJsonPath('signals.0.arguments', serialize(['Taylor']))
            ->assertJsonPath('commands.2.type', 'update')
            ->assertJsonPath('commands.2.target_name', 'approve')
            ->assertJsonPath('commands.2.status', 'rejected')
            ->assertJsonPath('commands.2.outcome', 'rejected_pending_signal')
            ->assertJsonPath('commands.2.update_id', '01JTESTUPDATEBLOCKED000001')
            ->assertJsonPath('commands.2.update_status', 'rejected')
            ->assertJsonPath('updates.0.id', '01JTESTUPDATEBLOCKED000001')
            ->assertJsonPath('updates.0.command_id', '01JTESTCOMMANDUPDATEBLOCK01')
            ->assertJsonPath('updates.0.name', 'approve')
            ->assertJsonPath('updates.0.status', 'rejected')
            ->assertJsonPath('updates.0.outcome', 'rejected_pending_signal')
            ->assertJsonPath('updates.0.rejection_reason', 'earlier_signal_pending')
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
                    'child_call_id' => '01JTESTCHILDCALLWORKFLOW01',
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
            ->assertJsonPath('commands.0.context.workflow.sequence', 2)
            ->assertJsonPath('commands.0.context.workflow.child_call_id', '01JTESTCHILDCALLWORKFLOW01');
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
            ->assertJsonPath('read_only_reason', null)
            ->assertJsonPath('tasks.0.id', 'missing:workflow:' . $run->id)
            ->assertJsonPath('tasks.0.type', 'workflow')
            ->assertJsonPath('tasks.0.status', 'missing')
            ->assertJsonPath('tasks.0.transport_state', 'missing')
            ->assertJsonPath('tasks.0.task_missing', true)
            ->assertJsonPath('tasks.0.synthetic', true)
            ->assertJsonPath('tasks.0.summary', 'Workflow task missing for selected run.')
            ->assertJsonPath('tasks.0.workflow_wait_kind', null)
            ->assertJsonPath('tasks.0.workflow_open_wait_id', null);
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

    public function testShowKeepsRunningActivityFromTypedHistoryWhenExecutionRowIsMissing(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-running-activity-history',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNRUNHISTORY01',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(2),
            'last_progress_at' => now()->subSeconds(20),
        ]);

        $instance->update(['current_run_id' => $run->id]);

        $execution = ActivityExecution::create([
            'id' => '01JTESTACTIVITYHISTORY0001',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => 'ActivityClass',
            'activity_type' => 'activity.test',
            'status' => 'pending',
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'activities',
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, [
            'activity_execution_id' => $execution->id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => $execution->sequence,
            'activity' => ActivitySnapshot::fromExecution($execution),
        ]);

        $execution->forceFill([
            'status' => 'running',
            'attempt_count' => 1,
            'current_attempt_id' => '01JTESTATTEMPT000000000001',
            'started_at' => now()->subSeconds(15),
        ])->save();

        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityStarted, [
            'activity_execution_id' => $execution->id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => $execution->sequence,
            'activity' => ActivitySnapshot::fromExecution($execution),
        ]);

        $executionId = $execution->id;
        $execution->delete();

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
                    $executionId,
                ),
            )
            ->assertJsonPath('activities.0.id', $executionId)
            ->assertJsonPath('activities.0.class', 'ActivityClass')
            ->assertJsonPath('activities.0.type', 'activity.test')
            ->assertJsonPath('activities.0.attempt_id', '01JTESTATTEMPT000000000001')
            ->assertJsonPath('activities.0.attempt_count', 1)
            ->assertJsonPath('activities.0.status', 'running')
            ->assertJsonPath('activities.0.attempts.0.id', '01JTESTATTEMPT000000000001')
            ->assertJsonPath('activities.0.attempts.0.attempt_number', 1)
            ->assertJsonPath('activities.0.attempts.0.status', 'running')
            ->assertJsonPath('activities.0.attempts.0.can_continue', true)
            ->assertJsonPath('activities.0.queue', 'activities')
            ->assertJsonPath('activities.0.closed_at', null)
            ->assertJsonPath('waits.0.kind', 'activity')
            ->assertJsonPath('waits.0.status', 'open')
            ->assertJsonPath('waits.0.source_status', 'running')
            ->assertJsonPath('waits.0.task_backed', false)
            ->assertJsonPath('waits.0.resume_source_kind', 'activity_execution')
            ->assertJsonPath('waits.0.resume_source_id', $executionId)
            ->assertJsonPath('timeline.0.type', 'ActivityScheduled')
            ->assertJsonPath('timeline.1.type', 'ActivityStarted')
            ->assertJsonPath('tasks', []);
    }

    public function testShowKeepsTypedActivityHistoryAuthoritativeWhenExecutionRowDriftsClosed(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-activity-terminal-drift',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNACTDRIFT001',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(2),
            'last_progress_at' => now()->subSeconds(20),
        ]);

        $instance->update(['current_run_id' => $run->id]);

        $execution = ActivityExecution::create([
            'id' => '01JACTDRIFT000000000001',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => 'ActivityClass',
            'activity_type' => 'activity.test',
            'status' => 'pending',
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'activities',
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, [
            'activity_execution_id' => $execution->id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => $execution->sequence,
            'activity' => ActivitySnapshot::fromExecution($execution),
        ]);

        $execution->forceFill([
            'status' => 'running',
            'attempt_count' => 1,
            'current_attempt_id' => '01JATTDRIFT000000000001',
            'started_at' => now()->subSeconds(15),
        ])->save();

        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityStarted, [
            'activity_execution_id' => $execution->id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => $execution->sequence,
            'activity' => ActivitySnapshot::fromExecution($execution),
        ]);

        $recordedStartedAt = $execution->started_at?->toJSON();

        $execution->forceFill([
            'status' => 'completed',
            'result' => Serializer::serialize('mutable result'),
            'current_attempt_id' => '01JATTDRIFTMUTATED000001',
            'attempt_count' => 42,
            'started_at' => now()->addDay(),
            'closed_at' => now(),
        ])->save();

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->get('/waterline/api/flows/' . $instance->id)
            ->assertStatus(200)
            ->assertJsonPath('id', $run->id)
            ->assertJsonPath('wait_kind', 'activity')
            ->assertJsonPath('liveness_state', 'activity_running_without_task')
            ->assertJsonPath('activities.0.id', $execution->id)
            ->assertJsonPath('activities.0.status', 'running')
            ->assertJsonPath('activities.0.result', serialize(null))
            ->assertJsonPath('activities.0.closed_at', null)
            ->assertJsonPath('waits.0.kind', 'activity')
            ->assertJsonPath('waits.0.status', 'open')
            ->assertJsonPath('waits.0.source_status', 'running')
            ->assertJsonPath('timeline.0.type', 'ActivityScheduled')
            ->assertJsonPath('timeline.1.type', 'ActivityStarted')
            ->assertJsonPath('timeline.1.activity.status', 'running')
            ->assertJsonPath('timeline.1.activity.attempt_id', '01JATTDRIFT000000000001')
            ->assertJsonPath('timeline.1.activity.attempt_count', 1)
            ->assertJsonPath('timeline.1.activity.started_at', $recordedStartedAt)
            ->assertJsonPath('timeline.1.activity.closed_at', null)
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
            ->assertJsonPath('can_cancel', false)
            ->assertJsonPath('cancel_blocked_reason', 'selected_run_not_current')
            ->assertJsonPath('can_terminate', false)
            ->assertJsonPath('terminate_blocked_reason', 'selected_run_not_current')
            ->assertJsonPath('can_signal', false)
            ->assertJsonPath('signal_blocked_reason', 'selected_run_not_current')
            ->assertJsonPath('can_update', false)
            ->assertJsonPath('update_blocked_reason', 'selected_run_not_current')
            ->assertJsonPath('can_repair', false)
            ->assertJsonPath('repair_blocked_reason', 'selected_run_not_current')
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

    public function testUpdateTargetsCurrentInstanceRouteAndReturnsAcceptedResponse(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.driver', 'database');

        Queue::fake();

        $workflow = WorkflowStub::make(TestOperatorCommandWorkflow::class, 'order-update-current');
        $workflow->start();

        $this->runReadyWorkflowTask($workflow->runId());

        $response = $this->postJson('/waterline/api/instances/' . $workflow->id() . '/updates/mark-approved', [
            'arguments' => [
                'approved' => true,
                'source' => 'waterline-ui',
            ],
        ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('outcome', 'update_completed')
            ->assertJsonPath('workflow_id', $workflow->id())
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('target_scope', 'instance')
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('command_source', 'waterline')
            ->assertJsonPath('validation_errors', [])
            ->assertJsonPath('wait_for', 'completed')
            ->assertJsonPath('wait_timed_out', false)
            ->assertJsonPath('wait_timeout_seconds', 10)
            ->assertJsonPath('result.approved', true)
            ->assertJsonPath('result.events.0', 'started')
            ->assertJsonPath('result.events.1', 'approved:yes:waterline-ui');

        $commandId = $response->json('command_id');

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $commandId,
            'workflow_instance_id' => $workflow->id(),
            'workflow_run_id' => $workflow->runId(),
            'command_type' => 'update',
            'source' => 'waterline',
            'target_scope' => 'instance',
            'status' => 'accepted',
            'outcome' => 'update_completed',
        ]);

        $command = WorkflowCommand::query()->findOrFail($commandId);

        $this->assertSame('mark-approved', $command->targetName());
        $this->assertSame(
            '/waterline/api/instances/'.$workflow->id().'/updates/mark-approved',
            $command->requestPath(),
        );
        $this->assertSame('waterline.instances.update', $command->requestRouteName());
    }

    public function testUpdateCanReturnAfterAcceptedOnlyModeAndLetWorkerApplyIt(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.driver', 'database');

        Queue::fake();

        $workflow = WorkflowStub::make(TestOperatorCommandWorkflow::class, 'order-update-current-accepted');
        $workflow->start();

        $this->runReadyWorkflowTask($workflow->runId());

        $response = $this->postJson('/waterline/api/instances/' . $workflow->id() . '/updates/mark-approved', [
            'wait_for' => 'accepted',
            'arguments' => [
                'approved' => true,
                'source' => 'waterline-ui',
            ],
        ]);

        $response
            ->assertStatus(202)
            ->assertJsonPath('outcome', null)
            ->assertJsonPath('workflow_id', $workflow->id())
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('target_scope', 'instance')
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('update_status', 'accepted')
            ->assertJsonPath('command_source', 'waterline')
            ->assertJsonPath('validation_errors', [])
            ->assertJsonPath('wait_for', 'accepted')
            ->assertJsonPath('wait_timed_out', false)
            ->assertJsonPath('wait_timeout_seconds', null)
            ->assertJsonPath('result', null);

        $commandId = $response->json('command_id');
        $updateId = $response->json('update_id');

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $commandId,
            'workflow_instance_id' => $workflow->id(),
            'workflow_run_id' => $workflow->runId(),
            'command_type' => 'update',
            'source' => 'waterline',
            'target_scope' => 'instance',
            'status' => 'accepted',
            'outcome' => null,
        ]);

        $this->assertDatabaseHas('workflow_updates', [
            'id' => $updateId,
            'workflow_command_id' => $commandId,
            'workflow_instance_id' => $workflow->id(),
            'workflow_run_id' => $workflow->runId(),
            'update_name' => 'mark-approved',
            'status' => 'accepted',
            'outcome' => null,
            'workflow_sequence' => null,
        ]);

        $this->runReadyWorkflowTask($workflow->runId());

        $this->assertDatabaseHas('workflow_updates', [
            'id' => $updateId,
            'status' => 'completed',
            'outcome' => 'update_completed',
            'workflow_sequence' => 1,
        ]);
    }

    public function testUpdateStatusEndpointCanInspectAcceptedLifecycleByUpdateId(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.driver', 'database');

        Queue::fake();

        $workflow = WorkflowStub::make(TestOperatorCommandWorkflow::class, 'order-update-status-endpoint');
        $workflow->start();

        $this->runReadyWorkflowTask($workflow->runId());

        $accepted = $this->postJson('/waterline/api/instances/' . $workflow->id() . '/updates/mark-approved', [
            'wait_for' => 'accepted',
            'arguments' => [
                'approved' => true,
                'source' => 'waterline-status',
            ],
        ]);

        $accepted
            ->assertStatus(202)
            ->assertJsonPath('update_status', 'accepted');

        $updateId = $accepted->json('update_id');

        $this->assertIsString($updateId);

        $this->getJson('/waterline/api/instances/' . $workflow->id() . '/updates/' . $updateId)
            ->assertStatus(202)
            ->assertJsonPath('workflow_id', $workflow->id())
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('command_source', 'waterline')
            ->assertJsonPath('command_id', $accepted->json('command_id'))
            ->assertJsonPath('update_id', $updateId)
            ->assertJsonPath('update_name', 'mark-approved')
            ->assertJsonPath('update_status', 'accepted')
            ->assertJsonPath('workflow_sequence', null)
            ->assertJsonPath('wait_for', 'status')
            ->assertJsonPath('wait_timed_out', false)
            ->assertJsonPath('wait_timeout_seconds', null)
            ->assertJsonPath('result', null);

        $this->runReadyWorkflowTask($workflow->runId());

        $this->getJson('/waterline/api/flows/' . $workflow->runId() . '/updates/' . $updateId)
            ->assertStatus(200)
            ->assertJsonPath('outcome', 'update_completed')
            ->assertJsonPath('command_source', 'waterline')
            ->assertJsonPath('update_id', $updateId)
            ->assertJsonPath('update_name', 'mark-approved')
            ->assertJsonPath('update_status', 'completed')
            ->assertJsonPath('workflow_sequence', 1)
            ->assertJsonPath('wait_for', 'status')
            ->assertJsonPath('wait_timed_out', false)
            ->assertJsonPath('result.approved', true)
            ->assertJsonPath('result.events.0', 'started')
            ->assertJsonPath('result.events.1', 'approved:yes:waterline-status');
    }

    public function testUpdateCanReturnAcceptedLifecycleWhenCompletionWaitTimesOut(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.driver', 'database');
        config()->set('workflows.v2.update_wait.poll_interval_milliseconds', 10);

        $workflow = WorkflowStub::make(TestOperatorCommandWorkflow::class, 'order-update-current-timeout');
        $workflow->start();

        $this->runReadyWorkflowTask($workflow->runId());

        WorkflowRun::query()->findOrFail($workflow->runId())->forceFill([
            'compatibility' => 'waterline-timeout-build',
        ])->save();

        $response = $this->postJson('/waterline/api/instances/' . $workflow->id() . '/updates/mark-approved', [
            'wait_timeout_seconds' => 1,
            'arguments' => [
                'approved' => true,
                'source' => 'waterline-ui',
            ],
        ]);

        $response
            ->assertStatus(202)
            ->assertJsonPath('outcome', null)
            ->assertJsonPath('workflow_id', $workflow->id())
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('target_scope', 'instance')
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('update_status', 'accepted')
            ->assertJsonPath('command_source', 'waterline')
            ->assertJsonPath('wait_for', 'completed')
            ->assertJsonPath('wait_timed_out', true)
            ->assertJsonPath('wait_timeout_seconds', 1)
            ->assertJsonPath('result', null);

        $updateId = $response->json('update_id');
        $commandId = $response->json('command_id');

        $this->assertIsString($updateId);
        $this->assertIsString($commandId);

        $detailResponse = $this->getJson('/waterline/api/flows/' . $workflow->runId());
        $detail = $detailResponse->json();
        $updateWait = collect($detail['waits'] ?? [])
            ->first(static fn (array $wait): bool => ($wait['kind'] ?? null) === 'update');
        $signalWait = collect($detail['waits'] ?? [])
            ->first(static fn (array $wait): bool => ($wait['kind'] ?? null) === 'signal');

        $detailResponse
            ->assertStatus(200)
            ->assertJsonPath('wait_kind', 'update')
            ->assertJsonPath('wait_reason', 'Waiting for update mark-approved')
            ->assertJsonPath('open_wait_id', 'update:' . $updateId)
            ->assertJsonPath('resume_source_kind', 'workflow_update')
            ->assertJsonPath('resume_source_id', $updateId)
            ->assertJsonPath('liveness_state', 'workflow_task_waiting_for_compatible_worker')
            ->assertJsonPath('open_wait_count', 2)
            ->assertJsonPath('tasks.0.workflow_wait_kind', 'update')
            ->assertJsonPath('tasks.0.workflow_open_wait_id', 'update:' . $updateId)
            ->assertJsonPath('tasks.0.workflow_resume_source_kind', 'workflow_update')
            ->assertJsonPath('tasks.0.workflow_resume_source_id', $updateId)
            ->assertJsonPath('tasks.0.workflow_update_id', $updateId)
            ->assertJsonPath('tasks.0.workflow_command_id', $commandId);

        $this->assertIsArray($updateWait);
        $this->assertSame($updateId, $updateWait['update_id']);
        $this->assertSame('open', $updateWait['status']);
        $this->assertSame('accepted', $updateWait['source_status']);
        $this->assertTrue($updateWait['task_backed']);
        $this->assertSame('workflow', $updateWait['task_type']);
        $this->assertSame('ready', $updateWait['task_status']);
        $this->assertIsArray($signalWait);
        $this->assertSame('open', $signalWait['status']);
        $this->assertSame('waiting', $signalWait['source_status']);
    }

    public function testRepairRestoresAcceptedUpdateWorkflowTaskWithUpdateTargetDetail(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.driver', 'database');

        Queue::fake();

        $workflow = WorkflowStub::make(TestOperatorCommandWorkflow::class, 'order-update-waterline-repair');
        $workflow->start();

        $this->runReadyWorkflowTask($workflow->runId());

        $accepted = $this->postJson('/waterline/api/instances/' . $workflow->id() . '/updates/mark-approved', [
            'wait_for' => 'accepted',
            'arguments' => [
                'approved' => true,
                'source' => 'waterline-repair',
            ],
        ]);

        $accepted
            ->assertStatus(202)
            ->assertJsonPath('update_status', 'accepted')
            ->assertJsonPath('command_source', 'waterline');

        $runId = $workflow->runId();
        $commandId = $accepted->json('command_id');
        $updateId = $accepted->json('update_id');

        $this->assertIsString($runId);
        $this->assertIsString($commandId);
        $this->assertIsString($updateId);

        WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', 'workflow')
            ->whereIn('status', ['ready', 'leased'])
            ->delete();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);
        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $detailBeforeRepair = $this->getJson('/waterline/api/flows/' . $runId);
        $updateWait = collect($detailBeforeRepair->json('waits') ?? [])
            ->first(static fn (array $wait): bool => ($wait['kind'] ?? null) === 'update');

        $detailBeforeRepair
            ->assertStatus(200)
            ->assertJsonPath('wait_kind', 'update')
            ->assertJsonPath('open_wait_id', 'update:' . $updateId)
            ->assertJsonPath('resume_source_kind', 'workflow_update')
            ->assertJsonPath('resume_source_id', $updateId)
            ->assertJsonPath('liveness_state', 'repair_needed')
            ->assertJsonPath('can_repair', true)
            ->assertJsonPath('tasks.0.type', 'workflow')
            ->assertJsonPath('tasks.0.status', 'missing')
            ->assertJsonPath('tasks.0.transport_state', 'missing')
            ->assertJsonPath('tasks.0.task_missing', true)
            ->assertJsonPath('tasks.0.workflow_wait_kind', 'update')
            ->assertJsonPath('tasks.0.workflow_open_wait_id', 'update:' . $updateId)
            ->assertJsonPath('tasks.0.workflow_resume_source_kind', 'workflow_update')
            ->assertJsonPath('tasks.0.workflow_resume_source_id', $updateId)
            ->assertJsonPath('tasks.0.workflow_update_id', $updateId)
            ->assertJsonPath('tasks.0.workflow_command_id', $commandId);

        $this->assertIsArray($updateWait);
        $this->assertSame($updateId, $updateWait['update_id']);
        $this->assertFalse($updateWait['task_backed']);
        $this->assertNotSame('ready', $updateWait['task_status'] ?? null);

        $repair = $this->postJson('/waterline/api/instances/' . $workflow->id() . '/repair');

        $repair
            ->assertStatus(200)
            ->assertJsonPath('outcome', 'repair_dispatched')
            ->assertJsonPath('workflow_id', $workflow->id())
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('command_source', 'waterline')
            ->assertJsonPath('rejection_reason', null);

        /** @var WorkflowTask $repairedTask */
        $repairedTask = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', 'workflow')
            ->where('status', 'ready')
            ->sole();

        $this->assertSame(1, $repairedTask->repair_count);
        $this->assertSame($updateId, $repairedTask->payload['workflow_update_id'] ?? null);
        $this->assertSame($commandId, $repairedTask->payload['workflow_command_id'] ?? null);

        $this->getJson('/waterline/api/flows/' . $runId)
            ->assertStatus(200)
            ->assertJsonPath('wait_kind', 'update')
            ->assertJsonPath('liveness_state', 'workflow_task_ready')
            ->assertJsonPath('can_repair', false)
            ->assertJsonPath('tasks.0.id', $repairedTask->id)
            ->assertJsonPath('tasks.0.summary', 'Workflow task ready to apply accepted update.')
            ->assertJsonPath('tasks.0.workflow_wait_kind', 'update')
            ->assertJsonPath('tasks.0.workflow_update_id', $updateId)
            ->assertJsonPath('tasks.0.workflow_command_id', $commandId)
            ->assertJsonPath('tasks.0.workflow_resume_source_kind', 'workflow_update')
            ->assertJsonPath('tasks.0.workflow_resume_source_id', $updateId);

        Queue::assertPushed(
            RunWorkflowTask::class,
            static fn (RunWorkflowTask $job): bool => $job->taskId === $repairedTask->id
        );

        $this->runReadyWorkflowTask($runId);

        $this->assertDatabaseHas('workflow_updates', [
            'id' => $updateId,
            'status' => 'completed',
            'outcome' => 'update_completed',
            'workflow_sequence' => 1,
        ]);
    }

    public function testAcceptedUpdateWaitDoesNotBorrowUnrelatedWorkflowTaskInDetailPayload(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'waterline-update-unrelated-task',
            'workflow_class' => TestOperatorCommandWorkflow::class,
            'workflow_type' => 'waterline.operator-command',
            'run_count' => 1,
            'reserved_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
        ]);

        $run = WorkflowRun::create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestOperatorCommandWorkflow::class,
            'workflow_type' => 'waterline.operator-command',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subSeconds(30),
        ]);

        $instance->update([
            'current_run_id' => $run->id,
        ]);

        $command = WorkflowCommand::record($instance, $run, [
            'command_type' => 'update',
            'target_scope' => 'instance',
            'status' => 'accepted',
            'payload_codec' => config('workflows.serializer'),
            'payload' => Serializer::serialize([
                'name' => 'mark-approved',
                'arguments' => [true, 'waterline'],
                'validation_errors' => [],
            ]),
            'accepted_at' => now()->subSeconds(20),
        ]);

        $update = WorkflowUpdate::create([
            'workflow_command_id' => $command->id,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'target_scope' => 'instance',
            'resolved_workflow_run_id' => $run->id,
            'update_name' => 'mark-approved',
            'status' => 'accepted',
            'command_sequence' => $command->command_sequence,
            'payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize([true, 'waterline']),
            'accepted_at' => $command->accepted_at,
        ]);

        $unrelatedTask = WorkflowTask::create([
            'workflow_run_id' => $run->id,
            'task_type' => 'workflow',
            'status' => 'ready',
            'available_at' => now()->subSeconds(10),
            'payload' => [
                'workflow_wait_kind' => 'signal',
                'open_wait_id' => 'signal-application:waterline-signal',
                'resume_source_kind' => 'workflow_signal',
                'resume_source_id' => 'waterline-signal',
                'workflow_signal_id' => 'waterline-signal',
                'workflow_command_id' => 'waterline-signal-command',
            ],
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $response = $this->getJson('/waterline/api/flows/' . $run->id);
        $updateWait = collect($response->json('waits') ?? [])
            ->first(static fn (array $wait): bool => ($wait['kind'] ?? null) === 'update');
        $missingUpdateTask = collect($response->json('tasks') ?? [])
            ->first(static fn (array $task): bool => ($task['task_missing'] ?? false) === true
                && ($task['workflow_wait_kind'] ?? null) === 'update');
        $openSignalTask = collect($response->json('tasks') ?? [])
            ->first(static fn (array $task): bool => ($task['id'] ?? null) === $unrelatedTask->id);

        $response
            ->assertStatus(200)
            ->assertJsonPath('wait_kind', 'update')
            ->assertJsonPath('wait_reason', 'Waiting for update mark-approved')
            ->assertJsonPath('open_wait_id', 'update:' . $update->id)
            ->assertJsonPath('resume_source_kind', 'workflow_update')
            ->assertJsonPath('resume_source_id', $update->id)
            ->assertJsonPath('liveness_state', 'repair_needed')
            ->assertJsonPath('liveness_reason', 'Accepted update mark-approved is open without an open workflow task.')
            ->assertJsonPath('next_task_id', null)
            ->assertJsonPath('can_repair', true);

        $this->assertIsArray($updateWait);
        $this->assertFalse($updateWait['task_backed']);
        $this->assertNull($updateWait['task_id']);
        $this->assertIsArray($openSignalTask);
        $this->assertSame('signal', $openSignalTask['workflow_wait_kind']);
        $this->assertIsArray($missingUpdateTask);
        $this->assertSame('missing', $missingUpdateTask['transport_state']);
        $this->assertSame('update:' . $update->id, $missingUpdateTask['workflow_open_wait_id']);
        $this->assertSame($update->id, $missingUpdateTask['workflow_update_id']);
        $this->assertSame($command->id, $missingUpdateTask['workflow_command_id']);
    }

    public function testRepairRestoresAcceptedSignalWorkflowTaskWithSignalTargetDetail(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.driver', 'database');

        Queue::fake();

        $workflow = WorkflowStub::make(TestOperatorCommandWorkflow::class, 'order-signal-waterline-repair');
        $workflow->start();

        $this->runReadyWorkflowTask($workflow->runId());

        $accepted = $this->postJson('/waterline/api/instances/' . $workflow->id() . '/signals/name-provided', [
            'arguments' => [
                'name' => 'Taylor',
            ],
        ]);

        $accepted
            ->assertStatus(200)
            ->assertJsonPath('outcome', 'signal_received')
            ->assertJsonPath('command_source', 'waterline');

        $runId = $workflow->runId();
        $commandId = $accepted->json('command_id');

        $this->assertIsString($runId);
        $this->assertIsString($commandId);

        /** @var WorkflowSignal $signal */
        $signal = WorkflowSignal::query()
            ->where('workflow_command_id', $commandId)
            ->sole();

        WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', 'workflow')
            ->whereIn('status', ['ready', 'leased'])
            ->delete();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);
        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->getJson('/waterline/api/flows/' . $runId)
            ->assertStatus(200)
            ->assertJsonPath('wait_kind', 'signal')
            ->assertJsonPath('open_wait_id', 'signal-application:' . $signal->id)
            ->assertJsonPath('resume_source_kind', 'workflow_signal')
            ->assertJsonPath('resume_source_id', $signal->id)
            ->assertJsonPath('liveness_state', 'repair_needed')
            ->assertJsonPath('can_repair', true)
            ->assertJsonPath('tasks.0.type', 'workflow')
            ->assertJsonPath('tasks.0.status', 'missing')
            ->assertJsonPath('tasks.0.transport_state', 'missing')
            ->assertJsonPath('tasks.0.task_missing', true)
            ->assertJsonPath('tasks.0.workflow_wait_kind', 'signal')
            ->assertJsonPath('tasks.0.workflow_open_wait_id', 'signal-application:' . $signal->id)
            ->assertJsonPath('tasks.0.workflow_resume_source_kind', 'workflow_signal')
            ->assertJsonPath('tasks.0.workflow_resume_source_id', $signal->id)
            ->assertJsonPath('tasks.0.workflow_signal_id', $signal->id)
            ->assertJsonPath('tasks.0.workflow_command_id', $commandId);

        $repair = $this->postJson('/waterline/api/instances/' . $workflow->id() . '/repair');

        $repair
            ->assertStatus(200)
            ->assertJsonPath('outcome', 'repair_dispatched')
            ->assertJsonPath('workflow_id', $workflow->id())
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('command_source', 'waterline')
            ->assertJsonPath('rejection_reason', null);

        /** @var WorkflowTask $repairedTask */
        $repairedTask = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', 'workflow')
            ->where('status', 'ready')
            ->sole();

        $this->assertSame(1, $repairedTask->repair_count);
        $this->assertSame($signal->id, $repairedTask->payload['workflow_signal_id'] ?? null);
        $this->assertSame($commandId, $repairedTask->payload['workflow_command_id'] ?? null);

        $this->getJson('/waterline/api/flows/' . $runId)
            ->assertStatus(200)
            ->assertJsonPath('liveness_state', 'workflow_task_ready')
            ->assertJsonPath('can_repair', false)
            ->assertJsonPath('tasks.0.id', $repairedTask->id)
            ->assertJsonPath('tasks.0.summary', 'Workflow task ready to apply accepted signal.')
            ->assertJsonPath('tasks.0.workflow_wait_kind', 'signal')
            ->assertJsonPath('tasks.0.workflow_signal_id', $signal->id)
            ->assertJsonPath('tasks.0.workflow_command_id', $commandId)
            ->assertJsonPath('tasks.0.workflow_resume_source_kind', 'workflow_signal')
            ->assertJsonPath('tasks.0.workflow_resume_source_id', $signal->id);

        Queue::assertPushed(
            RunWorkflowTask::class,
            static fn (RunWorkflowTask $job): bool => $job->taskId === $repairedTask->id
        );
    }

    public function testUpdateIsBlockedWhileAnEarlierSignalIsStillPending(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.driver', 'database');

        $workflow = WorkflowStub::make(TestLinearizedOperatorWorkflow::class, 'order-update-linearized');
        $workflow->start();
        $this->runReadyWorkflowTask($workflow->runId());

        $this->waitForWorkflowState(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        Queue::fake();

        $signal = $this->postJson('/waterline/api/instances/' . $workflow->id() . '/signals/advance', [
            'arguments' => [
                'name' => 'Taylor',
            ],
        ]);

        $signal
            ->assertStatus(200)
            ->assertJsonPath('outcome', 'signal_received')
            ->assertJsonPath('command_status', 'accepted');

        $commandId = $signal->json('command_id');

        $this->assertIsString($commandId);

        $this->get('/waterline/api/instances/' . $workflow->id())
            ->assertStatus(200)
            ->assertJsonPath('wait_kind', 'workflow-task')
            ->assertJsonPath('liveness_state', 'workflow_task_ready')
            ->assertJsonPath('tasks.0.summary', 'Workflow task ready to apply accepted signal.')
            ->assertJsonPath('tasks.0.workflow_wait_kind', 'signal')
            ->assertJsonPath('tasks.0.workflow_resume_source_kind', 'workflow_signal')
            ->assertJsonPath('tasks.0.workflow_command_id', $commandId)
            ->assertJsonPath('can_update', false)
            ->assertJsonPath('update_blocked_reason', 'earlier_signal_pending');

        $response = $this->postJson('/waterline/api/instances/' . $workflow->id() . '/updates/mark-approved', [
            'arguments' => [
                'approved' => true,
                'source' => 'waterline-ui',
            ],
        ]);

        $response
            ->assertStatus(409)
            ->assertJsonPath('outcome', 'rejected_pending_signal')
            ->assertJsonPath('workflow_id', $workflow->id())
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('command_status', 'rejected')
            ->assertJsonPath('rejection_reason', 'earlier_signal_pending')
            ->assertJsonPath('result', null);
    }

    public function testUpdateReturnsValidationErrorsForInvalidArguments(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $workflow = WorkflowStub::make(TestOperatorCommandWorkflow::class, 'order-update-invalid');
        $workflow->start();

        $this->waitForWorkflowState(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $response = $this->postJson('/waterline/api/instances/' . $workflow->id() . '/updates/mark-approved', [
            'arguments' => [
                'source' => 'console',
                'extra' => true,
            ],
        ]);

        $response
            ->assertStatus(409)
            ->assertJsonPath('outcome', 'rejected_invalid_arguments')
            ->assertJsonPath('workflow_id', $workflow->id())
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('target_scope', 'instance')
            ->assertJsonPath('command_status', 'rejected')
            ->assertJsonPath('rejection_reason', 'invalid_update_arguments')
            ->assertJsonPath('validation_errors.approved.0', 'The approved argument is required.')
            ->assertJsonPath('validation_errors.extra.0', 'Unknown argument [extra].');
    }

    public function testUpdateReturnsValidationErrorsForNullArgumentsWhenTheContractDisallowsNull(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $workflow = WorkflowStub::make(TestOperatorCommandWorkflow::class, 'order-update-null-invalid');
        $workflow->start();

        $this->waitForWorkflowState(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $response = $this->postJson('/waterline/api/instances/' . $workflow->id() . '/updates/mark-approved', [
            'arguments' => [
                'approved' => null,
            ],
        ]);

        $response
            ->assertStatus(409)
            ->assertJsonPath('outcome', 'rejected_invalid_arguments')
            ->assertJsonPath('workflow_id', $workflow->id())
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('target_scope', 'instance')
            ->assertJsonPath('command_status', 'rejected')
            ->assertJsonPath('rejection_reason', 'invalid_update_arguments')
            ->assertJsonPath('validation_errors.approved.0', 'The approved argument cannot be null.');
    }

    public function testSignalTargetsSelectedRunRouteAndAcceptsScalarJsonPayload(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $workflow = WorkflowStub::make(TestOperatorCommandWorkflow::class, 'order-signal-selected-run');
        $workflow->start();

        $this->waitForWorkflowState(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $response = $this->postJson(
            '/waterline/api/instances/' . $workflow->id() . '/runs/' . $workflow->runId() . '/signals/name-provided',
            ['arguments' => 'Taylor'],
        );

        $response
            ->assertStatus(200)
            ->assertJsonPath('outcome', 'signal_received')
            ->assertJsonPath('workflow_id', $workflow->id())
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('target_scope', 'run')
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('command_source', 'waterline')
            ->assertJsonPath('rejection_reason', null);

        $commandId = $response->json('command_id');

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $commandId,
            'workflow_instance_id' => $workflow->id(),
            'workflow_run_id' => $workflow->runId(),
            'command_type' => 'signal',
            'source' => 'waterline',
            'target_scope' => 'run',
            'status' => 'accepted',
            'outcome' => 'signal_received',
        ]);

        $command = WorkflowCommand::query()->findOrFail($commandId);

        $this->assertSame('name-provided', $command->targetName());
        $this->assertSame(['Taylor'], $command->payloadArguments());
        $this->assertSame(
            '/waterline/api/instances/'.$workflow->id().'/runs/'.$workflow->runId().'/signals/name-provided',
            $command->requestPath(),
        );
        $this->assertSame('waterline.instances.runs.signal', $command->requestRouteName());

        $this->waitForWorkflowState(static fn (): bool => $workflow->refresh()->completed());

        $this->assertSame([
            'approved' => false,
            'events' => ['started', 'signal:Taylor'],
            'name' => 'Taylor',
            'workflow_id' => 'order-signal-selected-run',
            'run_id' => $workflow->runId(),
        ], $workflow->output());
    }

    public function testQueryTargetsSelectedRunRouteAndReturnsSerializedResult(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $workflow = WorkflowStub::make(TestOperatorCommandWorkflow::class, 'order-query-selected-run');
        $workflow->start();

        $this->waitForWorkflowState(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $response = $this->postJson(
            '/waterline/api/instances/' . $workflow->id() . '/runs/' . $workflow->runId() . '/queries/events-starting-with',
            ['arguments' => ['prefix' => 'start']],
        );

        $response
            ->assertStatus(200)
            ->assertJsonPath('query_name', 'events-starting-with')
            ->assertJsonPath('workflow_id', $workflow->id())
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('target_scope', 'run');

        $this->assertSame(1, unserialize((string) $response->json('result')));
    }

    public function testQueryResponseUsesDurableQueryNameWhenCalledWithPhpMethodName(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $workflow = WorkflowStub::make(TestOperatorCommandWorkflow::class, 'order-query-method-name');
        $workflow->start();

        $this->waitForWorkflowState(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $response = $this->postJson(
            '/waterline/api/instances/' . $workflow->id() . '/queries/countEventsByPrefix',
            ['arguments' => ['prefix' => 'start']],
        );

        $response
            ->assertStatus(200)
            ->assertJsonPath('query_name', 'events-starting-with')
            ->assertJsonPath('workflow_id', $workflow->id())
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('target_scope', 'instance');

        $this->assertSame(1, unserialize((string) $response->json('result')));
    }

    public function testQueryReturnsValidationErrorsForInvalidArguments(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $workflow = WorkflowStub::make(TestOperatorCommandWorkflow::class, 'order-query-invalid');
        $workflow->start();

        $this->waitForWorkflowState(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $response = $this->postJson(
            '/waterline/api/instances/' . $workflow->id() . '/queries/events-starting-with',
            ['arguments' => ['extra' => 'start']],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('query_name', 'events-starting-with')
            ->assertJsonPath('workflow_id', $workflow->id())
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('target_scope', 'instance')
            ->assertJsonPath('validation_errors.prefix.0', 'The prefix argument is required.')
            ->assertJsonPath('validation_errors.extra.0', 'Unknown argument [extra].');
    }

    public function testQueryReturnsBlockedReasonWhenWorkflowDefinitionCannotBeResolved(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $workflow = WorkflowStub::make(TestOperatorCommandWorkflow::class, 'order-query-definition-unavailable');
        $workflow->start();

        $this->waitForWorkflowState(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        WorkflowRun::query()->whereKey($workflow->runId())->update([
            'workflow_class' => 'Missing\\Workflow\\TestOperatorCommandWorkflow',
            'workflow_type' => 'missing-operator-command-workflow',
        ]);

        $response = $this->postJson(
            '/waterline/api/instances/' . $workflow->id() . '/queries/events-starting-with',
            ['arguments' => ['prefix' => 'start']],
        );

        $response
            ->assertStatus(409)
            ->assertJsonPath('query_name', 'events-starting-with')
            ->assertJsonPath('workflow_id', $workflow->id())
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('target_scope', 'instance')
            ->assertJsonPath('blocked_reason', 'workflow_definition_unavailable')
            ->assertJsonPath(
                'message',
                sprintf(
                    'Workflow %s [%s] cannot execute query [%s] because the workflow definition is unavailable for durable type [%s].',
                    $workflow->runId(),
                    $workflow->id(),
                    'events-starting-with',
                    'missing-operator-command-workflow',
                ),
            );
    }

    public function testSignalReturnsValidationErrorsForInvalidArguments(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $workflow = WorkflowStub::make(TestOperatorCommandWorkflow::class, 'order-signal-invalid');
        $workflow->start();

        $this->waitForWorkflowState(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $response = $this->postJson(
            '/waterline/api/instances/' . $workflow->id() . '/signals/name-provided',
            ['arguments' => ['nickname' => 'Taylor']],
        );

        $response
            ->assertStatus(409)
            ->assertJsonPath('outcome', 'rejected_invalid_arguments')
            ->assertJsonPath('workflow_id', $workflow->id())
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('target_scope', 'instance')
            ->assertJsonPath('command_status', 'rejected')
            ->assertJsonPath('rejection_reason', 'invalid_signal_arguments')
            ->assertJsonPath('validation_errors.name.0', 'The name argument is required.')
            ->assertJsonPath('validation_errors.nickname.0', 'Unknown argument [nickname].');
    }

    public function testSignalReturnsValidationErrorsForTypeMismatchedArguments(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $workflow = WorkflowStub::make(TestOperatorCommandWorkflow::class, 'order-signal-type-invalid');
        $workflow->start();

        $this->waitForWorkflowState(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $response = $this->postJson(
            '/waterline/api/instances/' . $workflow->id() . '/signals/name-provided',
            ['arguments' => ['name' => 123]],
        );

        $response
            ->assertStatus(409)
            ->assertJsonPath('outcome', 'rejected_invalid_arguments')
            ->assertJsonPath('workflow_id', $workflow->id())
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('target_scope', 'instance')
            ->assertJsonPath('command_status', 'rejected')
            ->assertJsonPath('rejection_reason', 'invalid_signal_arguments')
            ->assertJsonPath('validation_errors.name.0', 'The name argument must be of type string.');
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

    public function testRepairRecreatesMissingDelayedActivityRetryTaskForFlowDetail(): void
    {
        config()->set('waterline.engine_source', 'v2');
        Queue::fake();

        $instance = WorkflowInstance::create([
            'id' => 'order-repair-retry-task',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNREPAIRRETRY1',
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

        $activity = ActivityExecution::create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => 'ActivityClass',
            'activity_type' => 'activity.test',
            'status' => 'pending',
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'activities',
            'attempt_count' => 1,
            'retry_policy' => [
                'snapshot_version' => 1,
                'max_attempts' => 3,
                'backoff_seconds' => [30, 60],
            ],
        ]);

        $failedAttemptId = (string) Str::ulid();
        $retryAvailableAt = now()->addSeconds(30);
        $originalRetryTaskId = (string) Str::ulid();
        $retryOfTaskId = (string) Str::ulid();

        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityRetryScheduled, [
            'activity_execution_id' => $activity->id,
            'activity_class' => $activity->activity_class,
            'activity_type' => $activity->activity_type,
            'sequence' => $activity->sequence,
            'retry_task_id' => $originalRetryTaskId,
            'retry_available_at' => $retryAvailableAt->toJSON(),
            'retry_backoff_seconds' => 30,
            'retry_after_attempt_id' => $failedAttemptId,
            'retry_after_attempt' => 1,
            'retry_of_task_id' => $retryOfTaskId,
            'max_attempts' => 3,
            'retry_policy' => $activity->retry_policy,
            'activity' => ActivitySnapshot::fromExecution($activity),
        ], $originalRetryTaskId);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->get('/waterline/api/flows/' . $instance->id)
            ->assertStatus(200)
            ->assertJsonPath('liveness_state', 'repair_needed')
            ->assertJsonPath('can_repair', true)
            ->assertJsonPath('next_task_id', null)
            ->assertJsonPath('tasks.0.type', 'activity')
            ->assertJsonPath('tasks.0.status', 'missing')
            ->assertJsonPath('tasks.0.transport_state', 'missing')
            ->assertJsonPath('tasks.0.task_missing', true)
            ->assertJsonPath('tasks.0.expected_task_id', $originalRetryTaskId)
            ->assertJsonPath('tasks.0.activity_execution_id', $activity->id)
            ->assertJsonPath('tasks.0.retry_of_task_id', $retryOfTaskId)
            ->assertJsonPath('tasks.0.retry_after_attempt_id', $failedAttemptId)
            ->assertJsonPath('tasks.0.retry_after_attempt', 1)
            ->assertJsonPath('tasks.0.retry_backoff_seconds', 30)
            ->assertJsonPath('tasks.0.retry_max_attempts', 3)
            ->assertJsonPath('tasks.0.retry_policy.max_attempts', 3);

        $this->post('/waterline/api/instances/' . $instance->id . '/repair')
            ->assertStatus(200)
            ->assertJsonPath('outcome', 'repair_dispatched')
            ->assertJsonPath('workflow_id', $instance->id)
            ->assertJsonPath('run_id', $run->id)
            ->assertJsonPath('command_status', 'accepted');

        /** @var WorkflowTask $repairedTask */
        $repairedTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', 'activity')
            ->sole();

        $this->assertNotSame($originalRetryTaskId, $repairedTask->id);
        $this->assertSame($retryAvailableAt->toJSON(), $repairedTask->available_at?->toJSON());
        $this->assertSame($activity->id, $repairedTask->payload['activity_execution_id'] ?? null);
        $this->assertSame($retryOfTaskId, $repairedTask->payload['retry_of_task_id'] ?? null);
        $this->assertSame($failedAttemptId, $repairedTask->payload['retry_after_attempt_id'] ?? null);
        $this->assertSame(1, $repairedTask->payload['retry_after_attempt'] ?? null);
        $this->assertSame(30, $repairedTask->payload['retry_backoff_seconds'] ?? null);
        $this->assertSame(3, $repairedTask->payload['max_attempts'] ?? null);
        $this->assertSame($activity->retry_policy, $repairedTask->payload['retry_policy'] ?? null);
        $this->assertSame(1, $repairedTask->attempt_count);
        $this->assertSame(1, $repairedTask->repair_count);

        Queue::assertPushed(
            RunActivityTask::class,
            static fn (RunActivityTask $job): bool => $job->taskId === $repairedTask->id
        );

        $this->get('/waterline/api/flows/' . $instance->id)
            ->assertStatus(200)
            ->assertJsonPath('next_task_id', $repairedTask->id)
            ->assertJsonPath('next_task_type', 'activity')
            ->assertJsonPath('liveness_state', 'activity_task_ready')
            ->assertJsonPath('can_repair', false)
            ->assertJsonPath('tasks.0.transport_state', 'scheduled')
            ->assertJsonPath('tasks.0.retry_of_task_id', $retryOfTaskId)
            ->assertJsonPath('tasks.0.retry_after_attempt_id', $failedAttemptId)
            ->assertJsonPath('tasks.0.retry_after_attempt', 1)
            ->assertJsonPath('tasks.0.retry_backoff_seconds', 30)
            ->assertJsonPath('tasks.0.retry_max_attempts', 3)
            ->assertJsonPath('tasks.0.retry_policy.max_attempts', 3);
    }

    public function testRepairRecreatesMissingActivityTaskFromTypedHistoryWhenActivityExecutionRowIsMissing(): void
    {
        config()->set('waterline.engine_source', 'v2');
        Queue::fake();

        $instance = WorkflowInstance::create([
            'id' => 'order-repair-activity-history',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNREPAIRACT001',
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

        $activity = ActivityExecution::create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => 'ActivityClass',
            'activity_type' => 'activity.test',
            'status' => 'pending',
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'activities',
            'attempt_count' => 0,
            'retry_policy' => [
                'snapshot_version' => 1,
                'max_attempts' => 1,
                'backoff_seconds' => [],
            ],
        ]);
        $activityId = $activity->id;

        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, [
            'activity_execution_id' => $activity->id,
            'activity_class' => $activity->activity_class,
            'activity_type' => $activity->activity_type,
            'sequence' => $activity->sequence,
            'activity' => ActivitySnapshot::fromExecution($activity),
        ]);

        $activity->delete();

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->get('/waterline/api/flows/' . $instance->id)
            ->assertStatus(200)
            ->assertJsonPath('wait_kind', 'activity')
            ->assertJsonPath('liveness_state', 'repair_needed')
            ->assertJsonPath('can_repair', true)
            ->assertJsonPath('next_task_id', null)
            ->assertJsonPath('activities.0.id', $activityId)
            ->assertJsonPath('activities.0.status', 'pending')
            ->assertJsonPath('tasks.0.type', 'activity')
            ->assertJsonPath('tasks.0.status', 'missing')
            ->assertJsonPath('tasks.0.transport_state', 'missing')
            ->assertJsonPath('tasks.0.task_missing', true)
            ->assertJsonPath('tasks.0.activity_execution_id', $activityId);

        $this->post('/waterline/api/instances/' . $instance->id . '/repair')
            ->assertStatus(200)
            ->assertJsonPath('outcome', 'repair_dispatched')
            ->assertJsonPath('workflow_id', $instance->id)
            ->assertJsonPath('run_id', $run->id)
            ->assertJsonPath('command_status', 'accepted');

        /** @var ActivityExecution $restoredActivity */
        $restoredActivity = ActivityExecution::query()->findOrFail($activityId);

        $this->assertSame('pending', $restoredActivity->status->value);
        $this->assertSame('ActivityClass', $restoredActivity->activity_class);
        $this->assertSame('activity.test', $restoredActivity->activity_type);
        $this->assertSame(0, $restoredActivity->attempt_count);
        $this->assertSame(['snapshot_version' => 1, 'max_attempts' => 1, 'backoff_seconds' => []], $restoredActivity->retry_policy);

        /** @var WorkflowTask $repairedTask */
        $repairedTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', 'activity')
            ->sole();

        $this->assertSame($activityId, $repairedTask->payload['activity_execution_id'] ?? null);
        $this->assertSame(1, $repairedTask->repair_count);

        Queue::assertPushed(
            RunActivityTask::class,
            static fn (RunActivityTask $job): bool => $job->taskId === $repairedTask->id
        );

        $this->get('/waterline/api/flows/' . $instance->id)
            ->assertStatus(200)
            ->assertJsonPath('next_task_id', $repairedTask->id)
            ->assertJsonPath('next_task_type', 'activity')
            ->assertJsonPath('liveness_state', 'activity_task_ready')
            ->assertJsonPath('can_repair', false)
            ->assertJsonPath('tasks.0.transport_state', 'ready')
            ->assertJsonPath('tasks.0.activity_execution_id', $activityId);
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
            ->assertJsonPath('requested_run_id', $historicalRun->id)
            ->assertJsonPath('resolved_run_id', $currentRun->id)
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
            ->assertJsonPath('requested_run_id', $historicalRun->id)
            ->assertJsonPath('resolved_run_id', $currentRun->id)
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

        $this->assertSame($historicalRun->id, $command->requestedRunId());
        $this->assertSame($currentRun->id, $command->resolvedRunId());

        $this->assertSame(
            '/waterline/api/instances/'.$instance->id.'/runs/'.$historicalRun->id.'/terminate',
            $command->requestPath(),
        );
        $this->assertSame('waterline.instances.runs.terminate', $command->requestRouteName());
    }

    public function testArchiveTargetsCurrentClosedInstanceRouteAndReturnsAcceptedResponse(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-archive-current',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNARCHCURRENT01',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'completed',
            'closed_reason' => 'completed',
            'arguments' => Serializer::serialize([]),
            'started_at' => now()->subMinutes(5),
            'closed_at' => now()->subMinute(),
            'last_progress_at' => now()->subMinute(),
        ]);

        $instance->update(['current_run_id' => $run->id]);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $response = $this->postJson('/waterline/api/instances/' . $instance->id . '/archive', [
            'reason' => 'exported to cold storage',
        ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('outcome', 'archived')
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
            'command_type' => 'archive',
            'source' => 'waterline',
            'target_scope' => 'instance',
            'status' => 'accepted',
            'outcome' => 'archived',
        ]);

        $this->assertDatabaseHas('workflow_run_summaries', [
            'id' => $run->id,
            'archive_command_id' => $commandId,
            'archive_reason' => 'exported to cold storage',
        ]);

        $this->get('/waterline/api/instances/' . $instance->id)
            ->assertStatus(200)
            ->assertJsonPath('archived_at', fn ($value): bool => is_string($value) && $value !== '')
            ->assertJsonPath('archive_command_id', $commandId)
            ->assertJsonPath('archive_reason', 'exported to cold storage')
            ->assertJsonPath('can_archive', false)
            ->assertJsonPath('archive_blocked_reason', 'run_archived')
            ->assertJsonPath('read_only_reason', 'Run is archived.')
            ->assertJsonPath('commands.0.type', 'archive')
            ->assertJsonPath('timeline.0.type', 'ArchiveRequested')
            ->assertJsonPath('timeline.1.type', 'WorkflowArchived');

        $command = WorkflowCommand::query()->findOrFail($commandId);

        $this->assertSame('/waterline/api/instances/'.$instance->id.'/archive', $command->requestPath());
        $this->assertSame('waterline.instances.archive', $command->requestRouteName());
    }

    public function testArchiveSelectedHistoricalClosedRunDoesNotRedirectToCurrentRun(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-archive-historical',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 2,
        ]);

        $historicalRun = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNARCHHIST001',
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
            'id' => '01JTESTFLOWRUNARCHCURR001',
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

        RunSummaryProjector::project(
            $historicalRun->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );
        RunSummaryProjector::project(
            $currentRun->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $response = $this->postJson(
            '/waterline/api/instances/' . $instance->id . '/runs/' . $historicalRun->id . '/archive',
            ['reason' => 'historical run retained offline'],
        );

        $response
            ->assertStatus(200)
            ->assertJsonPath('outcome', 'archived')
            ->assertJsonPath('workflow_id', $instance->id)
            ->assertJsonPath('run_id', $historicalRun->id)
            ->assertJsonPath('requested_run_id', $historicalRun->id)
            ->assertJsonPath('resolved_run_id', $historicalRun->id)
            ->assertJsonPath('target_scope', 'run')
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('rejection_reason', null);

        $commandId = $response->json('command_id');

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $commandId,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $historicalRun->id,
            'command_type' => 'archive',
            'target_scope' => 'run',
            'status' => 'accepted',
            'outcome' => 'archived',
        ]);

        $this->assertDatabaseHas('workflow_run_summaries', [
            'id' => $historicalRun->id,
            'archive_command_id' => $commandId,
            'archive_reason' => 'historical run retained offline',
        ]);
        $this->assertNull(WorkflowRun::query()->findOrFail($currentRun->id)->archived_at);
    }

    public function testArchiveRejectsOpenRun(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'order-archive-open',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => '01JTESTFLOWRUNARCHOPEN001',
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

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $response = $this->post('/waterline/api/instances/' . $instance->id . '/archive');

        $response
            ->assertStatus(409)
            ->assertJsonPath('outcome', 'rejected_run_not_closed')
            ->assertJsonPath('workflow_id', $instance->id)
            ->assertJsonPath('run_id', $run->id)
            ->assertJsonPath('target_scope', 'instance')
            ->assertJsonPath('command_status', 'rejected')
            ->assertJsonPath('rejection_reason', 'run_not_closed');

        $this->assertNull(WorkflowRun::query()->findOrFail($run->id)->archived_at);
    }

    private function wakeTaskWatchdog(): void
    {
        Cache::forget(TaskWatchdog::LOOP_THROTTLE_KEY);
        TaskWatchdog::wake();
    }

    private function runReadyWorkflowTask(string $runId): void
    {
        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', 'workflow')
            ->where('status', 'ready')
            ->orderBy('created_at')
            ->firstOrFail();

        $this->app->call([new RunWorkflowTask($task->id), 'handle']);
    }

    private function runReadyActivityTaskForSequence(string $runId, int $sequence): void
    {
        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $runId)
            ->where('sequence', $sequence)
            ->firstOrFail();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', 'activity')
            ->where('status', 'ready')
            ->get()
            ->sole(
                static fn (WorkflowTask $task): bool => ($task->payload['activity_execution_id'] ?? null) === $execution->id
            );

        $this->app->call([new RunActivityTask($task->id), 'handle']);
    }

    /**
     * @param list<array<string, mixed>> $path
     */
    private function replaceActivityScheduledParallelPath(string $runId, int $sequence, array $path): void
    {
        /** @var WorkflowHistoryEvent $event */
        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::ActivityScheduled->value)
            ->get()
            ->sole(
                static fn (WorkflowHistoryEvent $event): bool => ($event->payload['sequence'] ?? null) === $sequence
            );

        $payload = is_array($event->payload) ? $event->payload : [];
        $last = $path[array_key_last($path)] ?? [];

        $event->forceFill([
            'payload' => array_merge($payload, $last, [
                'parallel_group_path' => $path,
            ]),
        ])->save();
    }

    private function removeActivityHistoryParallelMetadata(string $runId, int $sequence): void
    {
        WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->whereIn('event_type', [
                HistoryEventType::ActivityScheduled->value,
                HistoryEventType::ActivityStarted->value,
                HistoryEventType::ActivityHeartbeatRecorded->value,
                HistoryEventType::ActivityRetryScheduled->value,
                HistoryEventType::ActivityCompleted->value,
                HistoryEventType::ActivityFailed->value,
            ])
            ->get()
            ->each(static function (WorkflowHistoryEvent $event) use ($sequence): void {
                $payload = is_array($event->payload) ? $event->payload : [];

                if (($payload['sequence'] ?? null) !== $sequence) {
                    return;
                }

                unset(
                    $payload['parallel_group_id'],
                    $payload['parallel_group_kind'],
                    $payload['parallel_group_base_sequence'],
                    $payload['parallel_group_size'],
                    $payload['parallel_group_index'],
                    $payload['parallel_group_path'],
                );

                $event->forceFill(['payload' => $payload])->save();
            });
    }

    private function waitForWorkflowState(callable $condition, int $attempts = 50): void
    {
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if ($condition()) {
                return;
            }

            usleep(100000);
        }

        $this->fail('Workflow did not reach the expected state before timeout.');
    }
}
