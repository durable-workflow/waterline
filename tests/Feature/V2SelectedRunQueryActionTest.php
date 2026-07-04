<?php

namespace Waterline\Tests\Feature;

use Illuminate\Support\Str;
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
}
