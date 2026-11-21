<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use Orchestra\Testbench\TestCase;
use Waterline\Support\Remote\RemoteBackend;
use Waterline\Waterline;
use Waterline\WaterlineServiceProvider;

/**
 * Native Server 3.13.0 pages GET /api/workers (server#553). Waterline reads
 * every page through the SDK when it can and says so when it cannot.
 */
final class ServiceModeWorkerListPagingTest extends TestCase
{
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
        $app['config']->set('waterline.middleware', []);
        $app['config']->set('waterline.api_middleware', []);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Waterline::auth(static fn (): bool => true);
    }

    public function testAPagingSdkReadsEveryRegistrationPastTheFirstServerPage(): void
    {
        $client = $this->pagingClient(
            ['workers' => self::workers('active', 150), 'next_page_token' => null, 'registration_retention_seconds' => 86400],
            ['workers' => self::workers('stale', 120), 'next_page_token' => null, 'registration_retention_seconds' => 86400],
        );

        $payload = $this->getJson('/waterline/api/v2/health')->assertOk()->json();

        $this->assertSame([[null, null], [null, 'stale']], $client->listAllWorkersCalls);
        $this->assertSame(150, $payload['operator_metrics']['workers']['active_registration_count']);
        $this->assertSame(120, $payload['operator_metrics']['workers']['stale_registration_count']);
        $this->assertSame(270, $payload['operator_metrics']['workers']['registration_count']);
        $this->assertNotContains('worker_list_complete', array_column($payload['checks'], 'name'));
    }

    public function testAPagingSdkThatStopsAtItsPageBoundIsReportedAsIncomplete(): void
    {
        $this->pagingClient(
            ['workers' => self::workers('active', 5000), 'next_page_token' => 'unread', 'registration_retention_seconds' => 86400],
            ['workers' => [], 'next_page_token' => null],
        );

        $payload = $this->getJson('/waterline/api/v2/health')->assertOk()->json();

        $this->assertSame(5000, $payload['operator_metrics']['workers']['active_registration_count']);
        $check = self::check($payload, 'worker_list_complete');
        $this->assertSame('warning', $check['status']);
        $this->assertSame('correctness', $check['category']);
        $this->assertSame('worker_list_page_bound_reached', $check['reason']);
    }

    public function testAnSdkWithoutPagingReportsAPagedServerListAsIncomplete(): void
    {
        $client = $this->singlePageClient(['workers' => self::workers('active', 100), 'next_page_token' => 'page-2']);

        $payload = $this->getJson('/waterline/api/v2/health')->assertOk()->json();

        $this->assertSame([[null, null], [null, 'stale']], $client->listWorkersCalls);
        $this->assertSame(100, $payload['operator_metrics']['workers']['active_registration_count']);
        $this->assertSame('worker_list_paging_unsupported', self::check($payload, 'worker_list_complete')['reason']);
    }

    public function testATruncatedRosterWithoutAContinuationIsReportedAsIncomplete(): void
    {
        $this->pagingClient(['workers' => self::workers('active', 2)], ['workers' => [], 'truncated' => true]);

        $payload = $this->getJson('/waterline/api/v2/health')->assertOk()->json();

        $this->assertSame(2, $payload['operator_metrics']['workers']['active_registration_count']);
        $this->assertSame('worker_list_truncated', self::check($payload, 'worker_list_complete')['reason']);
    }

    public function testAServerThatDoesNotPageStaysCompleteWithEitherSdk(): void
    {
        $this->singlePageClient(['workers' => self::workers('active', 150)]);
        $payload = $this->getJson('/waterline/api/v2/health')->assertOk()->json();
        $this->assertSame(150, $payload['operator_metrics']['workers']['active_registration_count']);
        $this->assertNotContains('worker_list_complete', array_column($payload['checks'], 'name'));

        $this->pagingClient(['workers' => self::workers('active', 150)], ['workers' => []]);
        $payload = $this->getJson('/waterline/api/v2/health')->assertOk()->json();
        $this->assertSame(150, $payload['operator_metrics']['workers']['active_registration_count']);
        $this->assertNotContains('worker_list_complete', array_column($payload['checks'], 'name'));
    }

    /**
     * @param array<string, mixed> $active
     * @param array<string, mixed> $stale
     */
    private function pagingClient(array $active, array $stale): object
    {
        $client = new class($active, $stale)
        {
            /** @var list<array{0: ?string, 1: ?string}> */
            public array $listAllWorkersCalls = [];

            public function __construct(private readonly array $active, private readonly array $stale) {}

            public function listWorkers(?string $taskQueue = null, ?string $status = null, ?int $pageSize = null, ?string $nextPageToken = null): array
            {
                throw new \LogicException('A paging SDK must not be read one page at a time.');
            }

            public function listAllWorkers(?string $taskQueue = null, ?string $status = null, ?int $pageSize = null, int $maxPages = 50): array
            {
                $this->listAllWorkersCalls[] = [$taskQueue, $status];

                return $status === 'stale' ? $this->stale : $this->active;
            }

            public function listTaskQueues(): array
            {
                return ['task_queues' => []];
            }
        };
        $this->app->instance(RemoteBackend::class, new RemoteBackend($client));

        return $client;
    }

    /** @param array<string, mixed> $active */
    private function singlePageClient(array $active): object
    {
        $client = new class($active)
        {
            /** @var list<array{0: ?string, 1: ?string}> */
            public array $listWorkersCalls = [];

            public function __construct(private readonly array $active) {}

            public function listWorkers(?string $taskQueue = null, ?string $status = null): array
            {
                $this->listWorkersCalls[] = [$taskQueue, $status];

                return $status === 'stale' ? ['workers' => []] : $this->active;
            }

            public function listTaskQueues(): array
            {
                return ['task_queues' => []];
            }
        };
        $this->app->instance(RemoteBackend::class, new RemoteBackend($client));

        return $client;
    }

    /** @return list<array<string, mixed>> */
    private static function workers(string $status, int $count): array
    {
        return array_map(static fn (int $index): array => [
            'worker_id' => sprintf('%s-%05d', $status, $index),
            'namespace' => 'orders',
            'task_queue' => 'orders',
            'status' => $status,
        ], range(1, $count));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function check(array $payload, string $name): array
    {
        foreach ($payload['checks'] as $check) {
            if (($check['name'] ?? null) === $name) {
                return $check;
            }
        }

        self::fail('The health payload has no '.$name.' check.');
    }
}
