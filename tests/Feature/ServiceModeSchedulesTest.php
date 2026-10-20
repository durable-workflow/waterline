<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use Composer\InstalledVersions;
use DurableWorkflow\Exception\ServerException;
use DurableWorkflow\Exception\TransportException;
use DurableWorkflow\Waterline\CI\SqlServerQualificationTls;
use Illuminate\Support\Carbon;
use Orchestra\Testbench\TestCase;
use function Orchestra\Testbench\artisan;
use Waterline\Support\Remote\RemoteBackend;
use Waterline\Support\ServiceModeRequirements;
use Waterline\Tests\Fixtures\FakeRemoteClient;
use Waterline\Waterline;
use Waterline\WaterlineServiceProvider;

require_once dirname(__DIR__, 2).'/scripts/ci/SqlServerQualificationTls.php';

final class ServiceModeSchedulesTest extends TestCase
{
    private const SCHEDULE_METHODS = [
        'listSchedules',
        'describeSchedule',
        'scheduleHistory',
        'pauseSchedule',
        'resumeSchedule',
        'triggerSchedule',
        'backfillSchedule',
        'deleteSchedule',
    ];

    /** @var array<string, mixed> */
    private static array $installedVersions;

    private FakeRemoteClient $client;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$installedVersions = InstalledVersions::getRawData();
        $planned = self::$installedVersions;
        $planned['versions']['durable-workflow/sdk']['pretty_version'] = ServiceModeRequirements::SDK_QUALIFIED_VERSION;
        $planned['versions']['durable-workflow/sdk']['version'] = '2.1.0.0';
        InstalledVersions::reload($planned);
    }

    public static function tearDownAfterClass(): void
    {
        InstalledVersions::reload(self::$installedVersions);

        parent::tearDownAfterClass();
    }

    protected function getPackageProviders($app): array
    {
        return [WaterlineServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI=');
        $app['config']->set('waterline.backend', 'service');
        $app['config']->set('waterline.service.endpoint', 'https://server.example');
        $app['config']->set('waterline.service.token', 'secret');
        $app['config']->set('waterline.service.namespace', 'orders');
        $app['config']->set('waterline.service.access_mode', 'read_only');
        $app['config']->set('waterline.middleware', []);
        $app['config']->set('waterline.api_middleware', []);

        if (($_ENV['DB_CONNECTION'] ?? getenv('DB_CONNECTION')) === 'sqlsrv') {
            foreach (SqlServerQualificationTls::laravelConfiguration() as $option => $value) {
                $app['config']->set("database.connections.sqlsrv.{$option}", $value);
            }
        }
    }

    protected function defineDatabaseMigrations(): void
    {
        artisan($this, 'migrate:fresh');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-12T12:00:15Z');
        Waterline::auth(static fn (): bool => true);
        $this->client = new FakeRemoteClient();
        $this->app->instance(RemoteBackend::class, new RemoteBackend($this->client));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testListRowsAreNormalizedWithTheLastOutcomeFromEachDetail(): void
    {
        $response = $this->getJson('/waterline/api/v2/schedules')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('operator_scope.namespace', 'orders')
            ->assertJsonPath('backend.mode', 'service');

        $nightly = $response->json('data.0');
        $this->assertSame('nightly-orders', $nightly['id']);
        $this->assertSame('nightly-orders', $nightly['schedule_id']);
        $this->assertSame('orders', $nightly['namespace']);
        $this->assertSame('orders.process', $nightly['workflow_type']);
        $this->assertSame('active', $nightly['status']);
        $this->assertFalse($nightly['paused']);
        $this->assertSame(['cron_expressions' => ['0 0 * * *'], 'intervals' => [], 'timezone' => 'UTC'], $nightly['spec']);
        $this->assertSame('skip', $nightly['overlap_policy']);
        $this->assertSame('2026-08-13T00:00:00+00:00', $nightly['next_fire_at']);
        $this->assertSame('2026-08-12T00:00:00+00:00', $nightly['last_fired_at']);
        $this->assertSame(42, $nightly['fires_count']);
        // Failure count and outcome exist only in the server detail.
        $this->assertSame(1, $nightly['failures_count']);
        $this->assertSame([
            'outcome' => 'scheduled',
            'at' => '2026-08-12T00:00:00+00:00',
            'reason' => null,
            'workflow_id' => 'schedule:nightly-orders:1786492800000',
            'run_id' => '01JAHX5M2N3P4Q5R6S7T8V9W0X',
        ], $nightly['last_outcome']);
        $this->assertSame(['region' => 'eu'], $nightly['search_attributes']);
        $this->assertArrayNotHasKey('state', $nightly);
        $this->assertArrayNotHasKey('info', $nightly);
        $this->assertArrayNotHasKey('recent_actions', $nightly);

        $reconcile = $response->json('data.1');
        $this->assertSame('hourly-reconcile', $reconcile['id']);
        $this->assertSame('paused', $reconcile['status']);
        $this->assertTrue($reconcile['paused']);
        $this->assertSame('Paused during ledger migration.', $reconcile['note']);
        $this->assertSame([
            'cron_expressions' => [],
            'intervals' => [['every' => 'PT1H', 'offset' => 'PT5M']],
            'timezone' => 'UTC',
        ], $reconcile['spec']);
        $this->assertSame(3, $reconcile['remaining_actions']);
        $this->assertSame(2, $reconcile['skipped_trigger_count']);
        // The 11:30 skip is newer than the 11:05 fire.
        $this->assertSame([
            'outcome' => 'skipped',
            'at' => '2026-08-12T11:30:00+00:00',
            'reason' => 'overlap_policy_buffer_one',
            'workflow_id' => null,
            'run_id' => null,
        ], $reconcile['last_outcome']);

        $this->assertSame(
            [['listSchedules', null], ['describeSchedule', 'nightly-orders'], ['describeSchedule', 'hourly-reconcile']],
            array_map(
                static fn (array $call): array => [$call['method'], $call['arguments']['scheduleId'] ?? null],
                $this->scheduleCalls(),
            ),
        );
    }

    public function testListFiltersAreForwardedToTheServer(): void
    {
        $this->getJson('/waterline/api/v2/schedules?status=paused&workflow_type=orders.reconcile')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'hourly-reconcile');

        $list = $this->scheduleCalls()[0];
        $this->assertSame('listSchedules', $list['method']);
        $this->assertSame('paused', $list['arguments']['status']);
        $this->assertSame('orders.reconcile', $list['arguments']['workflowType']);
    }

    public function testListRowFallsBackToTheListItemWhenItsDetailCannotBeRead(): void
    {
        $this->client->describeScheduleFailures['nightly-orders'] = new ServerException(
            'The schedule detail is temporarily unavailable.',
            503,
            'backend_unavailable',
        );
        $this->client->describeScheduleFailures['hourly-reconcile'] = new TransportException('Connection reset.');

        $response = $this->getJson('/waterline/api/v2/schedules')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $nightly = $response->json('data.0');
        $this->assertSame('nightly-orders', $nightly['id']);
        $this->assertSame('orders', $nightly['namespace']);
        $this->assertSame('orders.process', $nightly['workflow_type']);
        $this->assertSame(['0 0 * * *'], $nightly['spec']['cron_expressions']);
        $this->assertSame('2026-08-13T00:00:00+00:00', $nightly['next_fire_at']);
        $this->assertSame(42, $nightly['fires_count']);
        $this->assertNull($nightly['last_outcome']);
        $this->assertNull($nightly['failures_count']);

        $this->assertSame('hourly-reconcile', $response->json('data.1.id'));
        $this->assertTrue($response->json('data.1.paused'));
        $this->assertNull($response->json('data.1.last_outcome'));
    }

    public function testOneFailedDetailDoesNotHideAnotherRowsOutcome(): void
    {
        $this->client->describeScheduleFailures['nightly-orders'] = new ServerException('Not found.', 404, 'schedule_not_found');

        $this->getJson('/waterline/api/v2/schedules')
            ->assertOk()
            ->assertJsonPath('data.0.last_outcome', null)
            ->assertJsonPath('data.1.last_outcome.outcome', 'skipped');
    }

    public function testListFillsAMissingNamespaceWithTheServiceNamespace(): void
    {
        $item = FakeRemoteClient::defaultScheduleListItems()[0];
        unset($item['namespace']);
        $this->client->scheduleListItems = [$item];
        $this->client->describeScheduleFailures['nightly-orders'] = new ServerException('Unavailable.', 503);

        $this->getJson('/waterline/api/v2/schedules')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'nightly-orders')
            ->assertJsonPath('data.0.namespace', 'orders');
    }

    public function testForeignNamespaceIsNotFoundWithoutCallingTheServer(): void
    {
        config()->set('waterline.service.access_mode', 'operator');

        foreach ($this->routes() as [$method, $uri, $body]) {
            $query = $method === 'GET' ? '?namespace=billing' : '';
            $this->json($method, $uri.$query, $method === 'GET' ? [] : [...$body, 'namespace' => 'billing'])
                ->assertNotFound()
                ->assertJsonPath('message', 'Schedule not found.')
                ->assertJsonPath('reason', 'schedule_not_found')
                ->assertJsonPath('operator_scope.namespace', 'orders');
        }

        $this->assertSame([], $this->scheduleCalls());

        $this->getJson('/waterline/api/v2/schedules/nightly-orders?namespace=orders')
            ->assertOk()
            ->assertJsonPath('namespace', 'orders');
    }

    public function testReadOnlyRefusalPrecedesNamespaceAndPolicyChecks(): void
    {
        $capabilities = [
            'pause' => 'schedule.pause',
            'resume' => 'schedule.resume',
            'trigger' => 'schedule.trigger',
            'backfill' => 'schedule.backfill',
        ];

        foreach ($capabilities as $action => $capability) {
            $this->postJson("/waterline/api/v2/schedules/nightly-orders/{$action}", [
                'namespace' => 'billing',
                'overlap_policy' => 'run_twice',
            ])
                ->assertForbidden()
                ->assertJsonPath('reason', 'waterline_read_only')
                ->assertJsonPath('capability', $capability);
        }

        $this->deleteJson('/waterline/api/v2/schedules/nightly-orders', ['namespace' => 'billing'])
            ->assertForbidden()
            ->assertJsonPath('reason', 'waterline_read_only')
            ->assertJsonPath('capability', 'schedule.delete');

        $this->assertSame([], $this->scheduleCalls());

        // Reads need no write access and still apply the namespace check.
        $this->getJson('/waterline/api/v2/schedules/nightly-orders?namespace=billing')->assertNotFound();
        $this->getJson('/waterline/api/v2/schedules/nightly-orders/history?namespace=billing')->assertNotFound();
        $this->assertSame([], $this->scheduleCalls());
    }

    public function testUnknownOverlapPolicyIsRefusedAfterTheNamespaceCheckAndBeforeTheServer(): void
    {
        config()->set('waterline.service.access_mode', 'operator');

        $this->postJson('/waterline/api/v2/schedules/nightly-orders/trigger', [
            'namespace' => 'billing',
            'overlap_policy' => 'run_twice',
        ])->assertNotFound();

        $this->postJson('/waterline/api/v2/schedules/nightly-orders/trigger', ['overlap_policy' => 'run_twice'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'invalid_overlap_policy');

        $this->postJson('/waterline/api/v2/schedules/nightly-orders/backfill', [
            'from' => '2026-08-10T00:00:00Z',
            'to' => '2026-08-12T00:00:00Z',
            'overlap_policy' => 'run_twice',
        ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'invalid_overlap_policy');

        $this->assertSame([], $this->scheduleCalls());
    }

    public function testShowReturnsTheDetailShape(): void
    {
        $this->getJson('/waterline/api/v2/schedules/nightly-orders')
            ->assertOk()
            ->assertJsonPath('id', 'nightly-orders')
            ->assertJsonPath('schedule_id', 'nightly-orders')
            ->assertJsonPath('namespace', 'orders')
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('paused', false)
            ->assertJsonPath('action.task_queue', 'orders')
            ->assertJsonPath('failures_count', 1)
            ->assertJsonPath('skipped_trigger_count', 0)
            ->assertJsonPath('jitter_seconds', 0)
            ->assertJsonPath('latest_workflow_instance_id', 'schedule:nightly-orders:1786492800000')
            ->assertJsonCount(2, 'recent_actions')
            ->assertJsonPath('recent_actions.0.outcome', 'failed')
            ->assertJsonPath('recent_actions.0.reason', 'Task queue [orders] has no workers.')
            ->assertJsonPath('recent_actions.1.run_id', '01JAHX5M2N3P4Q5R6S7T8V9W0X')
            ->assertJsonPath('recent_actions.1.occurrence_time', '2026-08-12T00:00:00.000000+00:00')
            ->assertJsonPath('last_outcome.outcome', 'scheduled')
            ->assertJsonPath('buffered_actions', [])
            ->assertJsonPath('search_attributes.region', 'eu')
            ->assertJsonMissingPath('state')
            ->assertJsonMissingPath('info')
            ->assertJsonPath('operator_scope.namespace', 'orders')
            ->assertJsonPath('backend.mode', 'service');

        $this->assertSame(['describeSchedule'], array_column($this->scheduleCalls(), 'method'));
    }

    public function testPauseAndResumeReturnTheRefreshedDetailShape(): void
    {
        config()->set('waterline.service.access_mode', 'operator');
        $this->client->scheduleDetails['hourly-reconcile'] = FakeRemoteClient::defaultScheduleDetail('hourly-reconcile');

        $this->postJson('/waterline/api/v2/schedules/hourly-reconcile/pause', [
            'namespace' => 'orders',
            'note' => 'Ledger migration.',
        ])
            ->assertOk()
            ->assertJsonPath('id', 'hourly-reconcile')
            ->assertJsonPath('namespace', 'orders')
            ->assertJsonPath('status', 'paused')
            ->assertJsonPath('paused_at', '2026-08-12T11:20:00+00:00')
            ->assertJsonPath('last_outcome.outcome', 'skipped')
            ->assertJsonPath('max_runs', 10)
            ->assertJsonMissingPath('info');

        $resumed = FakeRemoteClient::defaultScheduleDetail('hourly-reconcile');
        $resumed['status'] = 'active';
        $resumed['paused'] = false;
        $resumed['paused_at'] = null;
        $this->client->scheduleDetails['hourly-reconcile'] = $resumed;

        $this->postJson('/waterline/api/v2/schedules/hourly-reconcile/resume')
            ->assertOk()
            ->assertJsonPath('id', 'hourly-reconcile')
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('paused', false)
            ->assertJsonPath('paused_at', null);

        $calls = $this->scheduleCalls();
        $this->assertSame(
            ['pauseSchedule', 'describeSchedule', 'resumeSchedule', 'describeSchedule'],
            array_column($calls, 'method'),
        );
        $this->assertSame(['scheduleId' => 'hourly-reconcile', 'note' => 'Ledger migration.'], $calls[0]['arguments']);
        $this->assertSame(['scheduleId' => 'hourly-reconcile', 'note' => null], $calls[2]['arguments']);
    }

    public function testTriggerReportsTheServerOutcomeWithTheNamespace(): void
    {
        config()->set('waterline.service.access_mode', 'operator');

        $this->postJson('/waterline/api/v2/schedules/nightly-orders/trigger', [
            'namespace' => 'orders',
            'overlap_policy' => 'allow_all',
        ])
            ->assertOk()
            ->assertJsonPath('schedule_id', 'nightly-orders')
            ->assertJsonPath('namespace', 'orders')
            ->assertJsonPath('outcome', 'triggered')
            ->assertJsonPath('triggered', true)
            ->assertJsonPath('workflow_id', 'schedule:nightly-orders:1786536015000')
            ->assertJsonPath('instance_id', 'schedule:nightly-orders:1786536015000')
            ->assertJsonPath('run_id', '01JAHX7B2C3D4E5F6G7H8J9K0M')
            ->assertJsonPath('reason', null)
            ->assertJsonPath('operator_scope.namespace', 'orders')
            ->assertJsonPath('backend.mode', 'service');

        $this->assertSame(
            [['method' => 'triggerSchedule', 'arguments' => ['scheduleId' => 'nightly-orders', 'overlapPolicy' => 'allow_all']]],
            $this->scheduleCalls(),
        );
    }

    public function testTriggerReportsSkippedAndBufferedOutcomesAsNotTriggered(): void
    {
        config()->set('waterline.service.access_mode', 'operator');

        $this->client->triggerScheduleResponse = [
            'schedule_id' => 'nightly-orders',
            'outcome' => 'skipped',
            'reason' => 'overlap_policy_skip',
        ];
        $this->postJson('/waterline/api/v2/schedules/nightly-orders/trigger')
            ->assertOk()
            ->assertJsonPath('outcome', 'skipped')
            ->assertJsonPath('triggered', false)
            ->assertJsonPath('workflow_id', null)
            ->assertJsonPath('reason', 'overlap_policy_skip');

        $this->client->triggerScheduleResponse = [
            'schedule_id' => 'nightly-orders',
            'outcome' => 'buffered',
            'buffer_depth' => 1,
        ];
        $this->postJson('/waterline/api/v2/schedules/nightly-orders/trigger')
            ->assertOk()
            ->assertJsonPath('outcome', 'buffered')
            ->assertJsonPath('triggered', false)
            ->assertJsonPath('buffer_depth', 1)
            ->assertJsonPath('namespace', 'orders');
    }

    public function testBackfillReportsNormalizedResultsWithTheNamespace(): void
    {
        config()->set('waterline.service.access_mode', 'operator');

        $this->postJson('/waterline/api/v2/schedules/nightly-orders/backfill', [
            'from' => '2026-08-10T00:00:00Z',
            'to' => '2026-08-12T00:00:00Z',
        ])
            ->assertOk()
            ->assertJsonPath('schedule_id', 'nightly-orders')
            ->assertJsonPath('namespace', 'orders')
            ->assertJsonPath('fires_attempted', 2)
            ->assertJsonPath('started_count', 2)
            ->assertJsonPath('skipped_count', 0)
            ->assertJsonPath('failed_count', 0)
            ->assertJsonPath('results.0', [
                'fire_time' => '2026-08-10T00:00:00+00:00',
                'workflow_id' => 'schedule:nightly-orders:backfill:1786320000',
                'outcome' => 'started',
                'reason' => null,
            ])
            ->assertJsonPath('operator_scope.namespace', 'orders');

        $this->assertSame([[
            'method' => 'backfillSchedule',
            'arguments' => [
                'scheduleId' => 'nightly-orders',
                'startTime' => '2026-08-10T00:00:00Z',
                'endTime' => '2026-08-12T00:00:00Z',
                'overlapPolicy' => null,
            ],
        ]], $this->scheduleCalls());
    }

    public function testBackfillCountsSkippedAndFailedServerResults(): void
    {
        config()->set('waterline.service.access_mode', 'operator');
        $this->client->backfillScheduleResponse = [
            'schedule_id' => 'nightly-orders',
            'outcome' => 'backfill_started',
            'fires_attempted' => 3,
            'results' => [
                ['fire_time' => '2026-08-09T00:00:00+00:00', 'workflow_id' => 'wf-9', 'outcome' => 'started'],
                ['fire_time' => '2026-08-10T00:00:00+00:00', 'outcome' => 'skipped'],
                ['fire_time' => '2026-08-11T00:00:00+00:00', 'outcome' => 'failed', 'reason' => 'Task queue [orders] has no workers.'],
            ],
        ];

        $this->postJson('/waterline/api/v2/schedules/nightly-orders/backfill', [
            'from' => '2026-08-09T00:00:00Z',
            'to' => '2026-08-12T00:00:00Z',
            'overlap_policy' => 'buffer_all',
        ])
            ->assertOk()
            ->assertJsonPath('fires_attempted', 3)
            ->assertJsonPath('started_count', 1)
            ->assertJsonPath('skipped_count', 1)
            ->assertJsonPath('failed_count', 1)
            ->assertJsonPath('results.1.workflow_id', null)
            ->assertJsonPath('results.2.reason', 'Task queue [orders] has no workers.');

        $this->assertSame('buffer_all', $this->scheduleCalls()[0]['arguments']['overlapPolicy']);
    }

    public function testBackfillRequiresAWindowBeforeCallingTheServer(): void
    {
        config()->set('waterline.service.access_mode', 'operator');

        $this->postJson('/waterline/api/v2/schedules/nightly-orders/backfill', ['from' => '2026-08-10T00:00:00Z'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'invalid_backfill_window');

        $this->assertSame([], $this->scheduleCalls());
    }

    public function testHistoryCarriesTheNamespace(): void
    {
        $this->getJson('/waterline/api/v2/schedules/nightly-orders/history?namespace=orders&limit=5&after_sequence=2')
            ->assertOk()
            ->assertJsonPath('schedule_id', 'nightly-orders')
            ->assertJsonPath('namespace', 'orders')
            ->assertJsonPath('events.0.event_type', 'ScheduleCreated')
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('operator_scope.namespace', 'orders');

        $this->assertSame([[
            'method' => 'scheduleHistory',
            'arguments' => ['scheduleId' => 'nightly-orders', 'limit' => 5, 'afterSequence' => 2],
        ]], $this->scheduleCalls());
    }

    public function testDeleteReportsTheDeletedScheduleIdentity(): void
    {
        config()->set('waterline.service.access_mode', 'operator');

        $this->deleteJson('/waterline/api/v2/schedules/nightly-orders', ['namespace' => 'orders'])
            ->assertOk()
            ->assertJsonPath('id', 'nightly-orders')
            ->assertJsonPath('schedule_id', 'nightly-orders')
            ->assertJsonPath('namespace', 'orders')
            ->assertJsonPath('status', 'deleted');

        $this->assertSame(
            [['method' => 'deleteSchedule', 'arguments' => ['scheduleId' => 'nightly-orders']]],
            $this->scheduleCalls(),
        );
    }

    /**
     * Schedule calls only; every scoped response also probes backend capabilities.
     *
     * @return list<array{method: string, arguments: array<int|string, mixed>}>
     */
    private function scheduleCalls(): array
    {
        return array_values(array_filter(
            $this->client->calls,
            static fn (array $call): bool => in_array($call['method'], self::SCHEDULE_METHODS, true),
        ));
    }

    /**
     * @return list<array{string, string, array<string, string>}>
     */
    private function routes(): array
    {
        $base = '/waterline/api/v2/schedules/nightly-orders';

        return [
            ['GET', $base, []],
            ['GET', $base.'/history', []],
            ['POST', $base.'/pause', []],
            ['POST', $base.'/resume', []],
            ['POST', $base.'/trigger', []],
            ['POST', $base.'/backfill', ['from' => '2026-08-10T00:00:00Z', 'to' => '2026-08-12T00:00:00Z']],
            ['DELETE', $base, []],
        ];
    }
}
