<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use DurableWorkflow\Codec\AvroPayloadCodec;
use DurableWorkflow\Exception\ServerException;
use DurableWorkflow\Model\ClusterInfo;
use Orchestra\Testbench\TestCase;
use Waterline\Support\Remote\RemoteBackend;
use Waterline\Waterline;
use Waterline\WaterlineServiceProvider;

final class RemoteActivityResultsControllerTest extends TestCase
{
    private ?object $fixtureClient = null;

    protected function getPackageProviders($app): array
    {
        return [WaterlineServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI=');
        $app['config']->set('waterline.backend', 'service');
        $app['config']->set('waterline.service.endpoint', 'https://runtime.test');
        $app['config']->set('waterline.service.namespace', 'orders');
        $app['config']->set('waterline.service.access_mode', 'read_only');
        $app['config']->set('waterline.middleware', []);
        $app['config']->set('waterline.api_middleware', []);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Waterline::auth(static fn (): bool => true);
    }

    public function testItLoadsOneExactResultAndPreservesNullAndLargeIntegers(): void
    {
        foreach ([null, [9007199254740993, null, false, '雪']] as $value) {
            $client = $this->client(['state' => 'available', 'available' => true,
                'result_envelope' => (new AvroPayloadCodec())->envelope($value)]);
            $response = $this->getJson($this->path())->assertOk()->assertJsonPath('state', 'available')
                ->assertJsonPath('available', true)->assertJsonPath('operator_scope.namespace', 'orders');
            if ($value === null) {
                $response->assertJsonPath('result', null);
            } else {
                $response->assertJsonPath('result.0.$type', 'long')->assertJsonPath('result.0.value', '9007199254740993')
                    ->assertJsonPath('result.1', null)->assertJsonPath('result.2', false)->assertJsonPath('result.3', '雪');
            }
            self::assertSame(['discovery', ['order-1', 'run-1', 'activity-1']], $client->calls);
            $response->assertJsonMissingPath('result_envelope');
        }
    }

    public function testItPreservesDistinctStatesWithoutDecodingOrReturningMutableResults(): void
    {
        foreach (['pending', 'failed', 'cancelled', 'pruned', 'unsupported', 'unavailable'] as $state) {
            $this->client(['state' => $state, 'available' => false, 'reason' => 'fixture_'.$state,
                'result' => 'mutable-wrong-value']);
            $this->getJson($this->path())->assertOk()->assertJsonPath('state', $state)
                ->assertJsonPath('available', false)->assertJsonPath('reason', 'fixture_'.$state)
                ->assertJsonMissingPath('result');
        }
    }

    public function testItRefusesWrongRunOrActivityIdentity(): void
    {
        foreach (['workflow_id', 'run_id', 'activity_id'] as $key) {
            $this->client([$key => 'another', 'state' => 'available', 'available' => true,
                'result_envelope' => (new AvroPayloadCodec())->envelope('wrong-result')]);
            $this->getJson($this->path())->assertStatus(502)->assertJsonPath('reason', 'activity_result_identity_mismatch')
                ->assertJsonMissingPath('result');
        }
    }

    public function testLegacyDiscoveryDoesNotAttemptTheResultRoute(): void
    {
        $client = $this->client([], false);
        $this->getJson($this->path())->assertOk()->assertJsonPath('state', 'unsupported')
            ->assertJsonPath('reason', 'activity_result_server_unavailable');
        self::assertSame(['discovery'], $client->calls);
    }

    public function testMissingSdkMethodIsExplicitlyUnsupported(): void
    {
        $this->app->instance(RemoteBackend::class, new RemoteBackend(new \stdClass));
        $this->getJson($this->path())->assertOk()->assertJsonPath('state', 'unsupported')
            ->assertJsonPath('reason', 'activity_result_sdk_unavailable')->assertJsonMissingPath('result');
    }

    public function testOriginalAuthorizationAndMissingActivityRefusalsArePreserved(): void
    {
        foreach ([403 => 'forbidden', 404 => 'activity_not_found'] as $status => $reason) {
            $client = $this->client([]);
            $client->failure = new ServerException('Original refusal', $status, $reason);
            $this->getJson($this->path())->assertStatus($status)->assertJsonPath('reason', $reason)
                ->assertJsonMissingPath('result');
            self::assertCount(2, $client->calls);
        }
    }

    public function testInvalidEnvelopeIsNotDisplayedAsNull(): void
    {
        $this->client(['state' => 'available', 'available' => true, 'result_envelope' => ['codec' => 'avro', 'blob' => 'not-avro']]);
        $this->getJson($this->path())->assertStatus(422)->assertJsonPath('reason', 'activity_result_decode_failed')
            ->assertJsonPath('available', false)->assertJsonMissingPath('result');
    }

    public function testWaterlineAuthorizationRunsBeforeBackendReads(): void
    {
        $client = $this->client([]);
        Waterline::auth(static fn (): bool => false);
        $this->getJson($this->path())->assertForbidden();
        self::assertSame([], $client->calls);
    }

    /** @param array<string, mixed> $response */
    private function client(array $response, bool $capable = true): object
    {
        if ($this->fixtureClient !== null) {
            $this->fixtureClient->response = $response;
            $this->fixtureClient->capable = $capable;
            $this->fixtureClient->calls = [];
            $this->fixtureClient->failure = null;
            return $this->fixtureClient;
        }
        $client = new class($response, $capable) {
            public array $calls = [];
            public ?ServerException $failure = null;

            public function __construct(public array $response, public bool $capable) {}

            public function clusterInfo(): ClusterInfo
            {
                $this->calls[] = 'discovery';
                return ClusterInfo::fromArray(['capabilities' => ['workflow_activity_result_inspection' => $this->capable]]);
            }

            public function workflowActivityResult(string $workflowId, string $runId, string $activityId): array
            {
                $this->calls[] = [$workflowId, $runId, $activityId];
                if ($this->failure !== null) throw $this->failure;
                return $this->response + ['workflow_id' => $workflowId, 'run_id' => $runId, 'activity_id' => $activityId];
            }

            public function payloadCodec(): AvroPayloadCodec
            {
                return new AvroPayloadCodec();
            }
        };
        $this->fixtureClient = $client;
        $this->app->instance(RemoteBackend::class, new RemoteBackend($client));
        return $client;
    }

    private function path(): string
    {
        return '/waterline/api/instances/order-1/runs/run-1/activities/activity-1/result';
    }
}
