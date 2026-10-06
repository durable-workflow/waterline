<?php

namespace Waterline\Tests\Feature;

use DurableWorkflow\Exception\ServerException;
use Waterline\Support\Remote\RemoteBackend;
use Waterline\Support\RunObservationHistoryToken;
use Waterline\Tests\Fixtures\FakeRemoteClient;
use Waterline\Tests\TestCase;
use Workflow\V2\Contracts\OperatorObservabilityRepository;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;

final class ServiceRunObservationTest extends TestCase
{
    private FakeRemoteClient $client;

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('waterline.backend', 'service');
        $app['config']->set('waterline.service.endpoint', 'https://server.example');
        $app['config']->set('waterline.service.namespace', 'orders');
        $app['config']->set('waterline.service.access_mode', 'read_only');
        $app['config']->set('waterline.middleware', []);
        $app['config']->set('waterline.api_middleware', []);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new FakeRemoteClient;
        $this->app->instance(RemoteBackend::class, new RemoteBackend($this->client));
    }

    public function testServiceUsesTheBoundedNativeContractWithoutFullRunOrChildReads(): void
    {
        $run = $this->createRun('run', 'order', 'completed');
        foreach (['failed', 'running'] as $index => $status) {
            $child = $this->createRun('child-'.$index, 'child-order-'.$index, $status);
            WorkflowLink::query()->create([
                'id' => 'link-'.$index, 'link_type' => 'child_workflow', 'sequence' => $index + 1,
                'parent_workflow_instance_id' => $run->workflow_instance_id, 'parent_workflow_run_id' => $run->id,
                'child_workflow_instance_id' => $child->workflow_instance_id, 'child_workflow_run_id' => $child->id,
            ]);
        }
        for ($sequence = 1; $sequence <= 3; $sequence++) {
            WorkflowHistoryEvent::query()->create([
                'id' => 'event-'.$sequence, 'workflow_run_id' => $run->id, 'sequence' => $sequence,
                'event_type' => 'SignalReceived', 'recorded_at' => now(),
                'payload' => ['arguments' => ['codec' => 'avro', 'external_payload' => ['opaque' => true]]],
            ]);
        }
        $this->client->observation = $this->observation($run, 2);
        $first = $this->getJson($this->path().'&history_limit=2')->assertOk()
            ->assertJsonPath('engine_source', 'service')->assertJsonPath('read_mode', 'bounded')
            ->assertJsonPath('operator_scope.namespace', 'orders')
            ->assertJsonPath('relationships.children.relationships.0.status', 'failed')
            ->assertJsonPath('relationships.children.relationships.1.status', 'running')
            ->assertJsonPath('status', 'completed')->assertJsonPath('timeline_returned_count', 2)
            ->assertJsonPath('timeline_truncated', true)->assertJsonPath('timeline_total_count', null)
            ->assertJsonPath('current_waits_state', 'available')
            ->assertJsonPath('detail_sections.activities', 'not_loaded')
            ->assertJsonPath('timeline.0.payload.arguments.external_payload.opaque', true);
        $this->assertNoFullReads();
        self::assertArrayNotHasKey('arguments', $first->json());
        self::assertArrayNotHasKey('cancellation_cascade', $first->json());
        $calls = $this->observationCalls();
        self::assertCount(1, $calls);
        self::assertSame(['workflowId' => 'order', 'runId' => 'run', 'searchAttributeKeys' => [],
            'historyPageSize' => 2, 'historyPageToken' => null], $calls[0]['arguments']);

        $token = $first->json('history_next_page_token');
        $this->client->observationPages[$token] = $this->observation($run, 2, $token);
        $this->getJson($this->path().'&history_page_token='.rawurlencode($token))->assertOk()
            ->assertJsonPath('history_window_from_start', false)->assertJsonPath('timeline.0.sequence', 3)
            ->assertJsonPath('history_start_page_token', $token)->assertJsonPath('history_next_page_token', null);
        self::assertSame($token, $this->observationCalls()[1]['arguments']['historyPageToken']);
        $this->assertNoFullReads();
    }

    public function testConfiguredContextIsReadFromTheObservedTypeAndPinnedRunOnly(): void
    {
        $run = $this->createRun('run', 'order');
        $this->client->observation = $this->observation($run);
        $this->client->observation['search_attributes'] = ['order' => '42/receipt', 'private' => 'secret'];
        $this->client->observation['visibility_labels'] = ['private' => 'hidden-label'];
        config()->set('waterline.observability.workflow_types', ['orders.process' => [
            'classification' => 'business_operation',
            'fields' => ['order' => ['source' => 'search_attributes', 'key' => 'order']],
            'links' => ['order' => ['url' => 'https://app.example/orders/{order}']],
        ]]);
        $response = $this->getJson($this->path())->assertOk()
            ->assertJsonPath('application_context.fields.0.value', '42/receipt')
            ->assertJsonPath('application_context.links.0.url', 'https://app.example/orders/42%2Freceipt');
        self::assertStringNotContainsString('secret', $response->getContent());
        self::assertStringNotContainsString('hidden-label', $response->getContent());
        $calls = $this->observationCalls();
        self::assertCount(2, $calls);
        self::assertSame('run', $calls[1]['arguments']['runId']);
        self::assertSame(['order'], $calls[1]['arguments']['searchAttributeKeys']);
        self::assertSame(1, $calls[1]['arguments']['historyPageSize']);
        $this->assertNoFullReads();
    }

    public function testPrunedServiceEvidenceRetainsUnknownTotals(): void
    {
        $run = $this->createRun('run', 'order', 'completed');
        $run->update(['details_pruned_at' => now()]);
        $this->client->observation = $this->observation($run);
        $this->getJson($this->path())->assertOk()
            ->assertJsonPath('history_state', 'pruned')->assertJsonPath('timeline_total_count', null)
            ->assertJsonPath('recent_failures_state', 'pruned')->assertJsonPath('recent_failures_total_count', null)
            ->assertJsonPath('history_audit', 'not_evaluated');
        $this->assertNoFullReads();
    }

    public function testMissingSdkCapabilityCannotTriggerCompleteReads(): void
    {
        $this->app->instance(RemoteBackend::class, new RemoteBackend(new \stdClass));
        $this->getJson($this->path())->assertStatus(501)
            ->assertJsonPath('required_sdk_method', 'workflowObservation');
        self::assertSame([], $this->client->calls);
    }

    public function testServerRefusalsCannotTriggerCompleteReads(): void
    {
        foreach ([404 => 'route_not_found', 503 => 'backend_unavailable'] as $status => $reason) {
            $this->client->failures['workflowObservation'] = new ServerException('Observation unavailable.', $status, $reason);
            $this->getJson($this->path())->assertStatus($status)->assertJsonPath('reason', $reason);
        }
        $this->assertNoFullReads();
        self::assertCount(2, $this->observationCalls());
        $this->client->calls = [];
        $this->getJson($this->path().'&history_limit=1001')->assertStatus(422);
        self::assertSame([], $this->client->calls);
    }

    private function observation(WorkflowRun $run, int $limit = 200, ?string $token = null): array
    {
        $observer = app(OperatorObservabilityRepository::class);
        $detail = $observer->runObservation($run);
        $cursor = RunObservationHistoryToken::decode($token, $run->id);
        $history = $observer->runHistoryPage($run, $limit, $cursor['after'] ?? 0, $cursor['through'] ?? null);
        $history['next_page_token'] = $history['has_more']
            ? RunObservationHistoryToken::encode($run->id, $history['next_sequence'], $history['through_sequence']) : null;

        return $detail + ['workflow_id' => $run->workflow_instance_id, 'history' => $history, 'search_attributes' => []];
    }

    private function createRun(string $id, string $instanceId, string $status = 'running'): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId, 'namespace' => 'orders', 'workflow_class' => 'orders.process',
            'workflow_type' => 'orders.process', 'run_count' => 1,
        ]);
        $run = WorkflowRun::query()->create([
            'id' => $id, 'workflow_instance_id' => $instanceId, 'namespace' => 'orders',
            'workflow_class' => 'orders.process', 'workflow_type' => 'orders.process', 'status' => $status,
            'run_number' => 1, 'connection' => 'database', 'queue' => 'orders',
        ]);
        $instance->update(['current_run_id' => $id]);

        return $run;
    }

    private function path(): string
    {
        return '/waterline/api/instances/order/runs/run?observation=bounded';
    }

    private function observationCalls(): array
    {
        return array_values(array_filter($this->client->calls, static fn ($call) => $call['method'] === 'workflowObservation'));
    }

    private function assertNoFullReads(): void
    {
        self::assertSame([], array_intersect(array_column($this->client->calls, 'method'), [
            'describeWorkflow', 'listWorkflowRuns', 'workflowHistory', 'workflowDiagnostics', 'workflowActivities', 'listWorkflowStreams',
        ]));
    }
}
