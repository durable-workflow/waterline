<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use Illuminate\Support\Str;
use Waterline\Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;

/**
 * Polyglot rendering contract: Waterline must render a run authored by any
 * SDK (PHP, Python, ...) using whichever payload codec the engine stored.
 *
 * The run-detail and list payloads delegate decoding to the workflow
 * package's RunDetailView / WorkflowRun helpers, which read the
 * `payload_codec` column on each row. This test pins that contract so a
 * regression that makes the dashboard PHP-only — for example sniffing
 * blob shape instead of honoring the persisted codec — fails loudly.
 */
class V2PolyglotRunRenderingTest extends TestCase
{
    public function testRunDetailDecodesAvroAuthoredArgumentsAndOutput(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $arguments = [['name' => 'world']];
        $output = ['greeting' => 'hello, world', 'length' => 12];

        $run = $this->createCompletedRun(
            namespace: 'default',
            workflowClass: 'durable_workflow.examples.greeter.GreeterWorkflow',
            workflowType: 'greeter',
            payloadCodec: 'avro',
            arguments: $arguments,
            output: $output,
        );

        $this->getJson('/waterline/api/instances/'.$run->workflow_instance_id.'/runs/'.$run->id)
            ->assertOk()
            ->assertJsonPath('run_id', $run->id)
            ->assertJsonPath('class', 'durable_workflow.examples.greeter.GreeterWorkflow')
            ->assertJsonPath('workflow_type', 'greeter')
            ->assertJsonPath('arguments', $arguments)
            ->assertJsonPath('output', $output);
    }

    public function testRunDetailDecodesLegacyPhpSerializerAuthoredArgumentsAndOutput(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $arguments = [['actor' => 'Taylor']];
        $output = ['approved' => true];

        $run = $this->createCompletedRun(
            namespace: 'default',
            workflowClass: 'App\\Workflows\\ApprovalWorkflow',
            workflowType: 'approval',
            payloadCodec: 'workflow-serializer-y',
            arguments: $arguments,
            output: $output,
        );

        $this->getJson('/waterline/api/instances/'.$run->workflow_instance_id.'/runs/'.$run->id)
            ->assertOk()
            ->assertJsonPath('run_id', $run->id)
            ->assertJsonPath('class', 'App\\Workflows\\ApprovalWorkflow')
            ->assertJsonPath('workflow_type', 'approval')
            ->assertJsonPath('arguments', $arguments)
            ->assertJsonPath('output', $output);
    }

    public function testRunDetailRendersAvroAndLegacyAuthoredRunsWithIdenticalShape(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $pythonRun = $this->createCompletedRun(
            namespace: 'default',
            workflowClass: 'durable_workflow.examples.greeter.GreeterWorkflow',
            workflowType: 'polyglot.shared',
            payloadCodec: 'avro',
            arguments: [['caller' => 'python']],
            output: ['ok' => true],
        );

        $phpRun = $this->createCompletedRun(
            namespace: 'default',
            workflowClass: 'App\\Workflows\\PolyglotSharedWorkflow',
            workflowType: 'polyglot.shared',
            payloadCodec: 'workflow-serializer-y',
            arguments: [['caller' => 'php']],
            output: ['ok' => true],
        );

        $pythonShape = array_keys($this->getJson(
            '/waterline/api/instances/'.$pythonRun->workflow_instance_id.'/runs/'.$pythonRun->id,
        )->assertOk()->json());

        $phpShape = array_keys($this->getJson(
            '/waterline/api/instances/'.$phpRun->workflow_instance_id.'/runs/'.$phpRun->id,
        )->assertOk()->json());

        sort($pythonShape);
        sort($phpShape);

        $this->assertSame(
            $phpShape,
            $pythonShape,
            'Run-detail shape must not depend on the SDK that authored the run.',
        );
    }

    public function testListRoutesReturnPolyglotRunsAcrossCodecs(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $pythonRun = $this->createCompletedRun(
            namespace: 'default',
            workflowClass: 'durable_workflow.examples.order.OrderWorkflow',
            workflowType: 'polyglot.list',
            payloadCodec: 'avro',
            arguments: [['order_id' => 'py-1']],
            output: ['shipped' => true],
        );

        $phpRun = $this->createCompletedRun(
            namespace: 'default',
            workflowClass: 'App\\Workflows\\OrderWorkflow',
            workflowType: 'polyglot.list',
            payloadCodec: 'workflow-serializer-y',
            arguments: [['order_id' => 'php-1']],
            output: ['shipped' => true],
        );

        $response = $this->getJson('/waterline/api/flows/completed')->assertOk();

        $ids = collect($response->json('data') ?? [])->pluck('id')->all();

        $this->assertContains(
            $pythonRun->id,
            $ids,
            'Avro-authored runs must appear in the completed list payload.',
        );
        $this->assertContains(
            $phpRun->id,
            $ids,
            'Legacy-codec PHP runs must appear in the completed list payload.',
        );
    }

    public function testPolyglotRunsRespectNamespaceTenancyIsolation(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'tenant-a');

        $tenantARun = $this->createCompletedRun(
            namespace: 'tenant-a',
            workflowClass: 'durable_workflow.examples.greeter.GreeterWorkflow',
            workflowType: 'polyglot.tenancy',
            payloadCodec: 'avro',
            arguments: [['caller' => 'tenant-a']],
            output: ['ok' => true],
        );

        $tenantBRun = $this->createCompletedRun(
            namespace: 'tenant-b',
            workflowClass: 'durable_workflow.examples.greeter.GreeterWorkflow',
            workflowType: 'polyglot.tenancy',
            payloadCodec: 'avro',
            arguments: [['caller' => 'tenant-b']],
            output: ['ok' => true],
        );

        $listed = $this->getJson('/waterline/api/flows/completed')
            ->assertOk()
            ->assertJsonPath('visibility_filters.applied.namespace', 'tenant-a')
            ->json('data');

        $this->assertNotEmpty($listed);

        foreach ($listed as $row) {
            $this->assertSame(
                'tenant-a',
                $row['namespace'] ?? null,
                'A namespace-pinned dashboard must never leak runs from another tenant, regardless of payload codec.',
            );
            $this->assertNotSame($tenantBRun->id, $row['id'] ?? null);
        }

        $this->getJson('/waterline/api/instances/'.$tenantARun->workflow_instance_id.'/runs/'.$tenantARun->id)
            ->assertOk();

        $this->getJson('/waterline/api/instances/'.$tenantBRun->workflow_instance_id.'/runs/'.$tenantBRun->id)
            ->assertNotFound();
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    private function createCompletedRun(
        string $namespace,
        string $workflowClass,
        string $workflowType,
        string $payloadCodec,
        array $arguments,
        mixed $output,
    ): WorkflowRun {
        $instanceId = 'polyglot-'.Str::lower(Str::random(8));

        $instance = WorkflowInstance::create([
            'id' => $instanceId,
            'workflow_class' => $workflowClass,
            'workflow_type' => $workflowType,
            'run_count' => 1,
            'namespace' => $namespace,
            'started_at' => now()->subMinutes(5),
        ]);

        $run = WorkflowRun::create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => $workflowClass,
            'workflow_type' => $workflowType,
            'status' => 'completed',
            'closed_reason' => 'completed',
            'namespace' => $namespace,
            'payload_codec' => $payloadCodec,
            'output_payload_codec' => $payloadCodec,
            'arguments' => Serializer::serializeWithCodec($payloadCodec, $arguments),
            'output' => Serializer::serializeWithCodec($payloadCodec, $output),
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
            'class' => $workflowClass,
            'workflow_type' => $workflowType,
            'status' => 'completed',
            'status_bucket' => 'completed',
            'closed_reason' => 'completed',
            'namespace' => $namespace,
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
            'payload' => [
                'workflow_type' => $workflowType,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'workflow_class' => $workflowClass,
            ],
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

        return $run;
    }
}
