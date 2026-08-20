<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use Illuminate\Support\Str;
use Waterline\Tests\TestCase;
use Workflow\Serializers\AvroBinaryValue;
use Workflow\Serializers\AvroMapValue;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;

/**
 * Polyglot rendering contract: Waterline must render runs authored by the
 * PHP, Python, and Rust SDKs through the shared v2 Avro payload contract.
 *
 * The run-detail and list payloads delegate decoding to the workflow
 * package's RunDetailView / WorkflowRun helpers, which read the
 * `payload_codec` column on each row. This test pins that contract so a
 * regression that makes the dashboard author-specific — for example sniffing
 * blob shape instead of honoring the persisted Avro codec — fails loudly.
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

    public function testRunDetailRendersAvroBytesWithoutConflatingThemWithText(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $run = $this->createCompletedRun(
            namespace: 'default',
            workflowClass: 'durable_workflow.examples.bytes.BytesWorkflow',
            workflowType: 'bytes',
            arguments: [[
                'text' => 'AP8=',
                'bytes' => AvroBinaryValue::fromBytes("\x00\xFF"),
            ]],
            output: AvroBinaryValue::fromBytes('result'),
        );

        $this->getJson('/waterline/api/instances/'.$run->workflow_instance_id.'/runs/'.$run->id)
            ->assertOk()
            ->assertJsonPath('arguments.0.text', 'AP8=')
            ->assertJsonPath('arguments.0.bytes.$type', 'bytes')
            ->assertJsonPath('arguments.0.bytes.base64', 'AP8=')
            ->assertJsonPath('output.$type', 'bytes')
            ->assertJsonPath('output.base64', 'cmVzdWx0');
    }

    public function testRunDetailRendersAmbiguousAvroMapsWithoutConflatingThemWithLists(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $run = $this->createCompletedRun(
            namespace: 'default',
            workflowClass: 'durable_workflow.examples.maps.MapsWorkflow',
            workflowType: 'maps',
            arguments: [
                AvroMapValue::fromPairs([]),
                AvroMapValue::fromPairs([['0', 'zero'], ['1', ['nested']]]),
                ['zero', 'one'],
            ],
            output: AvroMapValue::fromPairs([['0', AvroBinaryValue::fromBytes("\x00\xFF")]]),
        );

        $this->getJson('/waterline/api/instances/'.$run->workflow_instance_id.'/runs/'.$run->id)
            ->assertOk()
            ->assertJsonPath('arguments.0.$type', 'map')
            ->assertJsonPath('arguments.0.entries', [])
            ->assertJsonPath('arguments.1.$type', 'map')
            ->assertJsonPath('arguments.1.entries.0.key', '0')
            ->assertJsonPath('arguments.1.entries.1.value.0', 'nested')
            ->assertJsonPath('arguments.2.0', 'zero')
            ->assertJsonPath('output.$type', 'map')
            ->assertJsonPath('output.entries.0.value.$type', 'bytes')
            ->assertJsonPath('output.entries.0.value.base64', 'AP8=');
    }

    public function testRunDetailRendersAvroPolyglotRunsWithIdenticalShape(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $pythonRun = $this->createCompletedRun(
            namespace: 'default',
            workflowClass: 'durable_workflow.examples.greeter.GreeterWorkflow',
            workflowType: 'polyglot.shared',
            arguments: [['caller' => 'python']],
            output: ['ok' => true],
        );

        $phpRun = $this->createCompletedRun(
            namespace: 'default',
            workflowClass: 'App\\Workflows\\PolyglotSharedWorkflow',
            workflowType: 'polyglot.shared',
            arguments: [['caller' => 'php']],
            output: ['ok' => true],
        );

        $rustRun = $this->createCompletedRun(
            namespace: 'default',
            workflowClass: 'durable_workflow_examples::greeter::GreeterWorkflow',
            workflowType: 'polyglot.shared',
            arguments: [['caller' => 'rust']],
            output: ['ok' => true],
        );

        foreach ([$phpRun, $pythonRun, $rustRun] as $run) {
            $this->assertSame('avro', $run->payload_codec);
            $this->assertSame('avro', $run->output_payload_codec);
        }

        $pythonShape = array_keys($this->getJson(
            '/waterline/api/instances/'.$pythonRun->workflow_instance_id.'/runs/'.$pythonRun->id,
        )->assertOk()->json());

        $phpShape = array_keys($this->getJson(
            '/waterline/api/instances/'.$phpRun->workflow_instance_id.'/runs/'.$phpRun->id,
        )->assertOk()->json());

        $rustShape = array_keys($this->getJson(
            '/waterline/api/instances/'.$rustRun->workflow_instance_id.'/runs/'.$rustRun->id,
        )->assertOk()->json());

        sort($pythonShape);
        sort($phpShape);
        sort($rustShape);

        $this->assertSame(
            $phpShape,
            $pythonShape,
            'Run-detail shape must not depend on the SDK that authored the run.',
        );
        $this->assertSame(
            $phpShape,
            $rustShape,
            'Run-detail shape must not depend on the SDK that authored the run.',
        );
    }

    public function testListRoutesReturnAvroPolyglotRunsAcrossAuthors(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $pythonRun = $this->createCompletedRun(
            namespace: 'default',
            workflowClass: 'durable_workflow.examples.order.OrderWorkflow',
            workflowType: 'polyglot.list',
            arguments: [['order_id' => 'py-1']],
            output: ['shipped' => true],
        );

        $phpRun = $this->createCompletedRun(
            namespace: 'default',
            workflowClass: 'App\\Workflows\\OrderWorkflow',
            workflowType: 'polyglot.list',
            arguments: [['order_id' => 'php-1']],
            output: ['shipped' => true],
        );

        $rustRun = $this->createCompletedRun(
            namespace: 'default',
            workflowClass: 'durable_workflow_examples::order::OrderWorkflow',
            workflowType: 'polyglot.list',
            arguments: [['order_id' => 'rust-1']],
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
            'PHP-authored Avro runs must appear in the completed list payload.',
        );
        $this->assertContains(
            $rustRun->id,
            $ids,
            'Rust-authored Avro runs must appear in the completed list payload.',
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
            arguments: [['caller' => 'tenant-a']],
            output: ['ok' => true],
        );

        $tenantBRun = $this->createCompletedRun(
            namespace: 'tenant-b',
            workflowClass: 'durable_workflow.examples.greeter.GreeterWorkflow',
            workflowType: 'polyglot.tenancy',
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
                'A namespace-pinned dashboard must never leak runs from another tenant, regardless of authoring SDK.',
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
            'payload_codec' => 'avro',
            'output_payload_codec' => 'avro',
            'arguments' => Serializer::serializeWithCodec('avro', $arguments),
            'output' => Serializer::serializeWithCodec('avro', $output),
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
