<?php

namespace Waterline\Tests\Feature;

use Illuminate\Bus\BusServiceProvider;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Cookie\CookieServiceProvider;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\EncryptionServiceProvider;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Providers\FoundationServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Log\LogServiceProvider;
use Illuminate\Pagination\PaginationServiceProvider;
use Illuminate\Queue\QueueServiceProvider;
use Illuminate\Session\SessionServiceProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Translation\TranslationServiceProvider;
use Illuminate\Validation\ValidationServiceProvider;
use Illuminate\View\ViewServiceProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Waterline\Models\WorkerBuildIdRollout;
use Waterline\Models\WorkerRegistration;
use Waterline\WaterlineServiceProvider;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

class FreshLaravelPackageHostWorkerVersioningTest extends TestCase
{
    private ?Application $app = null;

    private ?string $temporaryDirectory = null;

    private ?string $hostDatabase = null;

    private ?string $serverDatabase = null;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $previousEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-04-09 12:00:00');

        $this->temporaryDirectory = sys_get_temp_dir()
            .'/waterline-fresh-laravel-host-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($this->temporaryDirectory, 0777, true);
        mkdir($this->temporaryDirectory.'/storage/framework/views', 0777, true);
        mkdir($this->temporaryDirectory.'/storage/framework/cache', 0777, true);
        mkdir($this->temporaryDirectory.'/storage/framework/sessions', 0777, true);
        mkdir($this->temporaryDirectory.'/storage/logs', 0777, true);
        mkdir($this->temporaryDirectory.'/resources/views', 0777, true);

        $this->hostDatabase = $this->temporaryDirectory.'/host.sqlite';
        $this->serverDatabase = $this->temporaryDirectory.'/server.sqlite';
        touch($this->hostDatabase);
        touch($this->serverDatabase);

        $this->setEnvironment([
            'APP_ENV' => 'local',
            'APP_DEBUG' => 'false',
            'APP_KEY' => 'base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI=',
            'DB_CONNECTION' => 'host',
            'WATERLINE_WORKFLOW_STORAGE_CONNECTION' => 'server_storage',
            'WATERLINE_WORKFLOW_DB_CONNECTION' => 'sqlite',
            'WATERLINE_WORKFLOW_DB_DATABASE' => $this->serverDatabase,
            'QUEUE_CONNECTION' => 'sync',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'WATERLINE_ALLOW_UNAUTHENTICATED' => 'true',
            'WATERLINE_ENGINE_SOURCE' => 'auto',
            'WATERLINE_HEALTH_TASK_DISPATCH_MODE' => 'poll',
            'WATERLINE_NAMESPACE' => 'worker-versioning-conformance',
            'DW_V2_TASK_DISPATCH_MODE' => 'poll',
        ]);

        $this->app = $this->freshApplication();
        $this->createServerSchema();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        $this->app = null;
        Carbon::setTestNow();
        $this->restoreEnvironment();

        foreach ([$this->hostDatabase, $this->serverDatabase] as $path) {
            if (is_string($path) && is_file($path)) {
                @unlink($path);
            }
        }

        $this->removeDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function testFreshLaravelPackageHostExposesWorkerVersioningJsonSurfacesFromSharedStorage(): void
    {
        [
            'v1' => $v1Run,
            'promoted' => $promotedRun,
            'no_compatible' => $noCompatibleRun,
        ] = $this->seedWorkerVersioningTopology();

        $this->assertFalse(
            DB::connection('host')->getSchemaBuilder()->hasTable('workflow_runs'),
            'The fresh host default database must not contain workflow runtime tables.',
        );
        $this->assertTrue(
            DB::connection('server_storage')->getSchemaBuilder()->hasTable('workflow_runs'),
            'The shared server storage database must contain workflow runtime tables.',
        );

        [$healthResponse, $health] = $this->getJson('/waterline/api/v2/health');

        $this->assertSame(200, $healthResponse->getStatusCode(), json_encode($health));
        $this->assertSame('worker-versioning-conformance', $health['namespace'] ?? null);
        $this->assertSame('auto', $health['engine_source']['configured'] ?? null);
        $this->assertSame(true, $health['engine_source']['uses_v2'] ?? null);
        $this->assertSame('host', $health['engine_source']['storage_connection']['default_connection'] ?? null);
        $this->assertSame('server_storage', $health['engine_source']['storage_connection']['configured'] ?? null);
        $this->assertSame('server_storage', $health['engine_source']['storage_connection']['effective_connection'] ?? null);
        $this->assertSame(true, $health['engine_source']['storage_connection']['core_tables_available'] ?? null);
        $this->assertSame('available', $health['engine_source']['storage_connection']['core_table_status'] ?? null);
        $this->assertSame([], $health['engine_source']['storage_connection']['missing_core_tables'] ?? null);
        $this->assertStorageConnectionDiagnosticsRedacted(
            $health['engine_source']['storage_connection'] ?? [],
            [$this->hostDatabase, $this->serverDatabase],
        );
        $serverConnection = collect($health['engine_source']['storage_connection']['connections'] ?? [])
            ->firstWhere('name', 'server_storage');
        $this->assertIsArray($serverConnection);
        $this->assertSame('available', $serverConnection['core_table_status'] ?? null);
        $this->assertSame(true, $health['queue_visibility']['available'] ?? null);

        $queue = collect($health['queue_visibility']['task_queues'] ?? [])
            ->firstWhere('task_queue', 'worker-versioning-shared');
        $this->assertIsArray($queue);
        $this->assertSame('build-v2', $queue['rollout_state']['selected_new_start_build_id'] ?? null);
        $this->assertContains('build-v1', $health['worker_versioning']['worker_cohorts'] ?? []);
        $this->assertContains('build-v2', $health['worker_versioning']['worker_cohorts'] ?? []);

        $queueBuildIds = collect($queue['build_ids'] ?? []);
        $this->assertTrue($queueBuildIds->contains(fn (array $build): bool => ($build['build_id'] ?? null) === 'build-v1'));
        $this->assertTrue($queueBuildIds->contains(fn (array $build): bool => ($build['build_id'] ?? null) === 'build-v2'));
        $noCompatibleBuild = $queueBuildIds->firstWhere('build_id', 'build-v3');
        $this->assertIsArray($noCompatibleBuild);
        $this->assertSame('no_compatible_worker', $noCompatibleBuild['pending_workflow_tasks']['status'] ?? null);

        [$runningResponse, $running] = $this->getJson('/waterline/api/flows/running');
        $this->assertSame(200, $runningResponse->getStatusCode(), json_encode($running));
        $noCompatibleRow = $this->listRowForRun($running, $noCompatibleRun);
        $this->assertSame('waiting', $noCompatibleRow['status'] ?? null);
        $this->assertSame('running', $noCompatibleRow['status_bucket'] ?? null);
        $this->assertSame('build-v3', $noCompatibleRow['compatibility'] ?? null);

        [$completedResponse, $completed] = $this->getJson('/waterline/api/flows/completed');
        $this->assertSame(200, $completedResponse->getStatusCode(), json_encode($completed));
        $this->assertSame('build-v1', $this->listRowForRun($completed, $v1Run)['compatibility'] ?? null);
        $this->assertSame('build-v2', $this->listRowForRun($completed, $promotedRun)['compatibility'] ?? null);

        $this->assertSelectedRunDetail($v1Run, 'completed', 'completed', 'build-v1');
        $this->assertSelectedRunDetail($promotedRun, 'completed', 'completed', 'build-v2');
        $noCompatibleDetail = $this->assertSelectedRunDetail($noCompatibleRun, 'waiting', 'running', 'build-v3');

        $this->assertNotNull(
            collect($noCompatibleDetail['run_diagnostics'] ?? [])->firstWhere('code', 'no_compatible_worker_for_task'),
            'Selected run detail must expose the no-compatible-worker operator diagnostic.',
        );
    }

    public function testFreshLaravelPackageHostReturnsJsonForOperatorApiFailures(): void
    {
        [$response, $payload] = $this->getJson('/waterline/api/instances/missing-workflow/runs/missing-run');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('application/json', (string) $response->headers->get('content-type'));
        $this->assertSame('not_found', $payload['error'] ?? null);
    }

    public function testFreshLaravelPackageHostPreservesFrameworkValidationJsonResponses(): void
    {
        [$preferencesResponse, $preferences] = $this->putJson('/waterline/api/preferences/workflow-list', [
            'preferences' => [
                'row_density' => 'wide',
            ],
        ]);

        $this->assertSame(422, $preferencesResponse->getStatusCode(), json_encode($preferences));
        $this->assertArrayHasKey('errors', $preferences);
        $this->assertIsArray($preferences['errors']);
        $this->assertArrayHasKey('preferences.row_density', $preferences['errors']);
        $this->assertArrayNotHasKey('error', $preferences);

        [$savedViewResponse, $savedView] = $this->postJson('/waterline/api/saved-views', [
            'bucket' => 'running',
        ]);

        $this->assertSame(422, $savedViewResponse->getStatusCode(), json_encode($savedView));
        $this->assertArrayHasKey('errors', $savedView);
        $this->assertIsArray($savedView['errors']);
        $this->assertArrayHasKey('name', $savedView['errors']);
        $this->assertArrayNotHasKey('error', $savedView);
    }

    /**
     * @param array<string, mixed> $storageConnection
     * @param list<string|null> $sensitiveValues
     */
    private function assertStorageConnectionDiagnosticsRedacted(array $storageConnection, array $sensitiveValues): void
    {
        foreach ($storageConnection['connections'] ?? [] as $connection) {
            $this->assertIsArray($connection);
            $this->assertArrayNotHasKey('database', $connection);
            $this->assertArrayNotHasKey('host', $connection);
            $this->assertArrayNotHasKey('port', $connection);
        }

        $encoded = json_encode($storageConnection, JSON_UNESCAPED_SLASHES);
        $this->assertIsString($encoded);

        foreach ($sensitiveValues as $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            $this->assertStringNotContainsString($value, $encoded);
        }
    }

    private function freshApplication(): Application
    {
        $app = new Application($this->temporaryDirectory);
        $app->useStoragePath($this->temporaryDirectory.'/storage');

        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        $app->instance('config', new ConfigRepository($this->baseConfig()));
        $app->instance('request', Request::create('/', 'GET'));

        foreach ([
            EventServiceProvider::class,
            LogServiceProvider::class,
            FilesystemServiceProvider::class,
            DatabaseServiceProvider::class,
            CacheServiceProvider::class,
            QueueServiceProvider::class,
            PaginationServiceProvider::class,
            BusServiceProvider::class,
            CookieServiceProvider::class,
            EncryptionServiceProvider::class,
            SessionServiceProvider::class,
            ViewServiceProvider::class,
            TranslationServiceProvider::class,
            ValidationServiceProvider::class,
            FoundationServiceProvider::class,
            WorkflowServiceProvider::class,
            WaterlineServiceProvider::class,
        ] as $provider) {
            $app->register($provider);
        }

        $app['router']->middlewareGroup('web', []);

        $app->boot();

        return $app;
    }

    /**
     * @return array<string, mixed>
     */
    private function baseConfig(): array
    {
        return [
            'app' => [
                'debug' => false,
                'env' => 'local',
                'key' => 'base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI=',
                'locale' => 'en',
                'fallback_locale' => 'en',
                'timezone' => 'UTC',
            ],
            'cache' => [
                'default' => 'array',
                'stores' => [
                    'array' => [
                        'driver' => 'array',
                    ],
                ],
            ],
            'database' => [
                'default' => 'host',
                'connections' => [
                    'host' => $this->sqliteConnection($this->hostDatabase),
                ],
                'migrations' => 'migrations',
            ],
            'filesystems' => [
                'default' => 'local',
                'disks' => [
                    'local' => [
                        'driver' => 'local',
                        'root' => $this->temporaryDirectory.'/storage/app',
                    ],
                ],
            ],
            'logging' => [
                'default' => 'null',
                'channels' => [
                    'null' => [
                        'driver' => 'monolog',
                        'handler' => \Monolog\Handler\NullHandler::class,
                    ],
                ],
            ],
            'queue' => [
                'default' => 'sync',
                'connections' => [
                    'sync' => [
                        'driver' => 'sync',
                    ],
                ],
            ],
            'session' => [
                'driver' => 'array',
                'connection' => null,
                'table' => 'sessions',
                'store' => null,
                'lifetime' => 120,
                'expire_on_close' => false,
                'encrypt' => false,
                'files' => $this->temporaryDirectory.'/storage/framework/sessions',
                'cookie' => 'waterline_session',
                'path' => '/',
                'domain' => null,
                'secure' => false,
                'http_only' => true,
                'same_site' => 'lax',
            ],
            'view' => [
                'paths' => [
                    $this->temporaryDirectory.'/resources/views',
                ],
                'compiled' => $this->temporaryDirectory.'/storage/framework/views',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sqliteConnection(string $database): array
    {
        return [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ];
    }

    private function createServerSchema(): void
    {
        $schema = Schema::connection('server_storage');

        $schema->create('workflow_instances', static function (Blueprint $table): void {
            $table->string('id', 191)->primary();
            $table->string('workflow_class');
            $table->string('workflow_type');
            $table->string('namespace')->nullable()->index();
            $table->string('business_key', 191)->nullable()->index();
            $table->json('visibility_labels')->nullable();
            $table->json('memo')->nullable();
            $table->string('current_run_id', 26)->nullable()->index();
            $table->unsignedInteger('run_count')->default(0);
            $table->timestamp('started_at', 6)->nullable();
            $table->timestamps(6);
        });

        $schema->create('workflow_runs', static function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('workflow_instance_id', 191)->index();
            $table->unsignedInteger('run_number');
            $table->string('workflow_class');
            $table->string('workflow_type');
            $table->string('namespace')->nullable()->index();
            $table->string('business_key', 191)->nullable()->index();
            $table->json('visibility_labels')->nullable();
            $table->string('status');
            $table->string('closed_reason')->nullable();
            $table->string('compatibility')->nullable();
            $table->string('payload_codec')->nullable();
            $table->longText('arguments')->nullable();
            $table->longText('output')->nullable();
            $table->string('connection')->nullable();
            $table->string('queue')->nullable();
            $table->unsignedInteger('last_history_sequence')->default(0);
            $table->unsignedInteger('last_command_sequence')->default(0);
            $table->unsignedInteger('message_cursor_position')->default(0);
            $table->timestamp('started_at', 6)->nullable();
            $table->timestamp('closed_at', 6)->nullable();
            $table->timestamp('archived_at', 6)->nullable()->index();
            $table->string('archive_command_id', 26)->nullable()->index();
            $table->string('archive_reason')->nullable();
            $table->timestamp('last_progress_at', 6)->nullable();
            $table->timestamps(6);
        });

        $schema->create('workflow_history_events', static function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('workflow_run_id', 26)->index();
            $table->unsignedInteger('sequence');
            $table->string('event_type');
            $table->json('payload')->nullable();
            $table->timestamp('recorded_at', 6)->nullable();
            $table->timestamps(6);
        });

        $schema->create('workflow_tasks', static function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('workflow_run_id', 26)->index();
            $table->string('namespace')->nullable()->index();
            $table->string('task_type');
            $table->string('status');
            $table->string('compatibility')->nullable();
            $table->json('payload')->nullable();
            $table->string('connection')->nullable();
            $table->string('queue')->nullable();
            $table->timestamp('available_at', 6)->nullable();
            $table->timestamp('leased_at', 6)->nullable();
            $table->string('lease_owner')->nullable();
            $table->timestamp('lease_expires_at', 6)->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('last_dispatch_attempt_at', 6)->nullable();
            $table->timestamp('last_dispatched_at', 6)->nullable();
            $table->text('last_dispatch_error')->nullable();
            $table->timestamp('last_claim_failed_at', 6)->nullable();
            $table->text('last_claim_error')->nullable();
            $table->unsignedInteger('repair_count')->default(0);
            $table->timestamp('repair_available_at', 6)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps(6);
            $table->index(['status', 'available_at']);
        });

        $this->createServerWorkerRegistrationsTable();
        $this->createServerWorkerBuildIdRolloutsTable();
    }

    /**
     * @return array{v1: WorkflowRun, promoted: WorkflowRun, no_compatible: WorkflowRun}
     */
    private function seedWorkerVersioningTopology(): array
    {
        $this->seedWorkerVersioningWorker('wv-v1-worker', 'php', '2.0.0-alpha.206', 'build-v1', 10);
        $this->seedWorkerVersioningWorker('wv-v2-worker', 'python', '0.4.89', 'build-v2', 5);

        WorkerBuildIdRollout::create([
            'namespace' => 'worker-versioning-conformance',
            'task_queue' => 'worker-versioning-shared',
            'build_id' => WorkerBuildIdRollout::buildIdKey('build-v1'),
            'drain_intent' => WorkerBuildIdRollout::DRAIN_INTENT_ACTIVE,
            'promoted_at' => now()->subMinutes(10),
        ]);

        WorkerBuildIdRollout::create([
            'namespace' => 'worker-versioning-conformance',
            'task_queue' => 'worker-versioning-shared',
            'build_id' => WorkerBuildIdRollout::buildIdKey('build-v2'),
            'drain_intent' => WorkerBuildIdRollout::DRAIN_INTENT_ACTIVE,
            'promoted_at' => now()->subMinute(),
        ]);

        $v1Run = $this->seedWorkerVersioningRun(
            'worker-versioning-v1-instance',
            '01JWVFRESHV1RUN0000001',
            'completed',
            'build-v1',
            now()->subMinutes(6),
        );
        $promotedRun = $this->seedWorkerVersioningRun(
            'worker-versioning-v2-instance',
            '01JWVFRESHV2RUN0000001',
            'completed',
            'build-v2',
            now()->subMinutes(2),
        );
        $noCompatibleRun = $this->seedWorkerVersioningRun(
            'worker-versioning-v3-instance',
            '01JWVFRESHV3RUN0000001',
            'waiting',
            'build-v3',
            null,
        );

        WorkflowTask::create([
            'id' => '01JWVFRESHTASK0000001',
            'workflow_run_id' => $noCompatibleRun->id,
            'namespace' => 'worker-versioning-conformance',
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()->subMinute(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'worker-versioning-shared',
            'compatibility' => 'build-v3',
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        return [
            'v1' => $v1Run,
            'promoted' => $promotedRun,
            'no_compatible' => $noCompatibleRun,
        ];
    }

    private function seedWorkerVersioningWorker(
        string $workerId,
        string $runtime,
        string $sdkVersion,
        string $buildId,
        int $heartbeatSecondsAgo,
    ): void {
        WorkerRegistration::create([
            'worker_id' => $workerId,
            'namespace' => 'worker-versioning-conformance',
            'task_queue' => 'worker-versioning-shared',
            'runtime' => $runtime,
            'sdk_version' => $sdkVersion,
            'build_id' => $buildId,
            'supported_workflow_types' => ['workflow.worker-versioning'],
            'workflow_definition_fingerprints' => [],
            'supported_activity_types' => ['activity.worker-versioning'],
            'max_concurrent_workflow_tasks' => 8,
            'max_concurrent_activity_tasks' => 4,
            'available_workflow_slots' => 7,
            'available_activity_slots' => 3,
            'process_metrics' => [
                'process_id' => $buildId === 'build-v1' ? 1001 : 1002,
                'host' => 'worker-versioning-conformance',
                'process_started_at' => now()->subMinutes(5)->toJSON(),
                'process_uptime_seconds' => 300,
            ],
            'heartbeat_interval_seconds' => 60,
            'last_heartbeat_at' => now()->subSeconds($heartbeatSecondsAgo),
            'status' => 'active',
        ]);
    }

    private function seedWorkerVersioningRun(
        string $workflowId,
        string $runId,
        string $status,
        string $compatibility,
        ?Carbon $closedAt,
    ): WorkflowRun {
        $instance = WorkflowInstance::query()->create([
            'id' => $workflowId,
            'workflow_class' => 'WorkerVersioningWorkflow',
            'workflow_type' => 'workflow.worker-versioning',
            'namespace' => 'worker-versioning-conformance',
            'run_count' => 1,
            'started_at' => now()->subMinutes(12),
        ]);

        $run = WorkflowRun::query()->create([
            'id' => $runId,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkerVersioningWorkflow',
            'workflow_type' => 'workflow.worker-versioning',
            'namespace' => 'worker-versioning-conformance',
            'status' => $status,
            'closed_reason' => $closedAt === null ? null : $status,
            'closed_at' => $closedAt,
            'compatibility' => $compatibility,
            'connection' => 'redis',
            'queue' => 'worker-versioning-shared',
            'started_at' => now()->subMinutes(12),
            'last_progress_at' => $closedAt ?? now()->subMinute(),
            'last_history_sequence' => $closedAt === null ? 1 : 2,
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        WorkflowHistoryEvent::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => 'WorkflowStarted',
            'payload' => [
                'workflow_type' => 'workflow.worker-versioning',
                'compatibility' => $compatibility,
            ],
            'recorded_at' => now()->subMinutes(12),
        ]);

        if ($closedAt !== null) {
            WorkflowHistoryEvent::query()->create([
                'id' => (string) Str::ulid(),
                'workflow_run_id' => $run->id,
                'sequence' => 2,
                'event_type' => 'WorkflowCompleted',
                'payload' => [
                    'status' => $status,
                    'compatibility' => $compatibility,
                ],
                'recorded_at' => $closedAt,
            ]);
        }

        return $run->refresh();
    }

    /**
     * @return array{0: Response, 1: array<string, mixed>}
     */
    private function getJson(string $path): array
    {
        return $this->json('GET', $path);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: Response, 1: array<string, mixed>}
     */
    private function postJson(string $path, array $payload): array
    {
        return $this->json('POST', $path, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: Response, 1: array<string, mixed>}
     */
    private function putJson(string $path, array $payload): array
    {
        return $this->json('PUT', $path, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: Response, 1: array<string, mixed>}
     */
    private function json(string $method, string $path, array $payload = []): array
    {
        $method = strtoupper($method);
        $request = Request::create($path, $method, [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_DURABLE_WORKFLOW_CONTROL_PLANE_VERSION' => '2',
        ], $method === 'GET' ? null : json_encode($payload));

        $this->app->instance('request', $request);
        $response = $this->app['router']->dispatch($request);
        $payload = json_decode((string) $response->getContent(), true);

        $this->assertStringContainsString(
            'application/json',
            (string) $response->headers->get('content-type'),
            (string) $response->getContent(),
        );
        $this->assertIsArray($payload, (string) $response->getContent());

        return [$response, $payload];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function listRowForRun(array $payload, WorkflowRun $run): array
    {
        $row = collect($payload['data'] ?? [])->first(
            static fn (array $item): bool => ($item['workflow_instance_id'] ?? null) === $run->workflow_instance_id
                && ($item['run_id'] ?? null) === $run->id,
        );

        $this->assertIsArray($row);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function assertSelectedRunDetail(
        WorkflowRun $run,
        string $status,
        string $statusBucket,
        string $compatibility,
    ): array {
        [, $payload] = $this->getJson(
            '/waterline/api/instances/'
            .rawurlencode($run->workflow_instance_id)
            .'/runs/'
            .rawurlencode($run->id)
            .'?history_limit=all',
        );

        $this->assertSame($run->id, $payload['id'] ?? null);
        $this->assertSame($run->workflow_instance_id, $payload['workflow_instance_id'] ?? null);
        $this->assertSame($run->id, $payload['workflow_run_id'] ?? null);
        $this->assertSame($run->workflow_instance_id, $payload['instance_id'] ?? null);
        $this->assertSame($run->id, $payload['run_id'] ?? null);
        $this->assertSame($run->id, $payload['selected_run_id'] ?? null);
        $this->assertSame('worker-versioning-conformance', $payload['namespace'] ?? null);
        $this->assertSame($status, $payload['status'] ?? null);
        $this->assertSame($statusBucket, $payload['status_bucket'] ?? null);
        $this->assertSame($compatibility, $payload['compatibility'] ?? null);
        $this->assertSame('redis', $payload['connection'] ?? null);
        $this->assertSame('worker-versioning-shared', $payload['queue'] ?? null);

        return $payload;
    }

    private function createServerWorkerRegistrationsTable(): void
    {
        Schema::connection('server_storage')->create('workflow_worker_registrations', static function (Blueprint $table): void {
            $table->id();
            $table->string('worker_id', 255);
            $table->string('namespace', 128);
            $table->string('task_queue', 255);
            $table->string('runtime', 32);
            $table->string('sdk_version', 64)->nullable();
            $table->string('build_id', 255)->nullable();
            $table->json('supported_workflow_types')->nullable();
            $table->json('workflow_definition_fingerprints')->nullable();
            $table->json('supported_activity_types')->nullable();
            $table->unsignedInteger('max_concurrent_workflow_tasks')->default(100);
            $table->unsignedInteger('max_concurrent_activity_tasks')->default(100);
            $table->unsignedInteger('max_concurrent_worker_sessions')->nullable();
            $table->unsignedInteger('available_workflow_slots')->nullable();
            $table->unsignedInteger('available_activity_slots')->nullable();
            $table->unsignedInteger('available_session_slots')->nullable();
            $table->json('process_metrics')->nullable();
            $table->unsignedInteger('heartbeat_interval_seconds')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->unique(['worker_id', 'namespace']);
            $table->index(['namespace', 'task_queue', 'status']);
        });
    }

    private function createServerWorkerBuildIdRolloutsTable(): void
    {
        Schema::connection('server_storage')->create('workflow_worker_build_id_rollouts', static function (Blueprint $table): void {
            $table->id();
            $table->string('namespace', 128);
            $table->string('task_queue', 255);
            $table->string('build_id', 255)->default(WorkerBuildIdRollout::UNVERSIONED_KEY);
            $table->string('drain_intent', 32)->default(WorkerBuildIdRollout::DRAIN_INTENT_ACTIVE);
            $table->timestamp('drained_at')->nullable();
            $table->timestamp('promoted_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->string('required_compatibility', 255)->nullable();
            $table->string('recorded_fingerprint', 255)->nullable();
            $table->json('compatibility_policy')->nullable();
            $table->json('workflow_types')->nullable();
            $table->timestamps();
            $table->unique(['namespace', 'task_queue', 'build_id']);
            $table->index(['namespace', 'task_queue', 'drain_intent']);
        });
    }

    /**
     * @param array<string, string> $values
     */
    private function setEnvironment(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, $this->previousEnvironment)) {
                $this->previousEnvironment[$key] = [
                    'getenv' => getenv($key),
                    'env_exists' => array_key_exists($key, $_ENV),
                    'env' => $_ENV[$key] ?? null,
                    'server_exists' => array_key_exists($key, $_SERVER),
                    'server' => $_SERVER[$key] ?? null,
                ];
            }

            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    private function restoreEnvironment(): void
    {
        foreach ($this->previousEnvironment as $key => $state) {
            if ($state['getenv'] === false) {
                putenv($key);
            } else {
                putenv($key.'='.$state['getenv']);
            }

            if ($state['env_exists']) {
                $_ENV[$key] = $state['env'];
            } else {
                unset($_ENV[$key]);
            }

            if ($state['server_exists']) {
                $_SERVER[$key] = $state['server'];
            } else {
                unset($_SERVER[$key]);
            }
        }

        $this->previousEnvironment = [];
    }

    private function removeDirectory(?string $directory): void
    {
        if (! is_string($directory) || ! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.'/'.$item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
