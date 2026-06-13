<?php

namespace Waterline\Tests\Feature;

use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Waterline\Tests\Fixtures\V2\TestCommandContractWorkflow;
use Waterline\Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\HistoryExportRedactor;
use Workflow\V2\Contracts\OperatorObservabilityRepository;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowRunTimerEntry;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\HistoryExport;

class V2HistoryExportControllerTest extends TestCase
{
    public function testCanonicalRouteExportsSelectedRunHistoryBundle(): void
    {
        config()->set('waterline.engine_source', 'v2');

        [$instance, $run] = $this->createCompletedRunWithHistory();

        $this->get('/waterline/api/instances/'.$instance->id.'/runs/'.$run->id.'/history-export')
            ->assertStatus(200)
            ->assertJsonPath('schema', HistoryExport::SCHEMA)
            ->assertJsonPath('schema_version', HistoryExport::SCHEMA_VERSION)
            ->assertJsonPath('history_complete', true)
            ->assertJsonPath('workflow.instance_id', $instance->id)
            ->assertJsonPath('workflow.run_id', $run->id)
            ->assertJsonPath('workflow.status', 'completed')
            ->assertJsonPath('payloads.arguments.available', true)
            ->assertJsonPath('summary.history_event_count', 2)
            ->assertJsonPath('selected_run.waits_projection_source', 'workflow_run_waits')
            ->assertJsonPath('selected_run.timeline_projection_source', 'workflow_run_timeline_entries_rebuilt')
            ->assertJsonPath('selected_run.timers_projection_source', 'workflow_run_timer_entries')
            ->assertJsonPath('integrity.canonicalization', 'json-recursive-ksort-v1')
            ->assertJsonPath('integrity.checksum_algorithm', 'sha256')
            ->assertJsonPath('integrity.signature', null)
            ->assertJsonPath('timeline.0.type', 'WorkflowStarted')
            ->assertJsonPath('history_events.0.type', 'WorkflowStarted')
            ->assertJsonPath('history_events.1.type', 'WorkflowCompleted');
    }

    public function testCurrentRunRouteExportsCurrentSelectedRunHistoryBundle(): void
    {
        config()->set('waterline.engine_source', 'v2');

        [$instance, $run] = $this->createCompletedRunWithHistory();

        $this->get('/waterline/api/instances/'.$instance->id.'/history-export')
            ->assertStatus(200)
            ->assertJsonPath('schema', HistoryExport::SCHEMA)
            ->assertJsonPath('workflow.instance_id', $instance->id)
            ->assertJsonPath('workflow.run_id', $run->id)
            ->assertJsonPath('workflow.current_run_id', $run->id)
            ->assertJsonPath('workflow.current_run_source', 'run_order_fallback')
            ->assertJsonPath('selected_run.timeline_projection_source', 'workflow_run_timeline_entries_rebuilt')
            ->assertJsonPath('selected_run.timers_projection_source', 'workflow_run_timer_entries')
            ->assertJsonPath('timeline.1.type', 'WorkflowCompleted')
            ->assertJsonPath('history_events.0.type', 'WorkflowStarted')
            ->assertJsonPath('history_events.1.type', 'WorkflowCompleted');
    }

    public function testLegacyRunRouteExportsTheSameBundleShape(): void
    {
        config()->set('waterline.engine_source', 'v2');

        [$instance, $run] = $this->createCompletedRunWithHistory();

        $this->get('/waterline/api/flows/'.$run->id.'/history-export')
            ->assertStatus(200)
            ->assertJsonPath('schema', HistoryExport::SCHEMA)
            ->assertJsonPath('workflow.instance_id', $instance->id)
            ->assertJsonPath('workflow.run_id', $run->id)
            ->assertJsonPath('history_events.0.type', 'WorkflowStarted')
            ->assertJsonPath('history_events.1.type', 'WorkflowCompleted');
    }

    public function testHistoryExportBackfillsLoadableLegacyCommandContracts(): void
    {
        $this->markTestSkipped('The current public workflow v2 API floor no longer performs Waterline-side command contract backfill during export.');

        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'history-export-command-contract-backfill',
            'workflow_class' => TestCommandContractWorkflow::class,
            'workflow_type' => 'workflow.command-contract',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => (string) Str::ulid(),
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
            'id' => (string) Str::ulid(),
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

        $this->get('/waterline/api/flows/'.$run->id.'/history-export')
            ->assertOk()
            ->assertJsonPath('history_events.0.type', 'WorkflowStarted')
            ->assertJsonPath('history_events.0.payload.declared_query_contracts.0.name', 'current-stage')
            ->assertJsonPath('history_events.0.payload.declared_signal_contracts.0.name', 'approved-by')
            ->assertJsonPath('history_events.0.payload.declared_update_contracts.0.name', 'mark-approved');

        /** @var WorkflowHistoryEvent $started */
        $started = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->sole();

        $this->assertSame(['current-stage', 'stageMatches'], $started->payload['declared_queries'] ?? null);
        $this->assertSame('current-stage', $started->payload['declared_query_contracts'][0]['name'] ?? null);
        $this->assertSame(['approved-by', 'rejected-by'], $started->payload['declared_signals'] ?? null);
        $this->assertSame('approved-by', $started->payload['declared_signal_contracts'][0]['name'] ?? null);
        $this->assertSame(['mark-approved'], $started->payload['declared_updates'] ?? null);
        $this->assertSame('mark-approved', $started->payload['declared_update_contracts'][0]['name'] ?? null);
    }

    public function testCurrentRunHistoryExportRoutePrefersContinueAsNewLineageWhenCurrentRunPointerIsMissing(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'history-export-lineage-current',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.export',
            'run_count' => 3,
        ]);

        $historicalRun = WorkflowRun::create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.export',
            'status' => 'completed',
            'closed_reason' => 'continued',
            'payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'last_history_sequence' => 1,
            'started_at' => now()->subMinutes(10),
            'closed_at' => now()->subMinutes(9),
            'last_progress_at' => now()->subMinutes(9),
        ]);

        $continuedRun = WorkflowRun::create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 2,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.export',
            'status' => 'waiting',
            'payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'last_history_sequence' => 1,
            'started_at' => now()->subMinutes(4),
            'last_progress_at' => now()->subMinutes(3),
        ]);

        $strayRun = WorkflowRun::create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 3,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.export',
            'status' => 'waiting',
            'payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'last_history_sequence' => 1,
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subMinute(),
        ]);

        $instance->update(['current_run_id' => null]);

        WorkflowHistoryEvent::create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $historicalRun->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::WorkflowStarted->value,
            'payload' => [
                'workflow_type' => 'workflow.export',
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $historicalRun->id,
            ],
            'recorded_at' => now()->subMinutes(10),
        ]);

        WorkflowHistoryEvent::create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $continuedRun->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::WorkflowStarted->value,
            'payload' => [
                'workflow_type' => 'workflow.export',
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $continuedRun->id,
                'continued_from_run_id' => $historicalRun->id,
            ],
            'recorded_at' => now()->subMinutes(4),
        ]);

        WorkflowHistoryEvent::create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $strayRun->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::WorkflowStarted->value,
            'payload' => [
                'workflow_type' => 'workflow.export',
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $strayRun->id,
            ],
            'recorded_at' => now()->subMinute(),
        ]);

        $this->get('/waterline/api/instances/'.$instance->id.'/history-export')
            ->assertStatus(200)
            ->assertJsonPath('workflow.instance_id', $instance->id)
            ->assertJsonPath('workflow.run_id', $continuedRun->id)
            ->assertJsonPath('workflow.current_run_id', $continuedRun->id)
            ->assertJsonPath('workflow.current_run_source', 'continue_as_new_lineage');
    }

    public function testHistoryExportMarksRowOnlyTerminalActivityResultsAsUnsupported(): void
    {
        config()->set('waterline.engine_source', 'v2');

        [$instance, $run] = $this->createCompletedRunWithHistory();
        $attemptId = (string) Str::ulid();

        /** @var ActivityExecution $activity */
        $activity = ActivityExecution::create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 2,
            'activity_class' => 'App\\Activities\\WaterlineRowOnlyActivity',
            'activity_type' => 'waterline.row-only.activity',
            'status' => ActivityStatus::Failed->value,
            'arguments' => Serializer::serialize(['order-123']),
            'result' => Serializer::serialize('mutable failure result'),
            'connection' => 'redis',
            'queue' => 'activities',
            'attempt_count' => 1,
            'current_attempt_id' => $attemptId,
            'started_at' => now()->subMinutes(2),
            'closed_at' => now()->subMinute(),
        ]);

        $this->get('/waterline/api/instances/'.$instance->id.'/runs/'.$run->id.'/history-export')
            ->assertStatus(200)
            ->assertJsonPath('actionability_contract.schema', 'waterline.actionability')
            ->assertJsonPath('actionability_contract.version', 1)
            ->assertJsonPath('waits.0.diagnostic_only', true)
            ->assertJsonPath('waits.0.actionability.state', 'diagnostic_only')
            ->assertJsonPath('waits.0.actionability.repair_source', false)
            ->assertJsonPath('waits.0.resume_source_kind', null)
            ->assertJsonPath('waits.0.resume_source_id', null)
            ->assertJsonPath('activities.0.id', $activity->id)
            ->assertJsonPath('activities.0.status', 'unsupported')
            ->assertJsonPath('activities.0.source_status', 'failed')
            ->assertJsonPath('activities.0.row_status', 'failed')
            ->assertJsonPath('activities.0.history_authority', 'unsupported_terminal_without_history')
            ->assertJsonPath('activities.0.history_unsupported_reason', 'terminal_activity_row_without_typed_history')
            ->assertJsonPath('activities.0.actionability.state', 'diagnostic_only')
            ->assertJsonPath('activities.0.actionability.repair_source', false)
            ->assertJsonPath('activities.0.history_event_types', [])
            ->assertJsonPath('activities.0.current_attempt_id', $attemptId)
            ->assertJsonPath('activities.0.attempts.0.id', $attemptId)
            ->assertJsonPath('activities.0.attempts.0.activity_execution_id', $activity->id)
            ->assertJsonPath('activities.0.attempts.0.workflow_task_id', null)
            ->assertJsonPath('activities.0.attempts.0.status', 'failed')
            ->assertJsonPath('activities.0.result', null)
            ->assertJsonPath('activities.0.closed_at', null);
    }

    public function testHistoryExportMarksRowOnlyTerminalTimersAsUnsupported(): void
    {
        config()->set('waterline.engine_source', 'v2');

        [$instance, $run] = $this->createCompletedRunWithHistory();

        /** @var WorkflowTimer $timer */
        $timer = WorkflowTimer::create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 2,
            'status' => TimerStatus::Fired->value,
            'delay_seconds' => 60,
            'fire_at' => now()->subMinutes(2),
            'fired_at' => now()->subMinute(),
        ]);

        $this->get('/waterline/api/instances/'.$instance->id.'/runs/'.$run->id.'/history-export')
            ->assertStatus(200)
            ->assertJsonPath('waits.0.diagnostic_only', true)
            ->assertJsonPath('waits.0.resume_source_kind', null)
            ->assertJsonPath('waits.0.resume_source_id', null)
            ->assertJsonPath('timers.0.id', $timer->id)
            ->assertJsonPath('timers.0.status', 'unsupported')
            ->assertJsonPath('timers.0.diagnostic_only', true)
            ->assertJsonPath('timers.0.source_status', 'fired')
            ->assertJsonPath('timers.0.row_status', 'fired')
            ->assertJsonPath('timers.0.history_authority', 'unsupported_terminal_without_history')
            ->assertJsonPath('timers.0.history_unsupported_reason', 'terminal_timer_row_without_typed_history')
            ->assertJsonPath('timers.0.history_event_types', [])
            ->assertJsonPath('timers.0.fired_at', null);
    }

    public function testHistoryExportRebuildsNonCurrentProjectedTimerRowsWithoutRowStatus(): void
    {
        config()->set('waterline.engine_source', 'v2');

        [$instance, $run] = $this->createCompletedRunWithHistory();

        /** @var WorkflowTimer $timer */
        $timer = WorkflowTimer::create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 2,
            'status' => TimerStatus::Fired->value,
            'delay_seconds' => 60,
            'fire_at' => now()->subMinutes(2),
            'fired_at' => now()->subMinute(),
        ]);

        $this->get('/waterline/api/instances/'.$instance->id.'/runs/'.$run->id.'/history-export')
            ->assertStatus(200);

        $entry = WorkflowRunTimerEntry::query()
            ->where('workflow_run_id', $run->id)
            ->where('timer_id', $timer->id)
            ->firstOrFail();

        $payload = $entry->payload;
        unset($payload['row_status']);

        $entry->forceFill([
            'schema_version' => WorkflowRunTimerEntry::CURRENT_SCHEMA_VERSION - 1,
            'payload' => $payload,
        ])->save();

        $this->get('/waterline/api/instances/'.$instance->id.'/runs/'.$run->id.'/history-export')
            ->assertStatus(200)
            ->assertJsonPath('selected_run.timers_projection_source', 'workflow_run_timer_entries_rebuilt')
            ->assertJsonPath('selected_run.timers_projection_rebuild_reasons.0', 'schema_version_mismatch')
            ->assertJsonPath('selected_run.timers_projection_rebuild_reasons.1', 'stale_projection')
            ->assertJsonPath('timers.0.id', $timer->id)
            ->assertJsonPath('timers.0.status', 'unsupported')
            ->assertJsonPath('timers.0.source_status', 'fired')
            ->assertJsonPath('timers.0.row_status', 'fired')
            ->assertJsonPath('timers.0.history_authority', 'unsupported_terminal_without_history')
            ->assertJsonPath('timers.0.diagnostic_only', true);

        $this->assertDatabaseHas('workflow_run_timer_entries', [
            'workflow_run_id' => $run->id,
            'timer_id' => $timer->id,
            'schema_version' => WorkflowRunTimerEntry::CURRENT_SCHEMA_VERSION,
        ]);
    }

    public function testHistoryExportRoutesApplyConfiguredRedactionPolicy(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('workflows.v2.history_export.redactor', new class() implements HistoryExportRedactor {
            /**
             * @param array<string, mixed> $context
             *
             * @return array<string, mixed>
             */
            public function redact(mixed $value, array $context): array
            {
                return [
                    'redacted' => true,
                    'path' => $context['path'],
                ];
            }
        });

        [$instance, $run] = $this->createCompletedRunWithHistory();

        $this->get('/waterline/api/instances/'.$instance->id.'/runs/'.$run->id.'/history-export')
            ->assertStatus(200)
            ->assertJsonPath('redaction.applied', true)
            ->assertJsonPath('payloads.arguments.data.redacted', true)
            ->assertJsonPath('payloads.arguments.data.path', 'payloads.arguments.data')
            ->assertJsonPath('integrity.checksum', fn ($value): bool => is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1)
            ->assertJsonPath('history_events.0.payload.path', 'history_events.0.payload');
    }

    public function testFallbackSelectionHistoryExportPreservesConfiguredRedactionPolicy(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'default');
        config()->set('workflows.v2.history_export.redactor', new class() implements HistoryExportRedactor {
            /**
             * @param array<string, mixed> $context
             *
             * @return array<string, mixed>
             */
            public function redact(mixed $value, array $context): array
            {
                return [
                    'redacted' => true,
                    'path' => $context['path'],
                    'workflow_run_id' => $context['workflow_run_id'],
                ];
            }
        });

        [$instance, $run] = $this->createCompletedRunWithHistory();
        $instance->forceFill(['namespace' => null])->save();
        $run->forceFill(['namespace' => 'default'])->save();
        WorkflowRunSummary::whereKey($run->id)->update(['namespace' => null]);

        $this->app->instance(OperatorObservabilityRepository::class, new class() implements OperatorObservabilityRepository {
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
                ?CarbonInterface $exportedAt = null,
                HistoryExportRedactor|callable|null $redactor = null,
            ): array {
                $export = [
                    'schema' => HistoryExport::SCHEMA,
                    'schema_version' => HistoryExport::SCHEMA_VERSION,
                    'workflow' => [
                        'instance_id' => $run->workflow_instance_id,
                        'run_id' => $run->id,
                    ],
                    'payloads' => [
                        'arguments' => [
                            'available' => true,
                            'data' => 'unredacted-arguments',
                        ],
                    ],
                    'history_events' => [
                        [
                            'id' => 'fallback-event-1',
                            'type' => 'WorkflowStarted',
                            'sequence' => 1,
                            'payload' => ['secret' => 'unredacted-history'],
                        ],
                    ],
                    'waits' => [],
                    'timeline' => [],
                    'timers' => [],
                    'activities' => [],
                    'redaction' => [
                        'applied' => false,
                        'policy' => null,
                        'paths' => [],
                    ],
                    'selected_run' => [
                        'projection_fallback' => 'durable_run',
                    ],
                ];

                if ($redactor === null) {
                    return $export;
                }

                $paths = ['payloads.arguments.data', 'history_events.0.payload'];
                $export['payloads']['arguments']['data'] = $this->redact($redactor, 'unredacted-arguments', [
                    'path' => 'payloads.arguments.data',
                    'category' => 'workflow_payload',
                    'workflow_instance_id' => $run->workflow_instance_id,
                    'workflow_run_id' => $run->id,
                ]);
                $export['history_events'][0]['payload'] = $this->redact($redactor, ['secret' => 'unredacted-history'], [
                    'path' => 'history_events.0.payload',
                    'category' => 'history_event',
                    'workflow_instance_id' => $run->workflow_instance_id,
                    'workflow_run_id' => $run->id,
                    'history_event_id' => 'fallback-event-1',
                    'history_event_type' => 'WorkflowStarted',
                    'sequence' => 1,
                ]);
                $export['redaction'] = [
                    'applied' => true,
                    'policy' => $redactor instanceof HistoryExportRedactor ? $redactor::class : 'callable',
                    'paths' => $paths,
                ];

                return $export;
            }

            public function dashboardSummary(?CarbonInterface $now = null, ?string $namespace = null): array
            {
                return [];
            }

            public function metrics(?CarbonInterface $now = null, ?string $namespace = null): array
            {
                return [];
            }

            /**
             * @param array<string, mixed> $context
             */
            private function redact(HistoryExportRedactor|callable $redactor, mixed $value, array $context): mixed
            {
                return $redactor instanceof HistoryExportRedactor
                    ? $redactor->redact($value, $context)
                    : $redactor($value, $context);
            }
        });

        $this->get('/waterline/api/instances/'.$instance->id.'/runs/'.$run->id.'/history-export')
            ->assertOk()
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('workflow.namespace', 'default')
            ->assertJsonPath('operator_scope.namespace', 'default')
            ->assertJsonPath('selected_run.projection_fallback', 'durable_run')
            ->assertJsonPath('redaction.applied', true)
            ->assertJsonPath('redaction.paths.0', 'payloads.arguments.data')
            ->assertJsonPath('redaction.paths.1', 'history_events.0.payload')
            ->assertJsonPath('payloads.arguments.data.redacted', true)
            ->assertJsonPath('payloads.arguments.data.workflow_run_id', $run->id)
            ->assertJsonPath('history_events.0.payload.path', 'history_events.0.payload');
    }

    public function testCanonicalRouteExportsLineageLinksFromTypedHistoryWhenLinkRowsAreMissing(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $parentInstance = WorkflowInstance::create([
            'id' => 'history-export-waterline-parent',
            'workflow_class' => 'ParentWorkflowClass',
            'workflow_type' => 'workflow.export.parent',
            'run_count' => 1,
        ]);

        $parentRun = WorkflowRun::create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $parentInstance->id,
            'run_number' => 1,
            'workflow_class' => 'ParentWorkflowClass',
            'workflow_type' => 'workflow.export.parent',
            'status' => 'waiting',
            'payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'last_history_sequence' => 1,
            'started_at' => now()->subMinutes(5),
            'last_progress_at' => now()->subMinutes(5),
        ]);
        $parentInstance->update(['current_run_id' => $parentRun->id]);

        WorkflowHistoryEvent::create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $parentRun->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::WorkflowStarted->value,
            'payload' => ['workflow_type' => 'workflow.export.parent'],
            'recorded_at' => now()->subMinutes(5),
        ]);

        $childInstance = WorkflowInstance::create([
            'id' => 'history-export-wateDC3B031',
            'workflow_class' => 'ChildWorkflowClass',
            'workflow_type' => 'workflow.export.child',
            'run_count' => 1,
        ]);

        $childRun = WorkflowRun::create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $childInstance->id,
            'run_number' => 1,
            'workflow_class' => 'ChildWorkflowClass',
            'workflow_type' => 'workflow.export.child',
            'status' => 'waiting',
            'payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'last_history_sequence' => 0,
            'started_at' => now()->subMinutes(4),
            'last_progress_at' => now()->subMinutes(4),
        ]);
        $childInstance->update(['current_run_id' => $childRun->id]);

        $childCallId = (string) Str::ulid();
        $link = WorkflowLink::create([
            'id' => $childCallId,
            'link_type' => 'child_workflow',
            'sequence' => 2,
            'parent_workflow_instance_id' => $parentInstance->id,
            'parent_workflow_run_id' => $parentRun->id,
            'child_workflow_instance_id' => $childInstance->id,
            'child_workflow_run_id' => $childRun->id,
            'is_primary_parent' => true,
        ]);

        WorkflowHistoryEvent::create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $parentRun->id,
            'sequence' => 2,
            'event_type' => HistoryEventType::ChildRunStarted->value,
            'payload' => [
                'sequence' => 2,
                'workflow_link_id' => $link->id,
                'child_call_id' => $childCallId,
                'child_workflow_instance_id' => $childInstance->id,
                'child_workflow_run_id' => $childRun->id,
                'child_workflow_class' => $childRun->workflow_class,
                'child_workflow_type' => $childRun->workflow_type,
                'child_run_number' => $childRun->run_number,
                'child_status' => $childRun->status->value,
            ],
            'recorded_at' => now()->subMinutes(4),
        ]);
        $parentRun->update([
            'last_history_sequence' => 2,
            'last_progress_at' => now()->subMinutes(4),
        ]);

        WorkflowHistoryEvent::create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $childRun->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::WorkflowStarted->value,
            'payload' => [
                'workflow_type' => 'workflow.export.child',
                'workflow_link_id' => $link->id,
                'parent_workflow_instance_id' => $parentInstance->id,
                'parent_workflow_run_id' => $parentRun->id,
                'parent_sequence' => 2,
                'child_call_id' => $childCallId,
            ],
            'recorded_at' => now()->subMinutes(4),
        ]);
        $childRun->update(['last_history_sequence' => 1]);

        $link->delete();

        $this->get('/waterline/api/instances/'.$parentInstance->id.'/runs/'.$parentRun->id.'/history-export')
            ->assertStatus(200)
            ->assertJsonPath('links.children.0.id', $childCallId)
            ->assertJsonPath('links.children.0.type', 'child_workflow')
            ->assertJsonPath('links.children.0.parent_workflow_instance_id', $parentInstance->id)
            ->assertJsonPath('links.children.0.parent_workflow_run_id', $parentRun->id)
            ->assertJsonPath('links.children.0.child_workflow_instance_id', $childInstance->id)
            ->assertJsonPath('links.children.0.child_workflow_run_id', $childRun->id)
            ->assertJsonPath('links.children.0.child_call_id', $childCallId)
            ->assertJsonPath('links.children.0.sequence', 2);

        $this->get('/waterline/api/instances/'.$childInstance->id.'/runs/'.$childRun->id.'/history-export')
            ->assertStatus(200)
            ->assertJsonPath('links.parents.0.id', $childCallId)
            ->assertJsonPath('links.parents.0.type', 'child_workflow')
            ->assertJsonPath('links.parents.0.parent_workflow_instance_id', $parentInstance->id)
            ->assertJsonPath('links.parents.0.parent_workflow_run_id', $parentRun->id)
            ->assertJsonPath('links.parents.0.child_workflow_instance_id', $childInstance->id)
            ->assertJsonPath('links.parents.0.child_workflow_run_id', $childRun->id)
            ->assertJsonPath('links.parents.0.child_call_id', $childCallId)
            ->assertJsonPath('links.parents.0.sequence', 2);
    }

    /**
     * @return array{WorkflowInstance, WorkflowRun}
     */
    private function createCompletedRunWithHistory(): array
    {
        $instance = WorkflowInstance::create([
            'id' => 'history-export-waterline',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.export',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.export',
            'status' => 'completed',
            'closed_reason' => 'completed',
            'payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize(['order-123']),
            'output' => Serializer::serialize(['ok' => true]),
            'connection' => 'redis',
            'queue' => 'default',
            'last_history_sequence' => 2,
            'started_at' => now()->subMinutes(5),
            'closed_at' => now()->subMinute(),
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
            'workflow_type' => 'workflow.export',
            'status' => 'completed',
            'status_bucket' => 'completed',
            'closed_reason' => 'completed',
            'started_at' => $run->started_at,
            'closed_at' => $run->closed_at,
            'duration_ms' => 240000,
            'exception_count' => 0,
            'history_event_count' => 2,
            'history_size_bytes' => 128,
            'continue_as_new_recommended' => false,
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinute(),
        ]);

        WorkflowHistoryEvent::create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::WorkflowStarted->value,
            'payload' => ['workflow_type' => 'workflow.export'],
            'recorded_at' => now()->subMinutes(5),
        ]);

        WorkflowHistoryEvent::create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 2,
            'event_type' => HistoryEventType::WorkflowCompleted->value,
            'payload' => ['result_available' => true],
            'recorded_at' => now()->subMinute(),
        ]);

        return [$instance, $run];
    }
}
