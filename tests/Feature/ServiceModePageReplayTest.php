<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use Composer\InstalledVersions;
use DurableWorkflow\Waterline\CI\SqlServerQualificationTls;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use ReflectionProperty;
use Orchestra\Testbench\TestCase;
use function Orchestra\Testbench\artisan;
use Waterline\Support\Remote\CursorPageReplay;
use Waterline\Support\Remote\MonotonicClock;
use Waterline\Support\Remote\RemoteBackend;
use Waterline\Support\ServiceModeRequirements;
use Waterline\Tests\Fixtures\FakeMonotonicClock;
use Waterline\Tests\Fixtures\FakeRemoteClient;
use Waterline\Waterline;
use Waterline\WaterlineServiceProvider;

require_once dirname(__DIR__, 2).'/scripts/ci/SqlServerQualificationTls.php';

/**
 * Service-mode lists reach page N by replaying the server's next-page tokens.
 * One Waterline request must stay bounded in calls and time even when the
 * server never stops returning tokens.
 */
final class ServiceModePageReplayTest extends TestCase
{
    private const LISTS = [
        'workflows' => ['/waterline/api/flows/running', 'listWorkflows'],
        'schedules' => ['/waterline/api/v2/schedules', 'listSchedules'],
    ];

    /** @var array<string, mixed> */
    private static array $installedVersions;

    private FakeRemoteClient $client;

    private FakeMonotonicClock $clock;

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
        $this->clock = new FakeMonotonicClock();
        $this->app->instance(MonotonicClock::class, $this->clock);
        $this->client = new FakeRemoteClient();
        $this->client->clock = $this->clock;
        $this->app->instance(RemoteBackend::class, new RemoteBackend($this->client));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testOrdinaryPaginationReplaysTokensUpToTheRequestedPage(): void
    {
        foreach (self::LISTS as $name => [$path, $method]) {
            $this->client->calls = [];
            $this->client->listPageCount = 5;

            $this->getJson($path.'?page=3')
                ->assertOk()
                ->assertJsonPath('current_page', 3)
                ->assertJsonPath('last_page', 4)
                ->assertJsonPath('next_page_token', 'page-4')
                ->assertJsonPath('page_limit', CursorPageReplay::MAX_PAGE)
                ->assertJsonPath('page_limit_reached', false);

            $this->assertSame([null, 'page-2', 'page-3'], $this->listTokens($method), $name);

            $this->client->calls = [];

            $this->getJson($path.'?page=5')
                ->assertOk()
                ->assertJsonPath('current_page', 5)
                ->assertJsonPath('last_page', 5)
                ->assertJsonPath('next_page_token', null);

            $this->assertCount(5, $this->listTokens($method), $name);
        }
    }

    public function testAPageBeyondTheServerDataReportsTheLastPageActuallyRead(): void
    {
        foreach (self::LISTS as $name => [$path, $method]) {
            $this->client->calls = [];
            $this->client->listPageCount = 2;

            $this->getJson($path.'?page=9')
                ->assertOk()
                ->assertJsonPath('current_page', 2)
                ->assertJsonPath('last_page', 2)
                ->assertJsonPath('next_page_token', null);

            $this->assertCount(2, $this->listTokens($method), $name);
        }
    }

    public function testMalformedAndOversizedPagesAreRefusedBeforeAnyServerCall(): void
    {
        // Numeric-string keys become ints in PHP arrays; cast back when building URLs.
        $refusals = [
            'abc' => 'invalid_page',
            '0' => 'invalid_page',
            '-3' => 'invalid_page',
            '1.5' => 'invalid_page',
            '01' => 'invalid_page',
            '9999999999' => 'invalid_page',
            (string) (CursorPageReplay::MAX_PAGE + 1) => 'page_beyond_replay_limit',
            '1000000' => 'page_beyond_replay_limit',
        ];

        foreach (self::LISTS as $name => [$path, $method]) {
            foreach ($refusals as $page => $reason) {
                $this->client->calls = [];

                $this->getJson($path.'?page='.urlencode((string) $page))
                    ->assertStatus(422)
                    ->assertJsonPath('reason', $reason)
                    ->assertJsonPath('max_page', CursorPageReplay::MAX_PAGE);

                $this->assertSame([], $this->listTokens($method), $name.' page='.$page);
            }

            $this->client->calls = [];

            $this->getJson($path.'?page[]=2')->assertStatus(422)->assertJsonPath('reason', 'invalid_page');
            $this->assertSame([], $this->listTokens($method), $name.' page[]');

            $this->getJson($path.'?page=')->assertOk()->assertJsonPath('current_page', 1);
        }

        // PHP's `$` also matches before a final newline. Laravel's default
        // TrimStrings turns "3\n" into the valid page 3 before Waterline sees
        // it, so refuse these directly and through hosts without trimming.
        $padded = ["3\n", "3\r\n", "\n3", ' 3', '3 ', "3\t"];

        foreach ($padded as $page) {
            try {
                CursorPageReplay::requestedPage(Request::create('/?page='.urlencode($page)));
                $this->fail('Accepted page '.json_encode($page));
            } catch (HttpResponseException $refusal) {
                $this->assertSame(422, $refusal->getResponse()->getStatusCode(), json_encode($page));
                $this->assertSame('invalid_page', $refusal->getResponse()->getData(true)['reason'] ?? null);
            }
        }

        TrimStrings::skipWhen(static fn (): bool => true);

        try {
            foreach (self::LISTS as $name => [$path, $method]) {
                foreach ($padded as $page) {
                    $this->client->calls = [];

                    $this->getJson($path.'?page='.urlencode($page))
                        ->assertStatus(422)
                        ->assertJsonPath('reason', 'invalid_page');

                    $this->assertSame([], $this->listTokens($method), $name.' page='.json_encode($page));
                }
            }
        } finally {
            $callbacks = new ReflectionProperty(TrimStrings::class, 'skipCallbacks');
            $callbacks->setAccessible(true);
            $callbacks->setValue(null, []);
        }
    }

    public function testEndlessNextTokensStopAtTheReplayCallLimit(): void
    {
        foreach (self::LISTS as $name => [$path, $method]) {
            $this->client->calls = [];
            $this->client->listPageCount = PHP_INT_MAX;

            $this->getJson($path.'?page='.CursorPageReplay::MAX_PAGE)
                ->assertOk()
                ->assertJsonPath('current_page', CursorPageReplay::MAX_PAGE)
                ->assertJsonPath('last_page', CursorPageReplay::MAX_PAGE)
                ->assertJsonPath('page_limit_reached', true)
                ->assertJsonPath('next_page_token', 'page-'.(CursorPageReplay::MAX_PAGE + 1));

            $this->assertCount(CursorPageReplay::MAX_PAGE, $this->listTokens($method), $name);
        }
    }

    public function testSlowServerPagesStopAtTheAggregateRequestBudget(): void
    {
        config()->set('waterline.browser.request_timeout_seconds', 65);

        foreach (self::LISTS as $name => [$path, $method]) {
            $this->client->calls = [];
            $this->client->listPageCount = PHP_INT_MAX;
            $this->client->listCallSeconds = 20;
            $started = $this->clock->now;

            $this->getJson($path.'?page=50')
                ->assertStatus(504)
                ->assertJsonPath('reason', 'page_replay_budget_exceeded')
                ->assertJsonPath('pages_read', 3)
                ->assertJsonPath('requested_page', 50);

            // The replay budget is 65s - 5s = 60s. Three 20s pages reach it; no
            // fourth call starts, and the refusal arrives before the browser budget.
            $this->assertCount(3, $this->listTokens($method), $name);
            $this->assertSame(60000.0, $this->clock->now - $started, $name);
            $this->assertLessThan(65000.0, $this->clock->now - $started, $name);
        }
    }

    public function testAWallClockSteppedBackwardsCannotExtendTheReplay(): void
    {
        config()->set('waterline.browser.request_timeout_seconds', 65);

        foreach (self::LISTS as $name => [$path, $method]) {
            $this->client->calls = [];
            $this->client->listPageCount = PHP_INT_MAX;
            $this->client->listCallSeconds = 20;
            $this->client->listCallWallClockSeconds = -3600;
            $wallStarted = Carbon::now();

            $this->getJson($path.'?page=50')
                ->assertStatus(504)
                ->assertJsonPath('reason', 'page_replay_budget_exceeded')
                ->assertJsonPath('pages_read', 3);

            $this->assertCount(3, $this->listTokens($method), $name);
            $this->assertTrue(Carbon::now()->lessThan($wallStarted), $name.' wall clock moved backwards');
        }
    }

    public function testAFrozenWallClockCannotExtendTheReplay(): void
    {
        config()->set('waterline.browser.request_timeout_seconds', 65);

        foreach (self::LISTS as $name => [$path, $method]) {
            $this->client->calls = [];
            $this->client->listPageCount = PHP_INT_MAX;
            $this->client->listCallSeconds = 20;
            $this->client->listCallWallClockSeconds = 0;
            $wallStarted = Carbon::now()->toJSON();

            $this->getJson($path.'?page=50')
                ->assertStatus(504)
                ->assertJsonPath('reason', 'page_replay_budget_exceeded')
                ->assertJsonPath('pages_read', 3);

            $this->assertCount(3, $this->listTokens($method), $name);
            $this->assertSame($wallStarted, Carbon::now()->toJSON(), $name.' wall clock stayed frozen');
        }
    }

    public function testAWallClockJumpForwardDoesNotCutTheReplayShort(): void
    {
        config()->set('waterline.browser.request_timeout_seconds', 65);

        foreach (self::LISTS as $name => [$path, $method]) {
            $this->client->calls = [];
            $this->client->listPageCount = PHP_INT_MAX;
            $this->client->listCallSeconds = 1;
            $this->client->listCallWallClockSeconds = 3600;

            $this->getJson($path.'?page=10')
                ->assertOk()
                ->assertJsonPath('current_page', 10);

            $this->assertCount(10, $this->listTokens($method), $name);
        }
    }

    public function testTheDefaultClockIsMonotonicAndIgnoresTheTestClock(): void
    {
        $clock = new MonotonicClock();
        $first = $clock->milliseconds();

        Carbon::setTestNow('2020-01-01T00:00:00Z');
        $second = $clock->milliseconds();
        Carbon::setTestNow('2030-01-01T00:00:00Z');
        $third = $clock->milliseconds();

        $this->assertGreaterThanOrEqual($first, $second);
        $this->assertGreaterThanOrEqual($second, $third);
        $this->assertLessThan(60000.0, $third - $first);
    }

    public function testShortBrowserBudgetsKeepHalfTheBudgetForReplay(): void
    {
        config()->set('waterline.browser.request_timeout_seconds', 8);
        $this->assertSame(4000, CursorPageReplay::budgetMilliseconds());

        config()->set('waterline.browser.request_timeout_seconds', 65);
        $this->assertSame(60000, CursorPageReplay::budgetMilliseconds());

        foreach (self::LISTS as $name => [$path, $method]) {
            config()->set('waterline.browser.request_timeout_seconds', 8);
            $this->client->calls = [];
            $this->client->listPageCount = PHP_INT_MAX;
            $this->client->listCallSeconds = 2;

            $this->getJson($path.'?page=50')
                ->assertStatus(504)
                ->assertJsonPath('pages_read', 2);

            $this->assertCount(2, $this->listTokens($method), $name);
        }
    }

    public function testRetryAfterABudgetRefusalSucceedsOnceTheServerIsFast(): void
    {
        config()->set('waterline.browser.request_timeout_seconds', 65);

        foreach (self::LISTS as $name => [$path, $method]) {
            $this->client->listPageCount = PHP_INT_MAX;
            $this->client->listCallSeconds = 30;

            $this->getJson($path.'?page=10')->assertStatus(504);

            $this->client->calls = [];
            $this->client->listCallSeconds = 1;

            $this->getJson($path.'?page=10')
                ->assertOk()
                ->assertJsonPath('current_page', 10);

            $this->assertCount(10, $this->listTokens($method), $name);
        }
    }

    /** @return list<string|null> next-page tokens passed to each list call */
    private function listTokens(string $method): array
    {
        return array_values(array_map(
            static fn (array $call): mixed => $call['arguments']['nextPageToken'] ?? null,
            array_filter($this->client->calls, static fn (array $call): bool => $call['method'] === $method),
        ));
    }
}
