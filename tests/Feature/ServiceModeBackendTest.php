<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use DurableWorkflow\Exception\ServerException;
use DurableWorkflow\Exception\TransportException;
use DurableWorkflow\Waterline\CI\SqlServerQualificationTls;
use Orchestra\Testbench\TestCase;
use function Orchestra\Testbench\artisan;
use Waterline\Support\Remote\RemoteBackend;
use Waterline\Tests\Fixtures\FakeRemoteClient;
use Waterline\Waterline;
use Waterline\WaterlineServiceProvider;

require_once dirname(__DIR__, 2).'/scripts/ci/SqlServerQualificationTls.php';

final class ServiceModeBackendTest extends TestCase
{
    private FakeRemoteClient $client;

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

        Waterline::auth(static fn (): bool => true);
        $this->client = new FakeRemoteClient();
        $this->app->instance(RemoteBackend::class, new RemoteBackend($this->client));
    }

    public function testWorkflowListAndSelectedRunUseTheSharedWaterlineContract(): void
    {
        $this->getJson('/waterline/api/flows/running')
            ->assertOk()
            ->assertJsonPath('data.0.instance_id', 'order-1')
            ->assertJsonPath('data.0.run_id', 'run-1')
            ->assertJsonPath('data.0.engine_source', 'service')
            ->assertJsonPath('operator_scope.namespace', 'orders')
            ->assertJsonPath('backend.transport', 'durable-workflow/sdk');

        $this->getJson('/waterline/api/instances/order-1/runs/run-1')
            ->assertOk()
            ->assertJsonPath('instance_id', 'order-1')
            ->assertJsonPath('selected_run_id', 'run-1')
            ->assertJsonPath('timeline.0.event_type', 'WorkflowStarted')
            ->assertJsonPath('run_navigation.0.is_selected_run', true)
            ->assertJsonPath('actionability.actions.query.allowed', true)
            ->assertJsonPath('actionability.actions.signal.allowed', false)
            ->assertJsonPath('actionability.actions.signal.reason', 'waterline_read_only')
            ->assertJsonPath('read_only', true);
    }

    public function testWorkersQueuesHealthMetricsAndSchedulesUseRemoteContracts(): void
    {
        $this->getJson('/waterline/api/v2/health')
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonCount(1, 'operator_metrics.workers.registrations')
            ->assertJsonPath('operator_metrics.workers.registrations.0.worker_id', 'worker-1')
            ->assertJsonCount(1, 'operator_metrics.workers.stale_registrations')
            ->assertJsonPath('operator_metrics.workers.stale_registrations.0.worker_id', 'worker-stale')
            ->assertJsonPath('operator_metrics.workers.registration_count', 2)
            ->assertJsonPath('operator_metrics.workers.active_registration_count', 1)
            ->assertJsonPath('operator_metrics.workers.stale_registration_count', 1)
            ->assertJsonPath('queue_visibility.task_queues.0.name', 'orders');

        $this->getJson('/waterline/api/stats')
            ->assertOk()
            ->assertJsonPath('operator_metrics.runs.total', 1)
            ->assertJsonPath('flows', 1)
            ->assertJsonPath('fleet_overview.current.running', 1)
            ->assertJsonPath('engine_source.resolved', 'service');

        $this->getJson('/waterline/api/v2/schedules')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'nightly-orders')
            ->assertJsonPath('data.0.workflow_type', 'orders.process');
    }

    public function testWorkerRegistrationRostersAreDisjointForEveryFleetShape(): void
    {
        $active = [[
            'worker_id' => 'worker-active',
            'namespace' => 'orders',
            'task_queue' => 'orders',
            'status' => 'active',
            'current_leases' => 2,
        ]];
        $stale = [[
            'worker_id' => 'worker-stale',
            'namespace' => 'orders',
            'task_queue' => 'orders',
            'status' => 'stale',
            'current_leases' => 13,
        ]];
        $scenarios = [
            'active-only' => [$active, []],
            'stale-only' => [[], $stale],
            'mixed' => [$active, $stale],
        ];

        foreach ($scenarios as $name => [$activeRows, $staleRows]) {
            // The unfiltered service response contains every registration,
            // including the rows returned again by the stale-only query.
            $this->client->activeWorkerRows = array_merge($activeRows, $staleRows);
            $this->client->staleWorkerRows = $staleRows;

            $workers = $this->getJson('/waterline/api/v2/health')
                ->assertOk()
                ->json('operator_metrics.workers');

            $this->assertIsArray($workers, $name);
            $this->assertSame(
                array_column($activeRows, 'worker_id'),
                array_column($workers['registrations'] ?? [], 'worker_id'),
                $name,
            );
            $this->assertSame(
                array_column($staleRows, 'worker_id'),
                array_column($workers['stale_registrations'] ?? [], 'worker_id'),
                $name,
            );
            $this->assertSame(count($activeRows) + count($staleRows), $workers['registration_count'], $name);
            $this->assertSame(count($activeRows), $workers['active_registration_count'], $name);
            $this->assertSame(count($staleRows), $workers['stale_registration_count'], $name);
        }
    }

    public function testSavedViewsUseTheWaterlineContractWithoutLoadingTheEmbeddedEngine(): void
    {
        $this->getJson('/waterline/api/saved-views?bucket=terminated')
            ->assertOk()
            ->assertJsonPath('filter_version', 6)
            ->assertJsonPath('supported_filter_versions', [6])
            ->assertJsonPath('filter_definition.fields.instance_id.label', 'Instance ID')
            ->assertJsonPath('filter_definition.fields.instance_id.service_mode_available', true)
            ->assertJsonPath('filter_definition.fields.repair_state.label', 'Repair State')
            ->assertJsonPath('filter_definition.fields.repair_state.filterable', false)
            ->assertJsonPath('filter_definition.fields.repair_state.saved_view_compatible', false)
            ->assertJsonPath('filter_definition.labels.service_mode_available', false)
            ->assertJsonPath('filter_definition.search_attributes.service_mode_available', false)
            ->assertJsonPath('filter_definition.actionability.schema', 'waterline.actionability');
    }

    public function testSavedViewFiltersNarrowTheAuthoritativeRemoteWorkflowList(): void
    {
        $this->client->workflowRows = [
            [
                'workflow_id' => 'order-1',
                'run_id' => 'run-1',
                'workflow_type' => 'orders.process',
                'task_queue' => 'orders',
                'status' => 'running',
                'status_bucket' => 'running',
                'started_at' => '2026-07-22T12:00:00Z',
            ],
            [
                'workflow_id' => 'invoice-1',
                'run_id' => 'run-2',
                'workflow_type' => 'invoices.process',
                'task_queue' => 'billing',
                'status' => 'running',
                'status_bucket' => 'running',
                'started_at' => '2026-07-22T12:01:00Z',
            ],
        ];

        $created = $this->postJson('/waterline/api/saved-views', [
            'name' => 'Order workflows',
            'bucket' => 'running',
            'filters' => [
                'workflow_type' => 'orders.process',
            ],
            'shared' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('service_mode_available', true)
            ->json();

        $this->getJson('/waterline/api/flows/running?view='.$created['id'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.instance_id', 'order-1')
            ->assertJsonPath('visibility_filters.saved_view.id', $created['id'])
            ->assertJsonPath('visibility_filters.saved_view_applied', true)
            ->assertJsonPath('visibility_filters.applied.workflow_type', 'orders.process')
            ->assertJsonPath('visibility_filters.unavailable', [])
            ->assertJsonPath('visibility_filters.capability.fully_applied', true)
            ->assertJsonPath('visibility_filters.capability_warning', null);

        $listCall = collect($this->client->calls)->last(
            static fn (array $call): bool => $call['method'] === 'listWorkflows',
        );

        $this->assertSame('orders.process', $listCall['arguments']['workflowType']);
    }

    public function testUnavailableServiceModeFiltersAreRejectedOrReportedInsteadOfIgnored(): void
    {
        $this->postJson('/waterline/api/saved-views', [
            'name' => 'Repair blocked',
            'bucket' => 'running',
            'filters' => [
                'repair_state' => 'blocked',
            ],
            'shared' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.filters.0', 'The connected service cannot apply these workflow-list filters: repair_state.');

        $this->getJson('/waterline/api/flows/running?repair_state=blocked')
            ->assertOk()
            ->assertJsonPath('visibility_filters.applied', [])
            ->assertJsonPath('visibility_filters.unavailable.repair_state', 'blocked')
            ->assertJsonPath('visibility_filters.capability.fully_applied', false)
            ->assertJsonPath(
                'visibility_filters.capability_warning',
                'The connected service cannot apply these workflow-list filters: repair_state.',
            );
    }

    public function testUnhealthyRemoteHealthRetainsServerChecksAndStatus(): void
    {
        $this->client->failures['systemHealth'] = new ServerException(
            'The standalone server is unhealthy.',
            503,
            'health_check_failed',
            ['health' => [
                'status' => 'error',
                'healthy' => false,
                'checks' => [[
                    'name' => 'database',
                    'status' => 'error',
                    'message' => 'Database unavailable.',
                ]],
            ]],
        );

        $this->getJson('/waterline/api/v2/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('healthy', false)
            ->assertJsonPath('checks.0.name', 'database')
            ->assertJsonPath('checks.1.reason', 'health_check_failed');
    }

    public function testReadOnlyModeRefusesMutationsBeforeCallingTheServer(): void
    {
        $this->postJson('/waterline/api/instances/order-1/runs/run-1/signals/approve', [
            'arguments' => ['manager'],
        ])
            ->assertForbidden()
            ->assertJsonPath('reason', 'waterline_read_only')
            ->assertJsonPath('capability', 'signal');

        $this->postJson('/waterline/api/v2/schedules/nightly-orders/trigger')
            ->assertForbidden()
            ->assertJsonPath('reason', 'waterline_read_only');

        $methods = array_column($this->client->calls, 'method');
        $this->assertNotContains('signalWorkflow', $methods);
        $this->assertNotContains('triggerSchedule', $methods);
    }

    public function testOperatorModeEnablesSupportedCommands(): void
    {
        config()->set('waterline.service.access_mode', 'operator');

        $this->postJson('/waterline/api/instances/order-1/runs/run-1/signals/approve', [
            'arguments' => ['manager'],
        ])
            ->assertOk()
            ->assertJsonPath('command_status', 'accepted');

        $this->assertContains('signalWorkflow', array_column($this->client->calls, 'method'));
    }

    public function testRemoteAuthenticationAndAuthorizationFailuresStayExplicit(): void
    {
        $this->client->failure = new ServerException(
            'The server token is not authorized for this namespace.',
            403,
            'authorization_failed',
            ['required_role' => 'operator'],
        );

        $this->getJson('/waterline/api/flows/running')
            ->assertForbidden()
            ->assertJsonPath('error', 'remote_authorization_failed')
            ->assertJsonPath('reason', 'authorization_failed')
            ->assertJsonPath('remote_details.required_role', 'operator');
    }

    public function testUnavailableSdkCapabilitiesAreMachineReadable(): void
    {
        $this->app->instance(RemoteBackend::class, new RemoteBackend(new class {}));

        $this->getJson('/waterline/api/v2/health')
            ->assertStatus(501)
            ->assertJsonPath('reason', 'backend_capability_unavailable')
            ->assertJsonPath('capability', 'workers')
            ->assertJsonPath('required_sdk_method', 'listWorkers');
    }

    public function testUnsupportedProductCapabilitiesAreExplicitWithoutLoadingEmbeddedCode(): void
    {
        $this->getJson('/waterline/api/v2/services/endpoints')
            ->assertStatus(501)
            ->assertJsonPath('reason', 'backend_capability_unavailable')
            ->assertJsonPath('capability', 'services')
            ->assertJsonPath('backend.capabilities.services', false);
    }

    public function testTransportFailuresAreRenderedAsTypedRemoteErrors(): void
    {
        $this->client->failure = new TransportException('Connection refused.');

        $this->getJson('/waterline/api/flows/running')
            ->assertStatus(502)
            ->assertJsonPath('error', 'remote_transport_failure')
            ->assertJsonPath('remote_status', 502);
    }

    public function testDashboardPublishesBackendScopeAndAuthorizationState(): void
    {
        $content = $this->get('/waterline')
            ->assertOk()
            ->getContent();

        $bootstrap = $this->bootstrapConfig($content);

        $this->assertSame('service', $bootstrap['backend']['mode']);
        $this->assertSame('Standalone service', $bootstrap['backend']['label']);
        $this->assertSame('orders', $bootstrap['backend']['namespace']);
        $this->assertSame('read_only', $bootstrap['backend']['access_mode']);
        $this->assertTrue($bootstrap['backend']['read_only']);
        $this->assertSame('configured', $bootstrap['backend']['authentication']);
    }

    /**
     * @return array<string, mixed>
     */
    private function bootstrapConfig(string|false $content): array
    {
        $this->assertIsString($content);
        $this->assertMatchesRegularExpression('/data-waterline-config="[^"]+"/', $content);

        preg_match('/data-waterline-config="([^"]+)"/', $content, $matches);

        return json_decode(
            html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
