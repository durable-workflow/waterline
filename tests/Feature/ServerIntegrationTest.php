<?php

namespace Waterline\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Waterline\Tests\TestCase;

/**
 * Phase 0 Integration Test: Waterline <-> Server Container
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
            config([
                'database.default' => 'mysql',
                'database.connections.mysql' => [
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
                ],
                'waterline.engine_source' => 'v2',
                'waterline.namespace' => self::NAMESPACE,
            ]);

            DB::purge('mysql');
            DB::reconnect('mysql');
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

    private function ensureServerIsHealthy(): void
    {
        if (self::$serverHealthy === true) {
            return;
        }

        if (self::$serverHealthy === false) {
            $this->markTestSkipped($this->serverUnavailableMessage());
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

        $this->markTestSkipped($this->serverUnavailableMessage());
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

    private function serverUnavailableMessage(): string
    {
        return 'Server container is not healthy. See INTEGRATION_TEST_README.md for the pinned docker compose startup command.';
    }
}
