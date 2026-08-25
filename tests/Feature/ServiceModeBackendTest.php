<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use Composer\InstalledVersions;
use DurableWorkflow\Client;
use DurableWorkflow\Exception\ServerException;
use DurableWorkflow\Exception\TransportException;
use DurableWorkflow\Transport\Transport;
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

final class ServiceModeBackendTest extends TestCase
{
    /** @var array<string, mixed> */
    private static array $installedVersions;

    private FakeRemoteClient $client;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$installedVersions = InstalledVersions::getRawData();
        $planned = self::$installedVersions;
        $planned['versions']['durable-workflow/sdk']['pretty_version'] = ServiceModeRequirements::SDK_VERSION;
        $planned['versions']['durable-workflow/sdk']['version'] = '2.0.0.0-RC45';
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
            ->assertJsonPath('workflow_streams_mode', 'service')
            ->assertJsonPath('workflow_streams.0.stream_name', 'receipts')
            ->assertJsonPath('workflow_streams.0.status', 'errored')
            ->assertJsonPath('workflow_streams.0.last_offset', 4)
            ->assertJsonPath('workflow_streams.0.pending_items', 2)
            ->assertJsonPath('workflow_streams.0.error_reason', 'producer_failed')
            ->assertJsonPath('workflow_streams.0.supports_inbound_workflow_messaging', false)
            ->assertJsonPath('workflow_streams_available', true)
            ->assertJsonPath('workflow_streams_unavailable_reason', null)
            ->assertJsonPath('backend.capabilities.workflow_streams', true)
            ->assertJsonPath('read_only', true);
    }

    public function testSelectedRunPreservesDetailWhenTheRemoteWorkflowStreamsRouteIsUnsupported(): void
    {
        $this->client->failures['listWorkflowStreams'] = new ServerException(
            'The requested route could not be found.',
            404,
            'not_found',
        );

        $this->getJson('/waterline/api/instances/order-1/runs/run-1')
            ->assertOk()
            ->assertJsonPath('instance_id', 'order-1')
            ->assertJsonPath('selected_run_id', 'run-1')
            ->assertJsonPath('status', 'running')
            ->assertJsonPath('timeline.0.event_type', 'WorkflowStarted')
            ->assertJsonPath('run_navigation.0.is_selected_run', true)
            ->assertJsonPath('tasks.0.id', 'task-1')
            ->assertJsonPath('workflow_streams', [])
            ->assertJsonPath('workflow_streams_mode', 'service')
            ->assertJsonPath('workflow_streams_available', false)
            ->assertJsonPath('workflow_streams_unavailable_reason', 'workflow_streams_route_unsupported')
            ->assertJsonPath('backend.capabilities.workflow_streams', false);
    }

    public function testSelectedRunPreservesWorkflowStreamsAuthorizationFailures(): void
    {
        $this->client->failures['listWorkflowStreams'] = new ServerException(
            'The server token is not authorized to inspect Workflow Streams.',
            403,
            'authorization_failed',
            ['required_role' => 'operator'],
        );

        $this->getJson('/waterline/api/instances/order-1/runs/run-1')
            ->assertForbidden()
            ->assertJsonPath('error', 'remote_authorization_failed')
            ->assertJsonPath('reason', 'authorization_failed')
            ->assertJsonPath('remote_details.required_role', 'operator');
    }

    public function testSelectedRunPreservesWorkflowStreamsNamespaceFailures(): void
    {
        $this->client->failures['listWorkflowStreams'] = new ServerException(
            "Namespace 'orders' does not exist.",
            404,
            'namespace_not_found',
            ['namespace' => 'orders'],
        );

        $this->getJson('/waterline/api/instances/order-1/runs/run-1')
            ->assertNotFound()
            ->assertJsonPath('reason', 'namespace_not_found')
            ->assertJsonPath('remote_details.namespace', 'orders');
    }

    public function testSelectedRunPreservesWorkflowStreamsTransportFailures(): void
    {
        $this->client->failures['listWorkflowStreams'] = new TransportException('Connection reset.');

        $this->getJson('/waterline/api/instances/order-1/runs/run-1')
            ->assertStatus(502)
            ->assertJsonPath('error', 'remote_transport_failure')
            ->assertJsonPath('remote_status', 502);
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

    public function testCapacityEvidenceUsesTheOfficialRemoteMetricsContractWithoutLeakingExecutionIds(): void
    {
        config()->set('waterline.capacity_evidence.allowed_window_seconds', [300, 3600]);
        config()->set('waterline.capacity_evidence.default_window_seconds', 3600);
        config()->set('waterline.capacity_evidence.plan', [
            'version' => 'cloud-test-v1',
            'limits' => ['workflow_starts_per_second' => 1],
        ]);
        $capacityEvidence = $this->serverCapacityEvidence([
            300 => $this->serverCapacityWindow(300, 12, 8),
            3600 => $this->serverCapacityWindow(3600, 44, 21),
        ]);
        $transport = new class($capacityEvidence) implements Transport
        {
            /** @var list<array<string, mixed>> */
            public array $requests = [];

            /** @param array<string, mixed> $capacityEvidence */
            public function __construct(private readonly array $capacityEvidence)
            {
            }

            public function send(string $method, string $uri, array $headers, ?array $body = null): ?array
            {
                $this->requests[] = compact('method', 'uri', 'headers', 'body');

                return [
                    'namespace' => 'orders',
                    'operator_metrics' => [
                        'runs' => ['total' => 1, 'running' => 1, 'failed' => 0],
                        'capacity_evidence' => $this->capacityEvidence,
                    ],
                ];
            }
        };
        $sdkClient = new Client(
            'https://server.example',
            namespace: 'orders',
            transport: $transport,
            controlToken: 'secret',
        );
        $this->app->instance(RemoteBackend::class, new RemoteBackend($sdkClient));

        $response = $this->getJson('/waterline/api/v2/capacity-evidence?window_seconds=300')
            ->assertOk()
            ->assertJsonPath('schema', 'waterline.namespace_capacity_evidence')
            ->assertJsonPath('generated_at', '2026-08-12T12:00:00.000000Z')
            ->assertJsonPath('freshness.strategy', 'namespace_snapshot_cache')
            ->assertJsonPath('freshness.max_age_seconds', 30)
            ->assertJsonPath('freshness.valid_until', '2026-08-12T12:00:30.000000Z')
            ->assertJsonPath('transport', 'service')
            ->assertJsonPath('scope.namespace', 'orders')
            ->assertJsonPath('observation_window.starts_at', '2026-08-12T11:55:00.000000Z')
            ->assertJsonPath('observation_window.ends_at', '2026-08-12T12:00:00.000000Z')
            ->assertJsonPath('observation_window.duration_seconds', 300)
            ->assertJsonPath('runtime_evidence.throughput.workflow_starts.value', 12)
            ->assertJsonPath('runtime_evidence.throughput.queries.value', 8)
            ->assertJsonPath('runtime_evidence.throughput.activity_dispatches.value', 7)
            ->assertJsonPath('runtime_evidence.concurrency.open_workflows.value', 1)
            ->assertJsonPath('recommendation_input.decision_guardrails.observation_windows_available', 2)
            ->assertJsonPath('recommendation_input.decision_guardrails.sustained_windows_observed', 2)
            ->assertJsonPath('recommendation_input.observation_window.duration_seconds', 300)
            ->assertJsonPath('commercial_boundary.automatic_plan_change', false)
            ->assertJsonPath('commercial_boundary.automatic_billing_change', false)
            ->assertJsonPath('commercial_boundary.automatic_infrastructure_change', false)
            ->assertJsonPath('backend.capabilities.capacity_evidence', true);

        $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('remote-workflow-id', $encoded);
        $this->assertStringNotContainsString('remote-run-id', $encoded);
        $this->assertStringNotContainsString('remote-task-id', $encoded);
        $this->assertStringNotContainsString('remote-worker-id', $encoded);

        $keys = [];
        $payload = $response->json();
        array_walk_recursive($payload, static function (mixed $_value, string|int $key) use (&$keys): void {
            $keys[] = $key;
        });
        $this->assertSame([], array_values(array_intersect(
            ['workflow_id', 'run_id', 'task_id', 'worker_id'],
            $keys,
        )));

        $this->getJson('/waterline/api/v2/capacity-evidence?window_seconds=3600')
            ->assertOk()
            ->assertJsonPath('observation_window.duration_seconds', 3600)
            ->assertJsonPath('runtime_evidence.throughput.workflow_starts.value', 44)
            ->assertJsonPath('runtime_evidence.throughput.queries.value', 21);

        $this->assertCount(1, $transport->requests);
        $this->assertSame('GET', $transport->requests[0]['method']);
        $this->assertSame('https://server.example/api/system/operator-metrics', $transport->requests[0]['uri']);
        $this->assertSame('orders', $transport->requests[0]['headers']['X-Namespace']);
    }

    public function testCapacityEvidenceRejectsExpiredServerEvidenceWithoutRestampingIt(): void
    {
        Carbon::setTestNow('2026-08-12T12:00:32Z');
        config()->set('waterline.capacity_evidence.allowed_window_seconds', [3600]);
        $this->client->capacityEvidence = $this->serverCapacityEvidence([
            3600 => $this->serverCapacityWindow(3600, 12, 8),
        ]);

        $this->getJson('/waterline/api/v2/capacity-evidence?window_seconds=3600')
            ->assertStatus(501)
            ->assertJsonPath('reason', 'capacity_evidence_contract_unavailable')
            ->assertJsonPath('contract_failure', 'capacity_evidence_expired')
            ->assertJsonPath('backend.capabilities.capacity_evidence', false)
            ->assertJsonMissingPath('generated_at')
            ->assertJsonMissingPath('freshness')
            ->assertJsonMissingPath('runtime_evidence');
    }

    public function testCapacityEvidenceRejectsFutureDatedServerEvidenceWithoutRestampingIt(): void
    {
        Carbon::setTestNow('2026-08-12T11:59:58Z');
        config()->set('waterline.capacity_evidence.allowed_window_seconds', [3600]);
        $this->client->capacityEvidence = $this->serverCapacityEvidence([
            3600 => $this->serverCapacityWindow(3600, 12, 8),
        ]);

        $this->getJson('/waterline/api/v2/capacity-evidence?window_seconds=3600')
            ->assertStatus(501)
            ->assertJsonPath('reason', 'capacity_evidence_contract_unavailable')
            ->assertJsonPath('contract_failure', 'capacity_evidence_not_yet_valid')
            ->assertJsonPath('backend.capabilities.capacity_evidence', false)
            ->assertJsonMissingPath('generated_at')
            ->assertJsonMissingPath('freshness')
            ->assertJsonMissingPath('runtime_evidence');
    }

    public function testCapacityEvidenceAcceptsInclusiveFreshnessAndClockSkewBoundaries(): void
    {
        config()->set('waterline.capacity_evidence.allowed_window_seconds', [3600]);
        $this->client->capacityEvidence = $this->serverCapacityEvidence([
            3600 => $this->serverCapacityWindow(3600, 12, 8),
        ]);

        foreach ([
            '2026-08-12T11:59:59Z',
            '2026-08-12T12:00:00Z',
            '2026-08-12T12:00:30Z',
            '2026-08-12T12:00:31Z',
        ] as $requestTime) {
            Carbon::setTestNow($requestTime);

            $this->getJson('/waterline/api/v2/capacity-evidence?window_seconds=3600')
                ->assertOk()
                ->assertJsonPath('generated_at', '2026-08-12T12:00:00.000000Z')
                ->assertJsonPath('freshness.valid_until', '2026-08-12T12:00:30.000000Z')
                ->assertJsonPath('backend.capabilities.capacity_evidence', true);
        }
    }

    public function testCapacityEvidenceFailsExplicitlyWhenTheServerContractIsMissing(): void
    {
        $this->getJson('/waterline/api/v2/capacity-evidence?window_seconds=3600')
            ->assertStatus(501)
            ->assertJsonPath('reason', 'capacity_evidence_contract_unavailable')
            ->assertJsonPath('contract_failure', 'capacity_evidence_missing')
            ->assertJsonPath('required_contract.window_seconds', 3600)
            ->assertJsonPath('backend.capabilities.capacity_evidence', false)
            ->assertJsonMissingPath('runtime_evidence');
    }

    public function testCapacityEvidenceNeverRelabelsAVersionedFixedServerWindow(): void
    {
        $this->client->capacityEvidence = $this->serverCapacityEvidence([
            300 => $this->serverCapacityWindow(300, 12, 8),
        ]);

        $this->getJson('/waterline/api/v2/capacity-evidence?window_seconds=3600')
            ->assertStatus(501)
            ->assertJsonPath('reason', 'capacity_evidence_contract_unavailable')
            ->assertJsonPath('contract_failure', 'capacity_evidence_window_unsupported')
            ->assertJsonPath('backend.capabilities.capacity_evidence', false)
            ->assertJsonMissingPath('runtime_evidence');
    }

    public function testCapacityEvidenceRejectsAnIncompleteVersionedServerContract(): void
    {
        $window = $this->serverCapacityWindow(3600, 12, 8);
        unset($window['runtime_evidence']['reliability']['failures']);
        $this->client->capacityEvidence = $this->serverCapacityEvidence([3600 => $window]);

        $this->getJson('/waterline/api/v2/capacity-evidence?window_seconds=3600')
            ->assertStatus(501)
            ->assertJsonPath('reason', 'capacity_evidence_contract_unavailable')
            ->assertJsonPath('contract_failure', 'capacity_evidence_contract_incomplete')
            ->assertJsonPath('backend.capabilities.capacity_evidence', false)
            ->assertJsonMissingPath('runtime_evidence');
    }

    public function testCapacityEvidenceRejectsAdversarialMeasurementAndSustainedMetadata(): void
    {
        $mutations = [
            static function (array &$window): void {
                $window['runtime_evidence']['throughput']['workflow_starts'] = [];
            },
            static function (array &$window): void {
                $window['runtime_evidence']['latency']['execution']['samples_ms'] = [];
            },
            static function (array &$window): void {
                $window['runtime_evidence']['growth']['history_events']['value'] = '14';
            },
            static function (array &$window): void {
                unset($window['sustained_evidence']['minimum_windows_required_for_recommendation']);
            },
        ];

        foreach ($mutations as $mutate) {
            $window = $this->serverCapacityWindow(3600, 12, 8);
            $mutate($window);
            $client = new FakeRemoteClient();
            $client->capacityEvidence = $this->serverCapacityEvidence([3600 => $window]);
            $this->app->instance(RemoteBackend::class, new RemoteBackend($client));

            $this->getJson('/waterline/api/v2/capacity-evidence?window_seconds=3600')
                ->assertStatus(501)
                ->assertJsonPath('reason', 'capacity_evidence_contract_unavailable')
                ->assertJsonPath('contract_failure', 'capacity_evidence_contract_incomplete')
                ->assertJsonPath('backend.capabilities.capacity_evidence', false)
                ->assertJsonMissingPath('runtime_evidence');
        }
    }

    public function testCapacityEvidencePreservesRemoteAuthorizationFailures(): void
    {
        $this->client->failures['operatorMetrics'] = new ServerException(
            'The server token is not authorized for operator metrics.',
            403,
            'authorization_failed',
            ['required_role' => 'admin'],
        );

        $this->getJson('/waterline/api/v2/capacity-evidence?window_seconds=3600')
            ->assertForbidden()
            ->assertJsonPath('error', 'remote_authorization_failed')
            ->assertJsonPath('reason', 'authorization_failed')
            ->assertJsonPath('remote_details.required_role', 'admin');
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
     * @param array<int, array<string, mixed>> $windows
     * @return array<string, mixed>
     */
    private function serverCapacityEvidence(array $windows): array
    {
        return [
            'schema' => 'durable-workflow.v2.namespace-capacity-evidence',
            'schema_version' => 1,
            'generated_at' => '2026-08-12T12:00:00.000000Z',
            'freshness' => [
                'strategy' => 'namespace_snapshot_cache',
                'max_age_seconds' => 30,
                'valid_until' => '2026-08-12T12:00:30.000000Z',
            ],
            'namespace' => 'orders',
            'supported_window_seconds' => array_keys($windows),
            'windows' => $windows,
            'cardinality' => [
                'bounded' => true,
                'dimensions' => ['namespace'],
                'prohibited_dimensions' => ['workflow_id', 'run_id', 'task_id', 'worker_id'],
                'individual_execution_identifiers_included' => false,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function serverCapacityWindow(int $duration, int $workflowStarts, int $queries): array
    {
        $end = Carbon::parse('2026-08-12T12:00:00Z');
        $count = static fn (int $value): array => [
            'available' => true,
            'value' => $value,
            'unit' => 'count',
            'kind' => 'window_count',
            'source' => 'durable_workflow_service',
        ];
        $distribution = static fn (int $value): array => [
            'available' => true,
            'samples_ms' => [$value],
            'population_count' => 1,
            'sample_limit' => 10000,
            'sample_truncated' => false,
            'source' => 'durable_workflow_service',
        ];

        return [
            'observation_window' => [
                'starts_at' => $end->copy()->subSeconds($duration)->toJSON(),
                'ends_at' => $end->toJSON(),
                'duration_seconds' => $duration,
            ],
            'runtime_evidence' => [
                'throughput' => [
                    'workflow_starts' => $count($workflowStarts),
                    'workflow_completions' => $count(9),
                    'activity_dispatches' => $count(7),
                    'activity_completions' => $count(6),
                    'timers_scheduled' => $count(5),
                    'timers_fired' => $count(4),
                    'signals' => $count(3),
                    'queries' => $count($queries),
                    'updates' => $count(2),
                ],
                'latency' => [
                    'schedule_to_start' => $distribution(10),
                    'execution' => $distribution(20),
                    'replay' => $distribution(30),
                    'inspection' => [
                        'available' => false,
                        'source' => 'not_available',
                        'reason' => 'inspection_telemetry_unavailable',
                    ],
                ],
                'growth' => [
                    'history_events' => $count(14),
                    'history_payload_bytes' => [...$count(1024), 'unit' => 'bytes'],
                    'durable_payload_bytes' => [...$count(2048), 'unit' => 'bytes'],
                ],
                'reliability' => [
                    'retries' => $count(1),
                    'timeouts' => $count(0),
                    'failures' => $count(0),
                    'stale_heartbeats' => [...$count(0), 'kind' => 'gauge'],
                    'overload_or_throttling' => $count(0),
                ],
            ],
            'sustained_evidence' => [
                'observation_windows' => 2,
                'upgrade_breach_windows' => [],
                'downgrade_clear_windows' => ['workflow_starts_per_second' => 2],
                'minimum_windows_required_for_recommendation' => 3,
            ],
        ];
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
