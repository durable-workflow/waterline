<?php

namespace Waterline\Tests\Feature;

use Mockery\MockInterface;
use Waterline\Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\OperatorObservabilityRepository;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;

class V2PrincipalVisibilityTest extends TestCase
{
    public function testSelectedRunReconcilesProjectedPrincipalsWithDurableCommandHistory(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'billing');

        $run = $this->createRun('waterline-principal-billing', 'billing');
        $authenticated = $this->recordArchiveCommand($run, [
            'caller' => [
                'type' => 'waterline',
                'label' => 'Waterline UI',
            ],
            'auth' => [
                'status' => 'authorized',
                'method' => 'waterline',
            ],
            'principal' => [
                'type' => 'user',
                'id' => 'billing-user:42',
                'label' => 'Taylor Operator',
            ],
            'request' => [
                'method' => 'POST',
                'path' => '/waterline/api/instances/'.$run->workflow_instance_id.'/archive',
                'route_name' => 'waterline.instances.archive',
            ],
        ]);
        $anonymous = $this->recordArchiveCommand($run, [
            'caller' => [
                'type' => 'waterline',
                'label' => 'Waterline UI',
            ],
            'auth' => [
                'status' => 'not_configured',
                'method' => 'none',
            ],
        ]);
        $shippingRun = $this->createRun('waterline-principal-shipping', 'shipping');

        $this->mock(OperatorObservabilityRepository::class, function (MockInterface $mock) use (
            $run,
            $authenticated,
            $anonymous,
        ): void {
            $projectedCommand = static fn (WorkflowCommand $command): array => [
                'id' => $command->id,
                'sequence' => $command->command_sequence,
                'type' => 'archive',
                'source' => 'waterline',
                'principal_type' => 'spoofed',
                'principal_id' => 'attacker:999',
                'principal_label' => 'Injected Principal',
                'context' => [
                    'principal' => [
                        'type' => 'spoofed',
                        'id' => 'attacker:999',
                        'label' => 'Injected Principal',
                    ],
                ],
            ];
            $projectedTimeline = static fn (WorkflowCommand $command): array => [
                'id' => $command->historyEvents()->orderBy('sequence')->value('id'),
                'sequence' => $command->historyEvents()->orderBy('sequence')->value('sequence'),
                'type' => 'ArchiveRequested',
                'kind' => 'command',
                'command_id' => $command->id,
                'command' => $projectedCommand($command),
            ];

            $mock->shouldReceive('runDetail')
                ->once()
                ->andReturn([
                    'id' => $run->id,
                    'instance_id' => $run->workflow_instance_id,
                    'run_id' => $run->id,
                    'status' => 'completed',
                    'status_bucket' => 'completed',
                    'commands' => [
                        $projectedCommand($authenticated),
                        $projectedCommand($anonymous),
                    ],
                    'timeline' => [
                        $projectedTimeline($anonymous),
                    ],
                ]);
        });

        $response = $this->getJson(
            '/waterline/api/instances/'.$run->workflow_instance_id.'/runs/'.$run->id,
        )->assertOk();

        $commands = collect($response->json('commands'))->keyBy('id');
        $authenticatedDetail = $commands->get($authenticated->id);
        $anonymousDetail = $commands->get($anonymous->id);

        $this->assertSame('user', $authenticatedDetail['principal_type']);
        $this->assertSame('billing-user:42', $authenticatedDetail['principal_id']);
        $this->assertSame('Taylor Operator', $authenticatedDetail['principal_label']);
        $this->assertSame('billing-user:42', $authenticatedDetail['context']['principal']['id']);
        $this->assertSame('authorized', $authenticatedDetail['auth_status']);
        $this->assertSame('waterline', $authenticatedDetail['auth_method']);
        $this->assertSame('waterline.instances.archive', $authenticatedDetail['request_route_name']);

        $this->assertArrayNotHasKey('principal_type', $anonymousDetail);
        $this->assertArrayNotHasKey('principal_id', $anonymousDetail);
        $this->assertArrayNotHasKey('principal_label', $anonymousDetail);
        $this->assertArrayNotHasKey('principal', $anonymousDetail['context'] ?? []);
        $this->assertSame('not_configured', $anonymousDetail['auth_status']);
        $this->assertSame('none', $anonymousDetail['auth_method']);

        $timeline = collect($response->json('timeline'))->keyBy(
            static fn (array $event): ?string => $event['command']['id'] ?? null,
        );
        $this->assertSame('billing-user:42', $timeline->get($authenticated->id)['command']['principal_id']);
        $this->assertSame(
            'billing-user:42',
            $timeline->get($authenticated->id)['command']['context']['principal']['id'],
        );
        $this->assertSame('authorized', $timeline->get($authenticated->id)['command']['auth_status']);
        $this->assertSame('waterline', $timeline->get($authenticated->id)['command']['auth_method']);
        $this->assertSame(
            'waterline.instances.archive',
            $timeline->get($authenticated->id)['command']['request_route_name'],
        );
        $this->assertArrayNotHasKey('principal_id', $timeline->get($anonymous->id)['command']);
        $this->assertStringNotContainsString(
            'attacker:999',
            json_encode($response->json(), JSON_THROW_ON_ERROR),
        );

        $this->getJson(
            '/waterline/api/instances/'.$shippingRun->workflow_instance_id.'/runs/'.$shippingRun->id,
        )->assertNotFound();
    }

    private function createRun(string $instanceId, string $namespace): WorkflowRun
    {
        $startedAt = now()->subMinute();
        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.principal-visibility',
            'namespace' => $namespace,
            'run_count' => 1,
            'started_at' => $startedAt,
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.principal-visibility',
            'namespace' => $namespace,
            'status' => 'completed',
            'closed_reason' => 'completed',
            'payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'last_history_sequence' => 0,
            'started_at' => $startedAt,
            'closed_at' => now(),
            'last_progress_at' => now(),
        ]);
        $instance->update(['current_run_id' => $run->id]);

        WorkflowRunSummary::query()->create([
            'id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => $run->workflow_class,
            'workflow_type' => $run->workflow_type,
            'namespace' => $namespace,
            'status' => 'completed',
            'status_bucket' => 'completed',
            'closed_reason' => 'completed',
            'started_at' => $run->started_at,
            'closed_at' => $run->closed_at,
            'sort_timestamp' => $run->closed_at,
        ]);

        return $run;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function recordArchiveCommand(WorkflowRun $run, array $context): WorkflowCommand
    {
        $command = WorkflowCommand::record($run->instance, $run, [
            'command_type' => 'archive',
            'target_scope' => 'instance',
            'source' => 'waterline',
            'status' => 'accepted',
            'outcome' => 'archived',
            'context' => $context,
            'payload_codec' => config('workflows.serializer'),
            'payload' => Serializer::serialize(['reason' => 'principal visibility']),
            'accepted_at' => now(),
            'applied_at' => now(),
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::ArchiveRequested, [
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'command_type' => 'archive',
            'outcome' => 'archived',
            'reason' => 'principal visibility',
        ], null, $command);

        return $command;
    }
}
