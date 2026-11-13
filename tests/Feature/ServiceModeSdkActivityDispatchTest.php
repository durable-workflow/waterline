<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use DurableWorkflow\Client;
use DurableWorkflow\Transport\Transport;
use Orchestra\Testbench\TestCase;
use Waterline\Support\Remote\RemoteBackend;
use Waterline\Waterline;
use Waterline\WaterlineServiceProvider;

/**
 * The pinned PHP SDK (3.16.0) exposes the activity dispatch pause calls, so
 * with a Server that advertises the capability Waterline offers Pause and
 * Resume through the real SDK client, end to end.
 */
final class ServiceModeSdkActivityDispatchTest extends TestCase
{
    private const PAUSED = [
        'state' => 'paused', 'paused' => true, 'reason' => 'payments vendor outage',
        'paused_at' => '2026-11-12T10:00:00.123456Z',
        'paused_by' => ['type' => 'auth:token', 'id' => 'role:operator', 'label' => 'Operator'],
        'resumed_at' => null, 'resumed_by' => null,
    ];

    protected function getPackageProviders($app): array
    {
        return [WaterlineServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI=');
        $app['config']->set('waterline.backend', 'service');
        $app['config']->set('waterline.service.endpoint', 'https://runtime.test');
        $app['config']->set('waterline.service.token', 'secret');
        $app['config']->set('waterline.service.namespace', 'orders');
        $app['config']->set('waterline.service.access_mode', 'operator');
        $app['config']->set('waterline.middleware', []);
        $app['config']->set('waterline.api_middleware', []);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Waterline::auth(static fn (): bool => true);
    }

    public function testThePinnedSdkExposesThePauseCalls(): void
    {
        foreach (RemoteBackend::ACTIVITY_DISPATCH_METHODS as $method) {
            $this->assertTrue(method_exists(Client::class, $method), $method);
        }
    }

    public function testThePauseControlsAreOfferedAndWorkThroughTheRealSdkClient(): void
    {
        $transport = $this->serve(capable: true);

        $this->getJson('/waterline/api/v2/health')
            ->assertOk()
            ->assertJsonPath('queue_visibility.activity_dispatch_control', ['available' => true, 'reason' => null])
            ->assertJsonPath('queue_visibility.task_queues.0.activity_dispatch.state', 'paused');

        $this->postJson('/waterline/api/v2/task-queues/payments/activity-dispatch/resume')
            ->assertOk()
            ->assertJsonPath('outcome', 'resumed')
            ->assertJsonPath('task_queue', 'payments');

        $this->postJson('/waterline/api/v2/task-queues/payments/activity-dispatch/pause', ['reason' => 'vendor outage'])
            ->assertOk()
            ->assertJsonPath('outcome', 'paused')
            ->assertJsonPath('activity_dispatch.paused_by.label', 'Operator');

        $writes = array_values(array_filter($transport->calls, static fn (array $call): bool => $call[0] === 'POST'));
        $this->assertSame([
            ['POST', '/api/task-queues/payments/activity-dispatch/resume', null],
            ['POST', '/api/task-queues/payments/activity-dispatch/pause', ['reason' => 'vendor outage']],
        ], array_map(static fn (array $call): array => [$call[0], $call[1], $call[3] === [] ? null : $call[3]], $writes));
        foreach ($writes as $call) {
            $this->assertSame('orders', $call[2]['X-Namespace'] ?? null);
        }
    }

    public function testAServerWithoutTheCapabilityStillGetsNoControls(): void
    {
        $transport = $this->serve(capable: false);

        $this->getJson('/waterline/api/v2/health')
            ->assertOk()
            ->assertJsonPath('queue_visibility.activity_dispatch_control.reason', 'activity_dispatch_server_unavailable');
        $this->postJson('/waterline/api/v2/task-queues/payments/activity-dispatch/pause')->assertStatus(501);

        $this->assertSame([], array_filter($transport->calls, static fn (array $call): bool => $call[0] === 'POST'));
    }

    private function serve(bool $capable): object
    {
        $transport = new class(self::PAUSED, $capable) implements Transport {
            /** @var list<array{0: string, 1: string, 2: array<string, string>, 3: array<string, mixed>|null}> */
            public array $calls = [];

            public function __construct(private array $paused, private bool $capable)
            {
            }

            public function send(string $method, string $uri, array $headers, ?array $body = null): ?array
            {
                $path = (string) parse_url($uri, PHP_URL_PATH);
                $this->calls[] = [$method, $path, $headers, $body];
                $resumed = ['state' => 'active', 'paused' => false] + $this->paused;

                return match (true) {
                    $method === 'GET' && $path === '/api/cluster/info' => [
                        'server_id' => 'native', 'version' => '3.8.0',
                        'capabilities' => $this->capable ? ['task_queue_activity_dispatch_pause' => true] : [],
                    ],
                    $method === 'GET' && $path === '/api/workers' => ['workers' => []],
                    $method === 'GET' && $path === '/api/task-queues' => ['namespace' => 'orders', 'task_queues' => [
                        ['name' => 'payments', 'stats' => [], 'activity_dispatch' => $this->paused],
                    ]],
                    $method === 'POST' && $path === '/api/task-queues/payments/activity-dispatch/pause' => [
                        'namespace' => 'orders', 'task_queue' => 'payments', 'outcome' => 'paused',
                        'activity_dispatch' => $this->paused,
                    ],
                    $method === 'POST' && $path === '/api/task-queues/payments/activity-dispatch/resume' => [
                        'namespace' => 'orders', 'task_queue' => 'payments', 'outcome' => 'resumed',
                        'activity_dispatch' => $resumed,
                    ],
                    default => ['status' => 'healthy', 'checks' => []],
                };
            }
        };

        $this->app->instance(RemoteBackend::class, new RemoteBackend(new Client(
            'https://runtime.test',
            namespace: 'orders',
            transport: $transport,
            controlToken: 'secret',
        )));

        return $transport;
    }
}
