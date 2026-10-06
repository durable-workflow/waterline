<?php

namespace Waterline\Tests\Feature;

use Waterline\Tests\Fixtures\V2\TestCommandContractWorkflow;
use Waterline\Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;

final class RunApplicationContextTest extends TestCase
{
    public function testEmbeddedDetailUsesConfiguredMetadataAndLeavesAnUnconfiguredRunAccessible(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.observability.workflow_types', [
            'workflow.command-contract' => [
                'classification' => 'business_operation',
                'fields' => ['order' => ['source' => 'visibility_labels', 'key' => 'order']],
                'links' => ['order' => ['url' => 'https://app.example/orders/{order}']],
            ],
        ]);
        $instance = WorkflowInstance::create([
            'id' => 'context-instance', 'workflow_class' => TestCommandContractWorkflow::class,
            'workflow_type' => 'workflow.command-contract', 'run_count' => 1,
        ]);
        $run = WorkflowRun::create([
            'id' => 'context-run', 'workflow_instance_id' => $instance->id, 'run_number' => 1,
            'workflow_class' => TestCommandContractWorkflow::class, 'workflow_type' => 'workflow.command-contract',
            'status' => 'waiting', 'started_at' => now(), 'last_progress_at' => now(),
            'arguments' => Serializer::serialize(['private']),
            'visibility_labels' => ['order' => '42/receipt', 'secret' => 'private'],
        ]);
        $instance->update(['current_run_id' => $run->id]);

        $response = $this->getJson('/waterline/api/flows/'.$run->id)
            ->assertOk()
            ->assertJsonPath('workflow_classification', 'business_operation')
            ->assertJsonPath('application_context.fields.0.value', '42/receipt')
            ->assertJsonPath('application_context.links.0.url', 'https://app.example/orders/42%2Freceipt')
            ->assertJsonCount(1, 'application_context.fields');
        self::assertStringNotContainsString('private', json_encode($response->json('application_context')));

        config()->set('waterline.observability.workflow_types', []);
        $this->getJson('/waterline/api/flows/'.$run->id)
            ->assertOk()
            ->assertJsonPath('workflow_classification', null)
            ->assertJsonPath('application_context.state', 'not_configured')
            ->assertJsonCount(0, 'application_context.fields');
    }
}
