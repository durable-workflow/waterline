<?php

namespace Waterline\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Waterline\Tests\TestCase;

/**
 * Phase 0 Integration Test: Waterline ↔ Server Container
 *
 * This test validates that Waterline can successfully query and render
 * workflow data from a running durableworkflow/server container.
 *
 * Prerequisites:
 *   docker-compose -f docker-compose.integration.yml up -d
 *   docker-compose -f docker-compose.integration.yml ps  # verify healthy
 *
 * Run test:
 *   vendor/bin/phpunit tests/Feature/ServerIntegrationTest.php
 *
 * Cleanup:
 *   docker-compose -f docker-compose.integration.yml down -v
 */
class ServerIntegrationTest extends TestCase
{
    private const SERVER_URL = 'http://localhost:8081';
    private const AUTH_TOKEN = 'integration-test-token-123';

    protected function setUp(): void
    {
        // Skip database migrations - we'll use the server's database
        $this->afterApplicationCreated(function () {
            // Override database config to point to integration container
            config([
                'database.connections.mysql' => [
                    'driver' => 'mysql',
                    'host' => env('INTEGRATION_DB_HOST', 'localhost'),
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
            ]);

            // Force reconnect with new config
            DB::purge('mysql');
            DB::reconnect('mysql');
        });

        parent::setUp();

        $this->ensureServerIsHealthy();
    }

    protected function defineDatabaseMigrations()
    {
        // Intentionally empty - server container handles migrations
    }

    /**
     * Test that server container is healthy and responding
     */
    private function ensureServerIsHealthy(): void
    {
        $maxRetries = 30;
        $retryInterval = 1; // seconds

        for ($i = 0; $i < $maxRetries; $i++) {
            try {
                $response = Http::timeout(5)->get(self::SERVER_URL . '/api/health');

                if ($response->successful()) {
                    return;
                }
            } catch (\Exception $e) {
                // Connection failed, retry
            }

            if ($i === $maxRetries - 1) {
                $this->markTestSkipped(
                    'Server container is not healthy. Ensure docker-compose.integration.yml is running: ' .
                    'docker-compose -f docker-compose.integration.yml up -d'
                );
            }

            sleep($retryInterval);
        }
    }

    /**
     * @test
     * Phase 0: Waterline can query workflow runs from server container database
     */
    public function it_can_query_workflow_runs_from_server_database(): void
    {
        // Create a workflow run via server API
        $workflowId = 'integration-test-' . uniqid();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . self::AUTH_TOKEN,
        ])->post(self::SERVER_URL . '/api/v2/namespaces/default/workflows/start', [
            'workflow_type' => 'TestWorkflow',
            'workflow_id' => $workflowId,
            'task_queue' => 'integration-test',
            'input' => ['test' => 'data'],
        ]);

        $this->assertTrue(
            $response->successful(),
            'Failed to create workflow via server API: ' . $response->body()
        );

        $runId = $response->json('run_id');
        $this->assertNotEmpty($runId, 'Server did not return run_id');

        // Wait a moment for data to be written
        sleep(1);

        // Query workflow run directly from database (what Waterline does)
        $workflowRun = DB::connection('mysql')
            ->table('workflow_runs')
            ->where('run_id', $runId)
            ->first();

        $this->assertNotNull($workflowRun, 'Waterline could not query workflow run from database');
        $this->assertEquals('default', $workflowRun->namespace);
        $this->assertEquals($workflowId, $workflowRun->workflow_id);
        $this->assertEquals('TestWorkflow', $workflowRun->workflow_type);
    }

    /**
     * @test
     * Phase 0: Waterline can query workflow history from server container database
     */
    public function it_can_query_workflow_history_from_server_database(): void
    {
        // Create a workflow run via server API
        $workflowId = 'integration-history-test-' . uniqid();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . self::AUTH_TOKEN,
        ])->post(self::SERVER_URL . '/api/v2/namespaces/default/workflows/start', [
            'workflow_type' => 'TestHistoryWorkflow',
            'workflow_id' => $workflowId,
            'task_queue' => 'integration-test',
            'input' => ['history' => 'test'],
        ]);

        $this->assertTrue($response->successful(), 'Failed to create workflow: ' . $response->body());
        $runId = $response->json('run_id');

        // Wait for workflow to be created
        sleep(1);

        // Query workflow history (what Waterline timeline does)
        $historyEvents = DB::connection('mysql')
            ->table('workflow_history_events')
            ->where('run_id', $runId)
            ->orderBy('event_id', 'asc')
            ->get();

        $this->assertGreaterThan(0, $historyEvents->count(), 'Waterline could not query history events');

        // Should have at least WorkflowExecutionStarted event
        $startEvent = $historyEvents->first();
        $this->assertEquals('WorkflowExecutionStarted', $startEvent->event_type);

        // Verify event attributes are accessible (what TimelineEventRenderer needs)
        $this->assertNotNull($startEvent->attributes);
        $attributes = json_decode($startEvent->attributes, true);
        $this->assertIsArray($attributes, 'Event attributes should be JSON');
    }

    /**
     * @test
     * Phase 0: Waterline can render workflow run detail view
     */
    public function it_can_render_workflow_run_detail_view(): void
    {
        // Create a workflow run via server API
        $workflowId = 'integration-render-test-' . uniqid();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . self::AUTH_TOKEN,
        ])->post(self::SERVER_URL . '/api/v2/namespaces/default/workflows/start', [
            'workflow_type' => 'TestRenderWorkflow',
            'workflow_id' => $workflowId,
            'task_queue' => 'integration-test',
            'input' => ['render' => 'test'],
        ]);

        $this->assertTrue($response->successful());
        $runId = $response->json('run_id');
        sleep(1);

        // Use Waterline's controller to fetch run detail
        $this->withoutExceptionHandling();

        $response = $this->get("/waterline/v2/flow/{$runId}");

        // Should successfully render (200 OK)
        $response->assertStatus(200);

        // Should have workflow run data in the view
        $response->assertSee($workflowId);
        $response->assertSee('TestRenderWorkflow');
        $response->assertSee($runId);
    }

    /**
     * @test
     * Phase 0: Waterline can list workflow runs
     */
    public function it_can_list_workflow_runs(): void
    {
        // Create multiple workflow runs via server API
        $workflowIds = [];
        for ($i = 0; $i < 3; $i++) {
            $workflowId = 'integration-list-test-' . $i . '-' . uniqid();
            $workflowIds[] = $workflowId;

            Http::withHeaders([
                'Authorization' => 'Bearer ' . self::AUTH_TOKEN,
            ])->post(self::SERVER_URL . '/api/v2/namespaces/default/workflows/start', [
                'workflow_type' => 'TestListWorkflow',
                'workflow_id' => $workflowId,
                'task_queue' => 'integration-test',
                'input' => ['index' => $i],
            ]);
        }

        sleep(2); // Wait for all workflows to be created

        // Use Waterline's controller to list runs
        $response = $this->get('/waterline/v2');

        $response->assertStatus(200);

        // Should see at least some of our created workflows
        foreach ($workflowIds as $workflowId) {
            $response->assertSee($workflowId);
        }
    }

    /**
     * @test
     * Phase 0: Waterline can query workflow tasks (activities)
     */
    public function it_can_query_workflow_tasks_from_server_database(): void
    {
        // For this test, we just verify the tables exist and are queryable
        // A real workflow with activities would require a worker to be running

        // Verify workflow_tasks table exists and is queryable
        $taskCount = DB::connection('mysql')
            ->table('workflow_tasks')
            ->count();

        $this->assertIsInt($taskCount);

        // Verify we can query task structure (what Waterline activities view needs)
        $columns = DB::connection('mysql')
            ->select("SHOW COLUMNS FROM workflow_tasks");

        $columnNames = array_column($columns, 'Field');

        // Verify essential columns exist for Waterline to render activities
        $this->assertContains('id', $columnNames);
        $this->assertContains('run_id', $columnNames);
        $this->assertContains('activity_id', $columnNames);
        $this->assertContains('state', $columnNames);
        $this->assertContains('input', $columnNames);
        $this->assertContains('result', $columnNames);
    }
}
