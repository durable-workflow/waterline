<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use DurableWorkflow\Exception\ServerException;
use DurableWorkflow\Model\ClusterInfo;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use Waterline\Support\Remote\RemoteBackend;
use Waterline\Tests\Fixtures\FakeRemoteClient;
use Waterline\Waterline;
use Waterline\WaterlineServiceProvider;

final class ServiceModeTaskQueueActivityDispatchTest extends TestCase
{
    private const PAUSED = [
        'state' => 'paused',
        'paused' => true,
        'reason' => 'payments vendor outage',
        'paused_at' => '2026-11-12T10:00:00.123456Z',
        'paused_by' => ['type' => 'auth:token', 'id' => 'role:operator', 'label' => 'Operator'],
        'resumed_at' => null,
        'resumed_by' => null,
    ];

    private const RESUMED = [
        'state' => 'active',
        'paused' => false,
        'reason' => 'payments vendor outage',
        'paused_at' => '2026-11-12T10:00:00.123456Z',
        'paused_by' => ['type' => 'auth:token', 'id' => 'role:operator', 'label' => 'Operator'],
        'resumed_at' => '2026-11-12T11:00:00.000000Z',
        'resumed_by' => ['type' => 'auth:token', 'id' => 'role:admin'],
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

    public function testHealthPassesTheReportedStateThroughAndOffersTheActions(): void
    {
        $client = $this->client();

        $this->getJson('/waterline/api/v2/health')
            ->assertOk()
            ->assertJsonPath('queue_visibility.task_queues.0.name', 'payments')
            ->assertJsonPath('queue_visibility.task_queues.0.activity_dispatch.state', 'paused')
            ->assertJsonPath('queue_visibility.task_queues.0.activity_dispatch.paused_by.label', 'Operator')
            ->assertJsonPath('queue_visibility.activity_dispatch_control', ['available' => true, 'reason' => null]);

        self::assertSame(1, $client->count('clusterInfo'));
    }

    public function testServerWithoutTheCapabilityOffersNothingAndIsNeverAskedToPause(): void
    {
        $client = $this->client(capable: false);
        $client->queues = [['name' => 'payments', 'stats' => []]];

        $this->getJson('/waterline/api/v2/health')
            ->assertOk()
            ->assertJsonMissingPath('queue_visibility.task_queues.0.activity_dispatch')
            ->assertJsonPath('queue_visibility.activity_dispatch_control.available', false)
            ->assertJsonPath('queue_visibility.activity_dispatch_control.reason', 'activity_dispatch_server_unavailable');

        foreach (['pause', 'resume'] as $action) {
            $this->postJson($this->path('payments', $action))
                ->assertStatus(501)
                ->assertJsonPath('reason', 'backend_capability_unavailable')
                ->assertJsonPath('capability', 'task_queue_activity_dispatch_pause')
                ->assertJsonPath('activity_dispatch_reason', 'activity_dispatch_server_unavailable');
        }

        self::assertSame(0, $client->count('pauseTaskQueueActivityDispatch'));
        self::assertSame(0, $client->count('resumeTaskQueueActivityDispatch'));
    }

    public function testAnSdkWithoutThePauseCallsOffersNothingAndSkipsDiscovery(): void
    {
        $client = new FakeRemoteClient();
        $this->app->instance(RemoteBackend::class, new RemoteBackend($client));

        $this->getJson('/waterline/api/v2/health')
            ->assertOk()
            ->assertJsonPath('queue_visibility.activity_dispatch_control.reason', 'activity_dispatch_sdk_unavailable');

        $this->postJson($this->path('orders', 'pause'))
            ->assertStatus(501)
            ->assertJsonPath('activity_dispatch_reason', 'activity_dispatch_sdk_unavailable')
            ->assertJsonPath('required_sdk_method', 'clusterInfo, pauseTaskQueueActivityDispatch, resumeTaskQueueActivityDispatch');
    }

    public function testUnreadableCapabilitiesOfferNothing(): void
    {
        $client = $this->client();
        $client->failures['clusterInfo'] = new ServerException('Unavailable', 503, 'server_unavailable');

        $this->getJson('/waterline/api/v2/health')
            ->assertOk()
            ->assertJsonPath('queue_visibility.activity_dispatch_control.reason', 'activity_dispatch_capability_unreadable');
        $this->postJson($this->path('payments', 'pause'))->assertStatus(501);
        self::assertSame(0, $client->count('pauseTaskQueueActivityDispatch'));
    }

    public function testReadOnlyRefusesBeforeAnyServerCall(): void
    {
        config()->set('waterline.service.access_mode', 'read_only');
        $client = $this->client();

        $this->getJson('/waterline/api/v2/health')
            ->assertOk()
            ->assertJsonPath('queue_visibility.activity_dispatch_control', ['available' => false, 'reason' => 'waterline_read_only'])
            ->assertJsonPath('queue_visibility.task_queues.0.activity_dispatch.state', 'paused');

        $this->postJson($this->path('payments', 'pause'), ['reason' => 'outage'])
            ->assertForbidden()
            ->assertJsonPath('reason', 'waterline_read_only')
            ->assertJsonPath('capability', 'task_queue.activity_dispatch.pause');
        $this->postJson($this->path('payments', 'resume'))
            ->assertForbidden()
            ->assertJsonPath('reason', 'waterline_read_only')
            ->assertJsonPath('capability', 'task_queue.activity_dispatch.resume');

        self::assertSame(0, $client->count('clusterInfo'));
        self::assertSame(0, $client->count('pauseTaskQueueActivityDispatch'));
        self::assertSame(0, $client->count('resumeTaskQueueActivityDispatch'));
    }

    public function testServerRefusalForAWorkerTokenIsShownAsAnAuthorizationFailure(): void
    {
        $client = $this->client();
        $client->failures['pauseTaskQueueActivityDispatch'] = new ServerException(
            'This action is unauthorized.',
            403,
            'forbidden',
            ['message' => 'This action is unauthorized.', 'reason' => 'forbidden'],
        );

        $this->postJson($this->path('payments', 'pause'))
            ->assertForbidden()
            ->assertJsonPath('error', 'remote_authorization_failed')
            ->assertJsonPath('reason', 'forbidden')
            ->assertJsonPath('remote_status', 403);
    }

    public function testPauseForwardsTheTrimmedReasonAndReturnsTheOutcome(): void
    {
        $client = $this->client();

        $this->postJson($this->path('payments', 'pause'), ['reason' => '  payments vendor outage  '])
            ->assertOk()
            ->assertJsonPath('outcome', 'paused')
            ->assertJsonPath('namespace', 'orders')
            ->assertJsonPath('task_queue', 'payments')
            ->assertJsonPath('activity_dispatch.state', 'paused')
            ->assertJsonPath('activity_dispatch.paused_by.id', 'role:operator')
            ->assertJsonPath('operator_scope.namespace', 'orders');

        self::assertSame([['payments', 'payments vendor outage']], $client->arguments('pauseTaskQueueActivityDispatch'));

        $this->postJson($this->path('payments', 'pause'), ['reason' => '   '])->assertOk();
        $this->postJson($this->path('payments', 'pause'))->assertOk();
        self::assertSame([null, null], array_column(array_slice($client->arguments('pauseTaskQueueActivityDispatch'), 1), 1));
    }

    public function testAlreadyPausedIsReportedWithTheOriginalPause(): void
    {
        $client = $this->client();
        $client->pauseOutcome = 'already_paused';

        $this->postJson($this->path('payments', 'pause'), ['reason' => 'another reason'])
            ->assertOk()
            ->assertJsonPath('outcome', 'already_paused')
            ->assertJsonPath('activity_dispatch.reason', 'payments vendor outage');
    }

    public function testResumeAndNotPausedOutcomes(): void
    {
        $client = $this->client();

        $this->postJson($this->path('payments', 'resume'))
            ->assertOk()
            ->assertJsonPath('outcome', 'resumed')
            ->assertJsonPath('activity_dispatch.state', 'active')
            ->assertJsonPath('activity_dispatch.resumed_by.id', 'role:admin');

        $client->resumeOutcome = 'not_paused';
        $this->postJson($this->path('payments', 'resume'))
            ->assertOk()
            ->assertJsonPath('outcome', 'not_paused');

        self::assertSame([['payments'], ['payments']], $client->arguments('resumeTaskQueueActivityDispatch'));
    }

    public function testQueueNamesWithSlashesAndSpacesAreAddressedExactly(): void
    {
        $client = $this->client();

        $this->postJson($this->path('billing/EU west', 'pause'))
            ->assertOk()
            ->assertJsonPath('task_queue', 'billing/EU west');
        self::assertSame([['billing/EU west', null]], $client->arguments('pauseTaskQueueActivityDispatch'));
    }

    public function testAReasonOverOneThousandCharactersIsRefusedBeforeTheServer(): void
    {
        $client = $this->client();

        $this->postJson($this->path('payments', 'pause'), ['reason' => str_repeat('é', 1001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
        $this->postJson($this->path('payments', 'pause'), ['reason' => ['not', 'text']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        self::assertSame(0, $client->count('clusterInfo'));
        self::assertSame(0, $client->count('pauseTaskQueueActivityDispatch'));

        $this->postJson($this->path('payments', 'pause'), ['reason' => str_repeat('é', 1000)])->assertOk();
    }

    public function testServerValidationErrorsAreReturnedAsUnprocessable(): void
    {
        $client = $this->client();
        $errors = ['task_queue' => ['The task queue may not be greater than 255 characters.']];
        $client->failures['pauseTaskQueueActivityDispatch'] = new ServerException(
            'The given data was invalid.',
            422,
            null,
            ['message' => 'The given data was invalid.', 'errors' => $errors],
        );

        $this->postJson($this->path(str_repeat('q', 256), 'pause'))
            ->assertUnprocessable()
            ->assertJsonPath('remote_status', 422)
            ->assertJsonPath('remote_details.errors', $errors);
    }

    public function testServiceModeRegistersThePauseRoutes(): void
    {
        self::assertTrue(Route::has('waterline.v2.task-queues.activity-dispatch.pause'));
        self::assertTrue(Route::has('waterline.v2.task-queues.activity-dispatch.resume'));
    }

    private function path(string $taskQueue, string $action): string
    {
        return '/waterline/api/v2/task-queues/'.rawurlencode($taskQueue).'/activity-dispatch/'.$action;
    }

    private function client(bool $capable = true): object
    {
        $client = new class(self::PAUSED, self::RESUMED, $capable) {
            /** @var list<array{0: string, 1: array<int, mixed>}> */
            public array $calls = [];

            /** @var array<string, \Throwable> */
            public array $failures = [];

            public string $pauseOutcome = 'paused';

            public string $resumeOutcome = 'resumed';

            /** @var list<array<string, mixed>> */
            public array $queues;

            public function __construct(private array $paused, private array $resumed, private bool $capable)
            {
                $this->queues = [['name' => 'payments', 'stats' => [], 'activity_dispatch' => $paused]];
            }

            public function clusterInfo(): ClusterInfo
            {
                $this->record(__FUNCTION__, []);

                return ClusterInfo::fromArray(['capabilities' => $this->capable
                    ? ['task_queue_activity_dispatch_pause' => true]
                    : []]);
            }

            public function listWorkers(?string $taskQueue = null, ?string $status = null): array
            {
                $this->record(__FUNCTION__, [$taskQueue, $status]);

                return ['workers' => []];
            }

            public function listTaskQueues(): array
            {
                $this->record(__FUNCTION__, []);

                return ['namespace' => 'orders', 'task_queues' => $this->queues];
            }

            public function pauseTaskQueueActivityDispatch(string $taskQueue, ?string $reason = null): array
            {
                $this->record(__FUNCTION__, [$taskQueue, $reason]);

                return ['namespace' => 'orders', 'task_queue' => $taskQueue, 'outcome' => $this->pauseOutcome,
                    'activity_dispatch' => $this->paused];
            }

            public function resumeTaskQueueActivityDispatch(string $taskQueue): array
            {
                $this->record(__FUNCTION__, [$taskQueue]);

                return ['namespace' => 'orders', 'task_queue' => $taskQueue, 'outcome' => $this->resumeOutcome,
                    'activity_dispatch' => $this->resumed];
            }

            public function count(string $method): int
            {
                return count($this->arguments($method));
            }

            /** @return list<array<int, mixed>> */
            public function arguments(string $method): array
            {
                return array_values(array_map(
                    static fn (array $call): array => $call[1],
                    array_filter($this->calls, static fn (array $call): bool => $call[0] === $method),
                ));
            }

            private function record(string $method, array $arguments): void
            {
                $this->calls[] = [$method, $arguments];
                if (isset($this->failures[$method])) {
                    throw $this->failures[$method];
                }
            }
        };
        $this->app->instance(RemoteBackend::class, new RemoteBackend($client));

        return $client;
    }
}
