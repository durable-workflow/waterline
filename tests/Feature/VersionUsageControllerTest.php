<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use DurableWorkflow\Exception\ServerException;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use Waterline\Http\Controllers\Remote\RemoteVersionUsageController;
use Waterline\Support\EmbeddedVersionUsage;
use Waterline\Support\Remote\RemoteBackend;
use Waterline\Support\VersionUsageInput;
use Waterline\Tests\Fixtures\VersionUsageFixture;
use Waterline\Tests\TestCase;

final class VersionUsageControllerTest extends TestCase
{
    private object $reader;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', null);
        $this->reader = new class
        {
            public array $calls = [];

            public ?\Throwable $failure = null;

            public bool $invalid = false;

            public function page($namespace, $workflowType, $changeId, $minimum, $limit, $cursor, $state): array
            {
                $this->calls[] = func_get_args();
                if ($this->failure !== null) {
                    throw $this->failure;
                }

                $page = VersionUsageFixture::page([
                    'namespace' => $namespace, 'workflow_type' => $workflowType, 'change_id' => $changeId,
                    'min_version' => $minimum, 'state' => $state, 'limit' => $limit, 'cursor' => $cursor,
                ]);
                if ($this->invalid) {
                    $page['runs'][2]['recorded_version'] = 1;
                }

                return $page;
            }
        };
        $this->app->instance(EmbeddedVersionUsage::class, new EmbeddedVersionUsage($this->reader));
    }

    public function testEmbeddedReadPassesDefaultNamespaceExplicitlyAndPreservesUnknowns(): void
    {
        $response = $this->getJson($this->url())->assertOk();
        self::assertSame(VersionUsageFixture::page(), $response->json());
        self::assertSame([[null, 'Orders', 'carrier-api', 1, 50, null, null]], $this->reader->calls);
    }

    public function testScopeRejectsForeignNamespaceBeforeTheReaderRuns(): void
    {
        config()->set('waterline.namespace', 'Tenant A');
        $this->getJson($this->url(['namespace' => 'Tenant a']))->assertForbidden();
        self::assertSame([], $this->reader->calls);
        $this->getJson($this->url())->assertOk()->assertJsonPath('namespace', 'Tenant A');
    }

    public function testOriginalQueryBytesSurviveHostTrimMiddleware(): void
    {
        $request = Request::create('/?'.http_build_query([
            'namespace' => ' Tenant ', 'workflow_type' => ' Orders ', 'change_id' => ' carrier-api ',
            'min_version' => '-2147483648', 'cursor' => ' opaque /+ = ',
        ]));
        foreach (['namespace', 'workflow_type', 'change_id', 'cursor'] as $key) {
            $request->query->set($key, trim($request->query($key)));
        }
        $query = VersionUsageInput::fromRequest($request);
        self::assertSame(' Tenant ', $query['namespace']);
        self::assertSame(' Orders ', $query['workflow_type']);
        self::assertSame(' carrier-api ', $query['change_id']);
        self::assertSame(' opaque /+ = ', $query['cursor']);
        self::assertSame(-2147483648, $query['min_version']);
    }

    public function testInvalidArgumentsNeverReachTheReader(): void
    {
        foreach ([
            ['workflow_type' => ''], ['workflow_type' => "\u{00a0}"], ['workflow_type' => ['Orders']], ['change_id' => str_repeat('x', 1025)],
            ['min_version' => '2147483648'], ['min_version' => '-2147483649'], ['min_version' => '1.5'],
            ['min_version' => ['1']], ['state' => 'safe'], ['limit' => 201], ['cursor' => ''], ['unexpected' => 'value'],
        ] as $invalid) {
            $this->getJson($this->url($invalid))->assertUnprocessable();
        }
        self::assertSame([], $this->reader->calls);
    }

    public function testCursorRefusalDoesNotRestartOrReturnZeroCounts(): void
    {
        $this->reader->failure = new InvalidArgumentException('invalid cursor');
        $this->getJson($this->url(['cursor' => 'opaque refused']))->assertUnprocessable()->assertJsonMissing(['counts' => []]);
        self::assertCount(1, $this->reader->calls);
        self::assertSame('opaque refused', $this->reader->calls[0][5]);
    }

    public function testReaderAndSchemaFailuresAreNotSuccessfulEmptyAnswers(): void
    {
        $this->reader->failure = new RuntimeException('storage unavailable');
        $this->getJson($this->url())->assertStatus(500)->assertJsonMissingPath('counts');
        $this->reader->failure = null;
        $this->reader->invalid = true;
        $this->getJson($this->url())->assertStatus(502)->assertJsonPath('reason', 'invalid_version_usage_response')->assertJsonMissingPath('counts');
    }

    public function testLegacyEngineCannotBeMistakenForAnEmptyV2Cohort(): void
    {
        config()->set('waterline.engine_source', 'v1');
        $this->getJson($this->url())->assertStatus(501)->assertJsonMissingPath('counts');
        self::assertSame([], $this->reader->calls);
    }

    public function testOlderSdkIsExplicitlyUnsupportedWithoutRawHttpFallback(): void
    {
        $controller = new RemoteVersionUsageController(new RemoteBackend(new \stdClass()));
        $response = $controller->show(Request::create($this->url()));
        self::assertSame(501, $response->status());
        self::assertSame('backend_capability_unavailable', $response->getData(true)['reason']);
        self::assertArrayNotHasKey('counts', $response->getData(true));
    }

    public function testSupportedSdkReadUsesItsTypedResultAndExactArguments(): void
    {
        $client = new class
        {
            public array $arguments = [];

            public function workflowVersionUsage(...$arguments): object
            {
                $this->arguments = $arguments;

                return (object) ['raw' => VersionUsageFixture::page()];
            }
        };
        $controller = new RemoteVersionUsageController(new RemoteBackend($client));
        $response = $controller->show(Request::create($this->url(['cursor' => 'opaque'])));
        self::assertSame(200, $response->status());
        self::assertSame(['Orders', 'carrier-api', 1, null, 50, 'opaque'], $client->arguments);
        self::assertSame(VersionUsageFixture::page(), $response->getData(true));
    }

    public function testSdkAuthenticationAndTransportFailuresPropagateToTheExistingErrorRenderer(): void
    {
        foreach ([401, 403, 503] as $status) {
            $failure = new ServerException('Synthetic read failure', $status, 'synthetic_refusal');
            $client = new class($failure)
            {
                public function __construct(private readonly ServerException $failure)
                {
                }

                public function workflowVersionUsage(...$arguments): never
                {
                    throw $this->failure;
                }
            };
            $controller = new RemoteVersionUsageController(new RemoteBackend($client));
            try {
                $controller->show(Request::create($this->url()));
                self::fail('The SDK error must not become a successful page.');
            } catch (ServerException $actual) {
                self::assertSame($failure, $actual);
            }
        }
    }

    /** @param array<string, mixed> $overrides */
    private function url(array $overrides = []): string
    {
        return '/waterline/api/v2/workflow-version-usage?'.http_build_query(array_replace([
            'workflow_type' => 'Orders', 'change_id' => 'carrier-api', 'min_version' => 1,
        ], $overrides));
    }
}
