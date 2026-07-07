<?php

namespace Waterline\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Waterline\Models\WorkerRegistration;
use Waterline\Support\SelectedRunCommandContract;
use Waterline\Tests\Fixtures\V2\TestOperatorCommandWorkflow;
use Waterline\Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowSignal;

class V2SelectedRunQueryActionTest extends TestCase
{
    public function testSelectedRunQueryActionReportsDefinitionLimitationForDurableDeclaredExternalQuery(): void
    {
        config()->set('waterline.engine_source', 'v2');

        [$instance, $run] = $this->createCounterRunWithDurableCurrentQuery();

        $this->getJson('/waterline/api/instances/'.$instance->id.'/runs/'.$run->id)
            ->assertStatus(200)
            ->assertJsonPath('declared_queries', ['current'])
            ->assertJsonPath('declared_query_targets.0.name', 'current')
            ->assertJsonPath('observer_state.queries.declared', ['current'])
            ->assertJsonPath('observer_state.signals.accepted_count', 2)
            ->assertJsonPath('observer_state.signals.items.0.name', 'increment')
            ->assertJsonPath('observer_state.signals.items.0.arguments', [3])
            ->assertJsonPath('observer_state.signals.items.1.arguments', [7]);

        $response = $this->postJson(
            '/waterline/api/instances/'.$instance->id.'/runs/'.$run->id.'/queries/current',
            ['arguments' => []],
        );

        $response
            ->assertStatus(409)
            ->assertJsonPath('query_name', 'current')
            ->assertJsonPath('workflow_id', $instance->id)
            ->assertJsonPath('run_id', $run->id)
            ->assertJsonPath('target_scope', 'run')
            ->assertJsonPath('blocked_reason', 'workflow_definition_unavailable')
            ->assertJsonPath('reason', 'workflow_definition_unavailable')
            ->assertJsonPath('limitation.reason', 'workflow_definition_unavailable')
            ->assertJsonPath('limitation.declared_query', true);

        $this->assertStringNotContainsString('not declared', $response->json('message'));
    }

    public function testSelectedRunQueryActionKeepsUndeclaredResponseForUnknownQuery(): void
    {
        config()->set('waterline.engine_source', 'v2');

        [$instance, $run] = $this->createCounterRunWithDurableCurrentQuery();

        $response = $this->postJson(
            '/waterline/api/instances/'.$instance->id.'/runs/'.$run->id.'/queries/missing',
            ['arguments' => []],
        );

        $response
            ->assertStatus(409)
            ->assertJsonPath('query_name', 'missing')
            ->assertJsonMissingPath('blocked_reason')
            ->assertJsonMissingPath('reason')
            ->assertJsonMissingPath('limitation');

        $this->assertStringContainsString('not declared', $response->json('message'));
    }

    public function testSelectedRunQueryActionUsesExternalWorkerCommandContractWhenDurableHistoryIsMissing(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $this->createWorkerRegistrationsTable();
        [$instance, $run] = $this->createCounterRunWithoutDurableCommandContract();

        WorkerRegistration::query()->create([
            'worker_id' => 'counter-worker',
            'namespace' => 'default',
            'task_queue' => 'default',
            'runtime' => 'python',
            'sdk_version' => '0.4.95',
            'build_id' => null,
            'supported_workflow_types' => ['external.counter'],
            'workflow_definition_fingerprints' => [],
            'workflow_command_contracts' => [
                'external.counter' => $this->externalCounterCommandContract(),
            ],
            'supported_activity_types' => [],
            'last_heartbeat_at' => now(),
            'status' => 'active',
        ]);

        $this->getJson('/waterline/api/instances/'.$instance->id.'/runs/'.$run->id)
            ->assertStatus(200)
            ->assertJsonPath('declared_queries', ['count-at-least', 'current', 'state'])
            ->assertJsonPath('declared_query_targets.2.name', 'state')
            ->assertJsonPath('declared_query_targets.2.source', SelectedRunCommandContract::SOURCE_EXTERNAL_WORKER_REGISTRATION)
            ->assertJsonPath('declared_contract_source', SelectedRunCommandContract::SOURCE_EXTERNAL_WORKER_REGISTRATION)
            ->assertJsonPath('can_query', true)
            ->assertJsonPath('query_blocked_reason', null)
            ->assertJsonPath('observer_state.queries.declared', ['count-at-least', 'current', 'state'])
            ->assertJsonPath('observer_state.queries.targets.2.name', 'state')
            ->assertJsonPath('observer_state.signals.accepted_count', 2)
            ->assertJsonPath('observer_state.signals.items.0.arguments', [3])
            ->assertJsonPath('observer_state.signals.items.1.arguments', [7]);

        $response = $this->postJson(
            '/waterline/api/instances/'.$instance->id.'/runs/'.$run->id.'/queries/state',
            ['arguments' => []],
        );

        $response
            ->assertStatus(409)
            ->assertJsonPath('query_name', 'state')
            ->assertJsonPath('workflow_id', $instance->id)
            ->assertJsonPath('run_id', $run->id)
            ->assertJsonPath('target_scope', 'run')
            ->assertJsonPath('declaration_source', SelectedRunCommandContract::SOURCE_EXTERNAL_WORKER_REGISTRATION)
            ->assertJsonPath('blocked_reason', 'workflow_definition_unavailable')
            ->assertJsonPath('reason', 'workflow_definition_unavailable')
            ->assertJsonPath('limitation.type', 'waterline_selected_run_query_action_definition_unavailable')
            ->assertJsonPath('limitation.declared_query', true)
            ->assertJsonPath('limitation.declaration_source', SelectedRunCommandContract::SOURCE_EXTERNAL_WORKER_REGISTRATION);

        $this->assertStringNotContainsString('not declared', $response->json('message'));
    }

    /**
     * @return array{WorkflowInstance, WorkflowRun}
     */
    private function createCounterRunWithDurableCurrentQuery(): array
    {
        $instanceId = 'waterline-counter-'.strtolower((string) Str::ulid());
        $runId = (string) Str::ulid();
        $startedAt = now()->subMinute();

        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
            'workflow_class' => TestOperatorCommandWorkflow::class,
            'workflow_type' => 'workflow.operator-command',
            'current_run_id' => $runId,
            'run_count' => 1,
            'reserved_at' => $startedAt,
            'started_at' => $startedAt,
        ]);

        $run = WorkflowRun::query()->create([
            'id' => $runId,
            'workflow_instance_id' => $instanceId,
            'run_number' => 1,
            'workflow_class' => TestOperatorCommandWorkflow::class,
            'workflow_type' => 'workflow.operator-command',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'last_history_sequence' => 1,
            'last_command_sequence' => 2,
            'started_at' => $startedAt,
            'last_progress_at' => now()->subSeconds(10),
        ]);

        WorkflowRunSummary::query()->create([
            'id' => $runId,
            'workflow_instance_id' => $instanceId,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => TestOperatorCommandWorkflow::class,
            'workflow_type' => 'workflow.operator-command',
            'status' => 'waiting',
            'status_bucket' => 'running',
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => $startedAt,
            'sort_timestamp' => now()->subSeconds(10),
        ]);

        WorkflowHistoryEvent::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $runId,
            'sequence' => 1,
            'event_type' => HistoryEventType::WorkflowStarted->value,
            'payload' => [
                'workflow_class' => 'External\\CounterWorkflow',
                'workflow_type' => 'external.counter',
                'workflow_instance_id' => $instanceId,
                'workflow_run_id' => $runId,
                'declared_queries' => ['current'],
                'declared_query_contracts' => [
                    [
                        'name' => 'current',
                        'parameters' => [],
                    ],
                ],
                'declared_signals' => ['increment'],
                'declared_signal_contracts' => [
                    [
                        'name' => 'increment',
                        'parameters' => [
                            [
                                'name' => 'amount',
                                'position' => 0,
                                'required' => true,
                                'variadic' => false,
                                'default_available' => false,
                                'default' => null,
                                'type' => 'int',
                                'allows_null' => false,
                            ],
                        ],
                    ],
                ],
                'declared_updates' => [],
                'declared_update_contracts' => [],
                'declared_entry_method' => 'handle',
                'declared_entry_mode' => 'canonical',
                'declared_entry_declaring_class' => 'External\\CounterWorkflow',
            ],
            'recorded_at' => $startedAt,
        ]);

        foreach ([3, 7] as $index => $amount) {
            WorkflowSignal::query()->create([
                'id' => (string) Str::ulid(),
                'workflow_command_id' => (string) Str::ulid(),
                'workflow_instance_id' => $instanceId,
                'workflow_run_id' => $runId,
                'target_scope' => 'instance',
                'signal_name' => 'increment',
                'status' => 'received',
                'outcome' => 'signal_received',
                'command_sequence' => $index + 1,
                'workflow_sequence' => $index + 2,
                'payload_codec' => config('workflows.serializer'),
                'arguments' => Serializer::serialize([$amount]),
                'received_at' => $startedAt->copy()->addSeconds($index + 1),
                'applied_at' => $startedAt->copy()->addSeconds($index + 2),
            ]);
        }

        return [$instance, $run];
    }

    /**
     * @return array{WorkflowInstance, WorkflowRun}
     */
    private function createCounterRunWithoutDurableCommandContract(): array
    {
        $instanceId = 'waterline-external-counter-'.strtolower((string) Str::ulid());
        $runId = (string) Str::ulid();
        $startedAt = now()->subMinute();
        $workflowClass = 'External\\CounterWorkflow';

        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
            'workflow_class' => $workflowClass,
            'workflow_type' => 'external.counter',
            'namespace' => 'default',
            'current_run_id' => $runId,
            'run_count' => 1,
            'reserved_at' => $startedAt,
            'started_at' => $startedAt,
        ]);

        $run = WorkflowRun::query()->create([
            'id' => $runId,
            'workflow_instance_id' => $instanceId,
            'run_number' => 1,
            'workflow_class' => $workflowClass,
            'workflow_type' => 'external.counter',
            'namespace' => 'default',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'last_history_sequence' => 1,
            'last_command_sequence' => 2,
            'started_at' => $startedAt,
            'last_progress_at' => now()->subSeconds(10),
        ]);

        WorkflowRunSummary::query()->create([
            'id' => $runId,
            'workflow_instance_id' => $instanceId,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => $workflowClass,
            'workflow_type' => 'external.counter',
            'namespace' => 'default',
            'status' => 'waiting',
            'status_bucket' => 'running',
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => $startedAt,
            'sort_timestamp' => now()->subSeconds(10),
        ]);

        WorkflowHistoryEvent::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $runId,
            'sequence' => 1,
            'event_type' => HistoryEventType::WorkflowStarted->value,
            'payload' => [
                'workflow_class' => $workflowClass,
                'workflow_type' => 'external.counter',
                'workflow_instance_id' => $instanceId,
                'workflow_run_id' => $runId,
            ],
            'recorded_at' => $startedAt,
        ]);

        foreach ([3, 7] as $index => $amount) {
            WorkflowSignal::query()->create([
                'id' => (string) Str::ulid(),
                'workflow_command_id' => (string) Str::ulid(),
                'workflow_instance_id' => $instanceId,
                'workflow_run_id' => $runId,
                'target_scope' => 'instance',
                'signal_name' => 'increment',
                'status' => 'received',
                'outcome' => 'signal_received',
                'command_sequence' => $index + 1,
                'workflow_sequence' => $index + 2,
                'payload_codec' => config('workflows.serializer'),
                'arguments' => Serializer::serialize([$amount]),
                'received_at' => $startedAt->copy()->addSeconds($index + 1),
                'applied_at' => $startedAt->copy()->addSeconds($index + 2),
            ]);
        }

        return [$instance, $run];
    }

    /**
     * @return array<string, mixed>
     */
    private function externalCounterCommandContract(): array
    {
        $intParameter = [
            'name' => 'amount',
            'position' => 0,
            'required' => true,
            'variadic' => false,
            'default_available' => false,
            'default' => null,
            'type' => 'int',
            'allows_null' => false,
        ];
        $minimumParameter = $intParameter;
        $minimumParameter['name'] = 'minimum';

        return [
            'queries' => ['count-at-least', 'current', 'state'],
            'query_contracts' => [
                [
                    'name' => 'current',
                    'parameters' => [],
                ],
                [
                    'name' => 'state',
                    'parameters' => [],
                ],
                [
                    'name' => 'count-at-least',
                    'parameters' => [$minimumParameter],
                ],
            ],
            'signals' => ['increment'],
            'signal_contracts' => [
                [
                    'name' => 'increment',
                    'parameters' => [$intParameter],
                ],
            ],
            'updates' => [],
            'update_contracts' => [],
        ];
    }

    private function createWorkerRegistrationsTable(): void
    {
        if (Schema::hasTable('workflow_worker_registrations')) {
            if (! Schema::hasColumn('workflow_worker_registrations', 'workflow_command_contracts')) {
                Schema::table('workflow_worker_registrations', static function (Blueprint $table): void {
                    $table->json('workflow_command_contracts')->nullable();
                });
            }

            return;
        }

        Schema::create('workflow_worker_registrations', static function (Blueprint $table): void {
            $table->id();
            $table->string('worker_id', 255);
            $table->string('namespace', 128);
            $table->string('task_queue', 255);
            $table->string('runtime', 32);
            $table->string('sdk_version', 64)->nullable();
            $table->string('build_id', 255)->nullable();
            $table->json('supported_workflow_types')->nullable();
            $table->json('workflow_definition_fingerprints')->nullable();
            $table->json('workflow_command_contracts')->nullable();
            $table->json('supported_activity_types')->nullable();
            $table->unsignedInteger('max_concurrent_workflow_tasks')->default(100);
            $table->unsignedInteger('max_concurrent_activity_tasks')->default(100);
            $table->unsignedInteger('max_concurrent_worker_sessions')->nullable();
            $table->unsignedInteger('available_workflow_slots')->nullable();
            $table->unsignedInteger('available_activity_slots')->nullable();
            $table->unsignedInteger('available_session_slots')->nullable();
            $table->json('process_metrics')->nullable();
            $table->unsignedInteger('heartbeat_interval_seconds')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['worker_id', 'namespace']);
            $table->index(['namespace', 'task_queue', 'status']);
        });
    }
}
