<?php

namespace Waterline\Tests\Feature;

use Composer\InstalledVersions;
use DurableWorkflow\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Waterline\Http\Controllers\Remote\RemoteCapacityEvidenceController;
use Waterline\Support\Remote\RemoteBackend;
use Waterline\Support\ServiceModeRequirements;
use Waterline\Tests\TestCase;

/**
 * Waterline service integration test against a Server container.
 *
 * This test validates that Waterline can query and render workflow data from a
 * running durableworkflow/server container using the current v2 HTTP and table
 * contracts.
 *
 * Prerequisites:
 *   git -C ../workflow fetch origin v2
 *   WORKFLOW_PACKAGE_COMMIT="$(git -C ../workflow rev-parse origin/v2)" \
 *     docker compose -f docker-compose.integration.yml up -d --build
 *   docker compose -f docker-compose.integration.yml ps  # verify healthy
 *
 * Run test:
 *   vendor/bin/phpunit tests/Feature/ServerIntegrationTest.php
 *
 * The focused service-capacity tuple gate is self-contained:
 *   composer test-service-capacity-tuple
 *
 * Cleanup:
 *   docker compose -f docker-compose.integration.yml down -v
 */
class ServerIntegrationTest extends TestCase
{
    private const DEFAULT_SERVER_URL = 'http://127.0.0.1:8081';
    private const AUTH_TOKEN = 'integration-test-token-123';
    private const NAMESPACE = 'default';

    private static ?bool $serverHealthy = null;

    protected function setUp(): void
    {
        $this->afterApplicationCreated(function (): void {
            $connection = strtolower((string) env('INTEGRATION_DB_CONNECTION', 'mysql'));
            $database = $connection === 'sqlite'
                ? [
                    'driver' => 'sqlite',
                    'database' => env('INTEGRATION_DB_DATABASE'),
                    'prefix' => '',
                    'foreign_key_constraints' => true,
                    'busy_timeout' => 5000,
                ]
                : [
                    'driver' => 'mysql',
                    'host' => env('INTEGRATION_DB_HOST', '127.0.0.1'),
                    'port' => env('INTEGRATION_DB_PORT', '33066'),
                    'database' => env('INTEGRATION_DB_DATABASE', 'durable_workflow'),
                    'username' => env('INTEGRATION_DB_USERNAME', 'workflow'),
                    'password' => env('INTEGRATION_DB_PASSWORD', 'workflow'),
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                    'prefix' => '',
                    'strict' => true,
                    'engine' => null,
                ];

            config([
                'database.default' => $connection,
                "database.connections.{$connection}" => $database,
                'waterline.engine_source' => 'v2',
                'waterline.namespace' => self::NAMESPACE,
            ]);

            DB::purge($connection);
            DB::reconnect($connection);
        });

        parent::setUp();

        $this->ensureServerIsHealthy();
        $this->ensureDefaultNamespaceExists();
    }

    protected function defineDatabaseMigrations()
    {
        // Intentionally empty: the server container owns the schema.
    }

    public function test_it_can_query_workflow_runs_from_server_database(): void
    {
        $workflowId = 'integration-query-runs-'.uniqid();
        $start = $this->startWorkflow('integration.remote.query-runs', $workflowId, ['test' => 'data']);
        $runId = $start['run_id'];

        $workflowRun = DB::table('workflow_runs')
            ->where('id', $runId)
            ->first();

        $this->assertNotNull($workflowRun, 'Waterline could not query workflow run from database.');
        $this->assertSame(self::NAMESPACE, $workflowRun->namespace);
        $this->assertSame($workflowId, $workflowRun->workflow_instance_id);
        $this->assertSame('integration.remote.query-runs', $workflowRun->workflow_type);
        $this->assertSame('integration-test', $workflowRun->queue);
        $this->assertContains($workflowRun->status, ['pending', 'waiting', 'running']);
    }

    public function test_it_can_query_workflow_history_from_server_database(): void
    {
        $workflowId = 'integration-history-'.uniqid();
        $start = $this->startWorkflow('integration.remote.history', $workflowId, ['history' => 'test']);
        $runId = $start['run_id'];

        $historyEvents = DB::table('workflow_history_events')
            ->where('workflow_run_id', $runId)
            ->orderBy('sequence')
            ->get();

        $this->assertGreaterThan(0, $historyEvents->count(), 'Waterline could not query history events.');

        $eventTypes = $historyEvents->pluck('event_type')->all();

        $this->assertContains('StartAccepted', $eventTypes);
        $this->assertContains('WorkflowStarted', $eventTypes);

        $firstPayload = $this->decodeJsonColumn($historyEvents->first()->payload ?? null);
        $this->assertIsArray($firstPayload, 'History event payload should be JSON-decodable.');
    }

    public function test_it_can_render_workflow_run_detail_from_server_database(): void
    {
        $workflowId = 'integration-render-'.uniqid();
        $start = $this->startWorkflow('integration.remote.render', $workflowId, ['render' => 'test']);
        $runId = $start['run_id'];

        $response = $this->getJson("/waterline/api/instances/{$workflowId}/runs/{$runId}");

        $response->assertOk()
            ->assertJsonPath('id', $runId)
            ->assertJsonPath('instance_id', $workflowId)
            ->assertJsonPath('selected_run_id', $runId)
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('workflow_type', 'integration.remote.render')
            ->assertJsonPath('queue', 'integration-test');
    }

    public function test_it_can_list_workflow_runs_from_server_database(): void
    {
        $workflowType = 'integration.remote.list';
        $workflowIds = [];

        for ($i = 0; $i < 3; $i++) {
            $workflowId = 'integration-list-'.$i.'-'.uniqid();
            $workflowIds[] = $workflowId;

            $this->startWorkflow($workflowType, $workflowId, ['index' => $i]);
        }

        $response = $this->getJson('/waterline/api/flows/running?workflow_type='.$workflowType);

        $response->assertOk();

        $listedWorkflowIds = collect($response->json('data'))->pluck('instance_id')->all();

        foreach ($workflowIds as $workflowId) {
            $this->assertContains($workflowId, $listedWorkflowIds);
        }
    }

    public function test_it_can_query_workflow_tasks_from_server_database(): void
    {
        $workflowId = 'integration-tasks-'.uniqid();
        $start = $this->startWorkflow('integration.remote.tasks', $workflowId, ['tasks' => 'test']);
        $runId = $start['run_id'];

        $taskCount = DB::table('workflow_tasks')
            ->where('workflow_run_id', $runId)
            ->count();

        $this->assertGreaterThan(0, $taskCount);

        $columns = DB::select('SHOW COLUMNS FROM workflow_tasks');
        $columnNames = array_column($columns, 'Field');

        $this->assertContains('id', $columnNames);
        $this->assertContains('workflow_run_id', $columnNames);
        $this->assertContains('namespace', $columnNames);
        $this->assertContains('task_type', $columnNames);
        $this->assertContains('status', $columnNames);
        $this->assertContains('payload', $columnNames);
        $this->assertContains('queue', $columnNames);
        $this->assertContains('available_at', $columnNames);
    }

    public function test_service_capacity_evidence_flows_through_the_declared_release_tuple(): void
    {
        Carbon::setTestNow();
        config()->set('waterline.backend', 'service');
        config()->set('waterline.service.endpoint', $this->serverUrl());
        config()->set('waterline.service.token', self::AUTH_TOKEN);
        config()->set('waterline.service.namespace', self::NAMESPACE);
        config()->set('waterline.capacity_evidence.allowed_window_seconds', [300]);
        config()->set('waterline.capacity_evidence.default_window_seconds', 300);

        $tuple = $this->serviceCapacityEvidenceTuple();
        $serverManifest = $this->serverSourceManifest();
        $this->assertSame(
            $tuple['server'],
            $serverManifest['extra']['durable-workflow']['product-train'] ?? null,
        );

        $waterlineManifest = $this->manifest(dirname(__DIR__, 2).'/composer.json');
        $currentSdk = $waterlineManifest['require-dev']['durable-workflow/sdk'] ?? null;
        $this->assertIsString($currentSdk);
        $installedSdk = InstalledVersions::getPrettyVersion('durable-workflow/sdk');
        $this->assertSame(ServiceModeRequirements::SDK_VERSION, $installedSdk);
        $this->assertSame($currentSdk, $installedSdk);
        $this->assertGreaterThanOrEqual(
            0,
            version_compare($installedSdk, $tuple['sdk-php']),
            'Current PHP SDK must not precede the first supported service-capacity tuple.',
        );

        $currentWaterline = $waterlineManifest['extra']['durable-workflow']['product-train'] ?? null;
        $this->assertIsString($currentWaterline);
        $this->assertGreaterThanOrEqual(
            0,
            version_compare($currentWaterline, $tuple['waterline']),
            'Current Waterline source must not precede the first supported service-capacity tuple.',
        );

        $client = new Client(
            $this->serverUrl(),
            namespace: self::NAMESPACE,
            controlToken: self::AUTH_TOKEN,
        );
        $this->assertSame($tuple['server'], $client->clusterInfo()->version);

        $controller = new RemoteCapacityEvidenceController(new RemoteBackend($client));
        $response = $controller->show(Request::create(
            '/waterline/api/v2/capacity-evidence',
            'GET',
            ['window_seconds' => 300],
        ));
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('waterline.namespace_capacity_evidence', $payload['schema'] ?? null);
        $this->assertSame('service', $payload['transport'] ?? null);
        $this->assertSame(self::NAMESPACE, $payload['scope']['namespace'] ?? null);
        $this->assertSame(300, $payload['observation_window']['duration_seconds'] ?? null);
        $this->assertSame(
            300,
            (int) Carbon::parse($payload['observation_window']['starts_at'])
                ->diffInSeconds(Carbon::parse($payload['observation_window']['ends_at'])),
        );

        foreach ([
            'throughput' => [
                'workflow_starts',
                'workflow_completions',
                'activity_dispatches',
                'activity_completions',
                'timers_scheduled',
                'timers_fired',
                'signals',
                'queries',
                'updates',
            ],
            'latency' => ['schedule_to_start', 'execution', 'replay', 'inspection'],
            'growth' => ['history_events', 'history_payload_bytes', 'durable_payload_bytes'],
            'reliability' => ['retries', 'timeouts', 'failures', 'stale_heartbeats', 'overload_or_throttling'],
        ] as $category => $dimensions) {
            foreach ($dimensions as $dimension) {
                $this->assertIsArray($payload['runtime_evidence'][$category][$dimension] ?? null);
            }
        }

        $this->assertFalse($payload['recommendation_input']['advisory']['automatic_plan_change'] ?? true);
        $this->assertFalse($payload['recommendation_input']['advisory']['automatic_billing_change'] ?? true);
        $this->assertFalse($payload['recommendation_input']['advisory']['automatic_infrastructure_change'] ?? true);
        $this->assertTrue($payload['commercial_boundary']['diagnostic_and_advisory_only'] ?? false);
        $this->assertFalse($payload['commercial_boundary']['automatic_plan_change'] ?? true);
        $this->assertFalse($payload['commercial_boundary']['automatic_billing_change'] ?? true);
        $this->assertFalse($payload['commercial_boundary']['automatic_infrastructure_change'] ?? true);
        $this->assertFalse($payload['cardinality']['individual_execution_identifiers_included'] ?? true);

        $keys = [];
        array_walk_recursive($payload, static function (mixed $_value, string|int $key) use (&$keys): void {
            $keys[] = $key;
        });
        $this->assertSame([], array_values(array_intersect(
            ['workflow_id', 'run_id', 'task_id', 'worker_id'],
            $keys,
        )));
    }

    private function ensureServerIsHealthy(): void
    {
        if (self::$serverHealthy === true) {
            return;
        }

        if (self::$serverHealthy === false) {
            $this->handleUnavailableServer();
        }

        $maxRetries = 30;

        for ($i = 0; $i < $maxRetries; $i++) {
            try {
                $response = $this->serverRequest('GET', '/api/health');

                if ($this->isSuccessful($response['status'])) {
                    self::$serverHealthy = true;

                    return;
                }
            } catch (\Throwable) {
                // Connection failed; retry until the integration stack is ready.
            }

            sleep(1);
        }

        self::$serverHealthy = false;

        $this->handleUnavailableServer();
    }

    private function ensureDefaultNamespaceExists(): void
    {
        $show = $this->serverRequest('GET', '/api/namespaces/'.self::NAMESPACE, $this->controlPlaneHeaders());

        if ($this->isSuccessful($show['status'])) {
            return;
        }

        $create = $this->serverRequest(
            'POST',
            '/api/namespaces',
            $this->controlPlaneHeaders(),
            [
                'name' => self::NAMESPACE,
                'description' => 'Default integration namespace',
                'retention_days' => 30,
            ],
        );

        $this->assertTrue(
            $this->isSuccessful($create['status']) || $create['status'] === 409,
            'Unable to ensure default namespace exists: '.$create['body'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function startWorkflow(string $workflowType, string $workflowId, array $input): array
    {
        $response = $this->serverRequest(
            'POST',
            '/api/workflows',
            $this->controlPlaneHeaders(),
            [
                'workflow_type' => $workflowType,
                'workflow_id' => $workflowId,
                'task_queue' => 'integration-test',
                'input' => $input,
            ],
        );

        $this->assertTrue(
            $response['status'] === 201,
            'Failed to create workflow via server API: '.$response['body'],
        );

        $payload = $response['json'];

        $this->assertIsArray($payload);
        $this->assertSame($workflowId, $payload['workflow_id'] ?? null);
        $this->assertIsString($payload['run_id'] ?? null);

        return $payload;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|null  $body
     * @return array{status: int, body: string, json: mixed}
     */
    private function serverRequest(string $method, string $path, array $headers = [], ?array $body = null): array
    {
        $headerLines = [];

        foreach ($headers as $name => $value) {
            $headerLines[] = $name.': '.$value;
        }

        $options = [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'ignore_errors' => true,
            'timeout' => 5,
        ];

        if ($body !== null) {
            $encoded = json_encode($body, JSON_THROW_ON_ERROR);
            $options['content'] = $encoded;
            $options['header'] = trim($options['header']."\r\nContent-Type: application/json\r\nContent-Length: ".strlen($encoded));
        }

        $responseBody = @file_get_contents(
            $this->serverUrl().$path,
            false,
            stream_context_create(['http' => $options]),
        );
        $responseHeaders = $http_response_header ?? [];

        if ($responseBody === false && $responseHeaders === []) {
            throw new \RuntimeException('No response from server.');
        }

        $responseBody = is_string($responseBody) ? $responseBody : '';

        return [
            'status' => $this->statusFromHeaders($responseHeaders),
            'body' => $responseBody,
            'json' => $responseBody === '' ? null : json_decode($responseBody, true),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function controlPlaneHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.self::AUTH_TOKEN,
            'X-Durable-Workflow-Control-Plane-Version' => '2',
            'X-Namespace' => self::NAMESPACE,
        ];
    }

    private function decodeJsonColumn(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        return json_decode($value, true);
    }

    /**
     * @param  array<int, string>  $headers
     */
    private function statusFromHeaders(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return 0;
    }

    private function isSuccessful(int $status): bool
    {
        return $status >= 200 && $status < 300;
    }

    private function serverUrl(): string
    {
        return rtrim((string) env('INTEGRATION_SERVER_URL', self::DEFAULT_SERVER_URL), '/');
    }

    /** @return array{server: string, sdk-php: string, waterline: string} */
    private function serviceCapacityEvidenceTuple(): array
    {
        $manifest = $this->manifest(dirname(__DIR__, 2).'/composer.json');
        $tuple = $manifest['extra']['durable-workflow']['service-capacity-evidence']['first-supported-tuple'] ?? null;

        $this->assertIsArray($tuple);

        return $tuple;
    }

    /** @return array<string, mixed> */
    private function serverSourceManifest(): array
    {
        $configured = env('INTEGRATION_SERVER_SOURCE_PATH');
        $root = is_string($configured) && trim($configured) !== ''
            ? $configured
            : dirname(dirname(__DIR__, 2)).'/server';

        return $this->manifest(rtrim($root, '/').'/composer.json');
    }

    /** @return array<string, mixed> */
    private function manifest(string $path): array
    {
        $contents = file_get_contents($path);
        $this->assertIsString($contents, 'Unable to read release manifest at '.$path);

        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }

    private function serverUnavailableMessage(): string
    {
        return 'Server is not healthy. Run composer test-service-capacity-tuple for the self-contained SQLite gate.';
    }

    private function handleUnavailableServer(): void
    {
        if (filter_var(env('INTEGRATION_SERVER_REQUIRED', false), FILTER_VALIDATE_BOOL)) {
            $this->fail($this->serverUnavailableMessage());
        }

        $this->markTestSkipped($this->serverUnavailableMessage());
    }
}
