<?php

namespace Waterline\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Waterline\Models\WorkerBuildIdRollout;
use Waterline\Models\WorkerRegistration;
use Waterline\Support\WorkflowEngineSourceResolver;
use Waterline\Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use function Orchestra\Testbench\artisan;

class PackageInstalledSharedStorageHostTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $previousDatabaseConnectionEnvironment = [];

    private ?string $temporaryDirectory = null;

    private ?string $hostDatabase = null;

    private ?string $serverDatabase = null;

    protected function setUp(): void
    {
        try {
            parent::setUp();
        } catch (\Throwable $exception) {
            $this->restoreDatabaseConnectionEnvironment();

            throw $exception;
        }
    }

    protected function getEnvironmentSetUp($app)
    {
        $this->scopeDatabaseConnectionEnvironmentToHost();
        parent::getEnvironmentSetUp($app);

        $this->temporaryDirectory = sys_get_temp_dir()
            .'/waterline-package-host-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($this->temporaryDirectory, 0777, true);

        $this->hostDatabase = $this->temporaryDirectory.'/host.sqlite';
        $this->serverDatabase = $this->temporaryDirectory.'/server.sqlite';
        touch($this->hostDatabase);
        touch($this->serverDatabase);

        $app['config']->set('database.default', 'host');
        $app['config']->set('database.connections.host', $this->sqliteConnection($this->hostDatabase));
        $app['config']->set('database.connections.server_storage', $this->sqliteConnection($this->serverDatabase));
        $app['config']->set('workflows.storage.connection', 'server_storage');
        $app['config']->set('waterline.engine_source', 'v2');
        $app['config']->set('waterline.namespace', 'default');
        $app['config']->set('waterline.health.task_dispatch_mode', 'poll');
        $app['config']->set('waterline.allow_unauthenticated', true);
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('queue.connections.sync.driver', 'sync');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'database');
        $app['config']->set('session.connection', 'host');
        $app['config']->set('session.table', 'sessions');
    }

    protected function waterlineHostApplicationProviders(): array
    {
        return [];
    }

    protected function defineDatabaseMigrations()
    {
        artisan($this, 'migrate:fresh');
        Schema::connection('host')->dropIfExists('sessions');

        $this->createServerWorkerRegistrationsTable();
        $this->createServerWorkerBuildIdRolloutsTable();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            $this->restoreDatabaseConnectionEnvironment();

            foreach ([$this->hostDatabase, $this->serverDatabase] as $path) {
                if (is_string($path) && is_file($path)) {
                    @unlink($path);
                }
            }

            if (is_string($this->temporaryDirectory) && is_dir($this->temporaryDirectory)) {
                @rmdir($this->temporaryDirectory);
            }
        }
    }

    public function testPackageInstalledHostReadsPausedSagaFromSharedServerStorage(): void
    {
        $this->assertFalse(
            DB::connection('host')->getSchemaBuilder()->hasTable('workflow_runs'),
            'The generated host default database must not contain workflow runtime tables.',
        );
        $this->assertTrue(
            DB::connection('server_storage')->getSchemaBuilder()->hasTable('workflow_runs'),
            'The shared server storage database must contain workflow runtime tables.',
        );
        $this->assertFalse(
            DB::connection('host')->getSchemaBuilder()->hasTable('sessions'),
            'The generated host default database intentionally lacks Laravel session tables.',
        );

        $run = $this->seedPausedSagaRun();
        $this->seedActiveWorkerRegistration();

        $this->getJson('/waterline/api/v2/health')
            ->assertOk()
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('healthy', true)
            ->assertJsonPath('engine_source.uses_v2', true)
            ->assertJsonPath('queue_visibility.available', true)
            ->assertJsonPath('operator_metrics.workers.registrations.0.build_id', 'python-v1')
            ->assertJsonPath('queue_visibility.task_queues.0.build_ids.0.build_id', 'python-v1');

        $runningResponse = $this->getJson('/waterline/api/flows/running')
            ->assertOk();
        $runningRows = collect($runningResponse->json('data'));
        $runningRow = $runningRows->first(
            static fn (array $row): bool => ($row['workflow_instance_id'] ?? null) === $run->workflow_instance_id
                && ($row['run_id'] ?? null) === $run->id,
        );

        $this->assertIsArray($runningRow);
        $this->assertSame('waiting', $runningRow['status'] ?? null);
        $this->assertSame('running', $runningRow['status_bucket'] ?? null);
        $this->assertSame('python-v1', $runningRow['compatibility'] ?? null);
        $this->assertSame('pause_after_refund', $runningRow['current_compensation_marker'] ?? null);
        $this->assertSame(
            'pause_after_refund',
            $runningRow['compensation_visibility']['current_marker'] ?? null,
        );

        $this->getJson(
            '/waterline/api/instances/'
            .rawurlencode($run->workflow_instance_id)
            .'/runs/'
            .rawurlencode($run->id)
            .'?history_limit=all',
        )
            ->assertOk()
            ->assertJsonPath('id', $run->id)
            ->assertJsonPath('workflow_instance_id', $run->workflow_instance_id)
            ->assertJsonPath('workflow_run_id', $run->id)
            ->assertJsonPath('instance_id', $run->workflow_instance_id)
            ->assertJsonPath('run_id', $run->id)
            ->assertJsonPath('selected_run_id', $run->id)
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('status', 'waiting')
            ->assertJsonPath('status_bucket', 'running')
            ->assertJsonPath('compatibility', 'python-v1')
            ->assertJsonPath('connection', 'redis')
            ->assertJsonPath('queue', 'sagas-python')
            ->assertJsonPath('current_compensation_marker', 'pause_after_refund')
            ->assertJsonPath('compensation_visibility.current_marker', 'pause_after_refund')
            ->assertJsonPath('activities.0.type', 'pause_after_refund')
            ->assertJsonPath('activities.0.status', 'running');
    }

    public function testPackageInstalledHostExposesWorkerVersioningVisibilityFromSharedServerStorage(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        config()->set('waterline.namespace', 'worker-versioning-conformance');
        config()->set('waterline.worker_stale_after_seconds', 120);
        Schema::connection('server_storage')->dropIfExists('workflow_run_timers');

        $this->assertFalse(
            DB::connection('host')->getSchemaBuilder()->hasTable('workflow_runs'),
            'The package host must observe workflow runs through the shared server storage connection.',
        );
        $this->assertTrue(
            DB::connection('server_storage')->getSchemaBuilder()->hasTable('workflow_runs'),
            'The shared server storage database must contain workflow runtime tables.',
        );

        [
            'v1' => $v1Run,
            'promoted' => $promotedRun,
            'no_compatible' => $noCompatibleRun,
        ] = $this->seedWorkerVersioningTopology();

        $this->assertSame('host', config('database.default'));
        $this->assertSame('server_storage', config('workflows.storage.connection'));

        $healthPayload = $this->getJson('/waterline/api/v2/health')
            ->assertOk()
            ->assertJsonPath('namespace', 'worker-versioning-conformance')
            ->assertJsonPath('engine_source.uses_v2', true)
            ->assertJsonPath('engine_source.degraded_operator_surface', true)
            ->assertJsonPath('engine_source.storage_connection.repair.applied', false)
            ->assertJsonPath('engine_source.storage_connection.default_connection', 'host')
            ->assertJsonPath('engine_source.storage_connection.effective_connection', 'server_storage')
            ->assertJsonPath('engine_source.storage_connection.core_table_status', 'available')
            ->assertJsonPath('queue_visibility.available', true)
            ->json();
        $this->assertSame('server_storage', config('workflows.storage.connection'));
        $this->assertStorageConnectionDiagnosticsRedacted(
            $healthPayload['engine_source']['storage_connection'] ?? [],
            [$this->hostDatabase, $this->serverDatabase],
        );
        $hostConnection = collect($healthPayload['engine_source']['storage_connection']['connections'] ?? [])
            ->firstWhere('name', 'host');
        $this->assertIsArray($hostConnection);
        $this->assertSame('no_v2_core_tables', $hostConnection['core_table_status'] ?? null);
        $serverConnection = collect($healthPayload['engine_source']['storage_connection']['connections'] ?? [])
            ->firstWhere('name', 'server_storage');
        $this->assertIsArray($serverConnection);
        $this->assertSame('available', $serverConnection['core_table_status'] ?? null);

        $this->assertContains(
            'build-v1',
            collect($healthPayload['operator_metrics']['workers']['registrations'] ?? [])
                ->pluck('build_id')
                ->all(),
        );
        $this->assertContains(
            'build-v2',
            collect($healthPayload['operator_metrics']['workers']['registrations'] ?? [])
                ->pluck('build_id')
                ->all(),
        );
        $this->assertContains('build-v1', $healthPayload['worker_versioning']['worker_cohorts'] ?? []);
        $this->assertContains('build-v2', $healthPayload['worker_versioning']['worker_cohorts'] ?? []);

        $queue = collect($healthPayload['queue_visibility']['task_queues'] ?? [])
            ->firstWhere('task_queue', 'worker-versioning-shared');
        $this->assertIsArray($queue, 'Queue visibility must include the worker-versioning task queue.');
        $this->assertSame('build-v2', $queue['rollout_state']['selected_new_start_build_id'] ?? null);

        $workerBuildIds = collect($queue['workers'] ?? [])->pluck('build_id')->all();
        $this->assertContains('build-v1', $workerBuildIds);
        $this->assertContains('build-v2', $workerBuildIds);
        $v2Worker = collect($queue['workers'] ?? [])->firstWhere('build_id', 'build-v2');
        $this->assertIsArray($v2Worker, 'Queue visibility must expose the build-v2 worker.');
        $this->assertSame(
            'worker-versioning-conformance',
            $v2Worker['process_metrics']['host'] ?? null,
        );
        $this->assertSame(7, $v2Worker['task_slots']['workflow_available'] ?? null);

        $queueBuildIds = collect($queue['build_ids'] ?? []);
        $this->assertTrue($queueBuildIds->contains(fn (array $build): bool => ($build['build_id'] ?? null) === 'build-v1'));
        $this->assertTrue($queueBuildIds->contains(fn (array $build): bool => ($build['build_id'] ?? null) === 'build-v2'));
        $noCompatibleBuild = $queueBuildIds->firstWhere('build_id', 'build-v3');
        $this->assertIsArray($noCompatibleBuild, 'Queue visibility must expose pending work for a build with no active worker.');
        $this->assertSame('no_compatible_worker', $noCompatibleBuild['pending_workflow_tasks']['status'] ?? null);
        $this->assertSame('no_compatible_worker', $noCompatibleBuild['pending_workflow_tasks']['operator_visible_signal'] ?? null);
        $this->assertSame(1, $noCompatibleBuild['pending_workflow_tasks']['total_count'] ?? null);

        $runningPayload = $this->getJson('/waterline/api/flows/running')
            ->assertOk()
            ->json();
        $noCompatibleRow = $this->listRowForRun($runningPayload, $noCompatibleRun);
        $this->assertSame('waiting', $noCompatibleRow['status'] ?? null);
        $this->assertSame('running', $noCompatibleRow['status_bucket'] ?? null);
        $this->assertSame('build-v3', $noCompatibleRow['compatibility'] ?? null);
        $this->assertSame('worker-versioning-shared', $noCompatibleRow['queue'] ?? null);

        $completedPayload = $this->getJson('/waterline/api/flows/completed')
            ->assertOk()
            ->json();
        $v1Row = $this->listRowForRun($completedPayload, $v1Run);
        $this->assertSame('completed', $v1Row['status'] ?? null);
        $this->assertSame('completed', $v1Row['status_bucket'] ?? null);
        $this->assertSame('build-v1', $v1Row['compatibility'] ?? null);
        $promotedRow = $this->listRowForRun($completedPayload, $promotedRun);
        $this->assertSame('completed', $promotedRow['status'] ?? null);
        $this->assertSame('completed', $promotedRow['status_bucket'] ?? null);
        $this->assertSame('build-v2', $promotedRow['compatibility'] ?? null);

        $this->assertSelectedRunDetail($v1Run, 'completed', 'completed', 'build-v1');
        $this->assertSelectedRunDetail($promotedRun, 'completed', 'completed', 'build-v2');
        $noCompatibleDetail = $this->assertSelectedRunDetail($noCompatibleRun, 'waiting', 'running', 'build-v3');

        $this->assertNotNull(
            collect($noCompatibleDetail['run_diagnostics'] ?? [])->firstWhere('code', 'no_compatible_worker_for_task'),
            'Selected run detail must expose the no-compatible-worker operator diagnostic.',
        );
    }

    public function testStorageConnectionDiagnosticsDistinguishUnavailableConnectionWithoutLeakingTopology(): void
    {
        $unavailableDatabase = $this->temporaryDirectory.'/missing/workflow.sqlite';

        config()->set('database.connections.server_storage', null);
        config()->set('database.connections.unavailable_storage', $this->sqliteConnection($unavailableDatabase));
        config()->set('workflows.storage.connection', 'unavailable_storage');
        DB::purge('server_storage');
        DB::purge('unavailable_storage');

        $status = WorkflowEngineSourceResolver::status('v2');
        $storageConnection = $status['storage_connection'] ?? [];
        $this->assertIsArray($storageConnection);
        $this->assertSame('connection_unavailable', $storageConnection['core_table_status'] ?? null);
        $this->assertSame(false, $storageConnection['core_tables_available'] ?? null);
        $this->assertContains(
            'v2_workflow_storage_unreadable',
            $status['readiness_issue_codes'] ?? [],
        );
        $storageIssue = collect($status['readiness_issues'] ?? [])
            ->firstWhere('code', 'v2_workflow_storage_unreadable');
        $this->assertIsArray($storageIssue);
        $this->assertSame('storage_connection', $storageIssue['category'] ?? null);
        $this->assertSame('connection_unavailable', $storageIssue['reason'] ?? null);

        $unavailableConnection = collect($storageConnection['connections'] ?? [])
            ->firstWhere('name', 'unavailable_storage');
        $this->assertIsArray($unavailableConnection);
        $this->assertSame('connection_unavailable', $unavailableConnection['core_table_status'] ?? null);

        $schemaFailures = collect($unavailableConnection['tables'] ?? [])
            ->filter(static fn (array $table): bool => ($table['reason'] ?? null) === 'schema_inspection_failed');
        $this->assertNotEmpty($schemaFailures);
        $this->assertSame(
            'Schema inspection failed while checking workflow storage table availability.',
            $schemaFailures->first()['message'] ?? null,
        );

        $this->assertStorageConnectionDiagnosticsRedacted(
            $storageConnection,
            [$this->hostDatabase, $this->serverDatabase, $unavailableDatabase],
        );

        $readinessJson = json_encode($status['readiness_issues'] ?? [], JSON_UNESCAPED_SLASHES);
        $this->assertIsString($readinessJson);
        $this->assertStringNotContainsString($unavailableDatabase, $readinessJson);

        $engineSourceIssuesJson = json_encode($status['issues'] ?? [], JSON_UNESCAPED_SLASHES);
        $this->assertIsString($engineSourceIssuesJson);
        $this->assertStringNotContainsString($unavailableDatabase, $engineSourceIssuesJson);
    }

    private function scopeDatabaseConnectionEnvironmentToHost(): void
    {
        if ($this->previousDatabaseConnectionEnvironment !== []) {
            return;
        }

        $this->previousDatabaseConnectionEnvironment = [
            'getenv' => getenv('DB_CONNECTION'),
            'env_exists' => array_key_exists('DB_CONNECTION', $_ENV),
            'env' => $_ENV['DB_CONNECTION'] ?? null,
            'server_exists' => array_key_exists('DB_CONNECTION', $_SERVER),
            'server' => $_SERVER['DB_CONNECTION'] ?? null,
        ];

        putenv('DB_CONNECTION=host');
        $_ENV['DB_CONNECTION'] = 'host';
        $_SERVER['DB_CONNECTION'] = 'host';
    }

    private function restoreDatabaseConnectionEnvironment(): void
    {
        if ($this->previousDatabaseConnectionEnvironment === []) {
            return;
        }

        $previous = $this->previousDatabaseConnectionEnvironment;

        if ($previous['getenv'] === false) {
            putenv('DB_CONNECTION');
        } else {
            putenv('DB_CONNECTION='.$previous['getenv']);
        }

        if ($previous['env_exists']) {
            $_ENV['DB_CONNECTION'] = $previous['env'];
        } else {
            unset($_ENV['DB_CONNECTION']);
        }

        if ($previous['server_exists']) {
            $_SERVER['DB_CONNECTION'] = $previous['server'];
        } else {
            unset($_SERVER['DB_CONNECTION']);
        }

        $this->previousDatabaseConnectionEnvironment = [];
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

    private function seedPausedSagaRun(): WorkflowRun
    {
        $workflowId = 'sagas-python-operator_visible_mid_compensation_status';
        $runId = '01kv0pat8e62bayexh5jscy4b5';

        $instance = WorkflowInstance::query()->create([
            'id' => $workflowId,
            'workflow_class' => 'PythonBookTripWorkflow',
            'workflow_type' => 'python.book-trip',
            'namespace' => 'default',
            'run_count' => 1,
            'started_at' => now()->subMinutes(2),
        ]);

        $run = WorkflowRun::query()->create([
            'id' => $runId,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'PythonBookTripWorkflow',
            'workflow_type' => 'python.book-trip',
            'namespace' => 'default',
            'status' => 'waiting',
            'closed_reason' => null,
            'closed_at' => null,
            'compatibility' => 'python-v1',
            'connection' => 'redis',
            'queue' => 'sagas-python',
            'started_at' => now()->subMinutes(2),
            'last_progress_at' => now()->subSeconds(5),
            'last_history_sequence' => 1,
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        WorkflowHistoryEvent::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => 'ActivityStarted',
            'payload' => [
                'activity_execution_id' => 'pause-after-refund-activity',
                'activity_type' => 'pause_after_refund',
                'activity_class' => 'PauseAfterRefundActivity',
                'activity' => [
                    'id' => 'pause-after-refund-activity',
                    'idempotency_key' => 'pause-after-refund-activity',
                    'sequence' => 1,
                    'type' => 'pause_after_refund',
                    'class' => 'PauseAfterRefundActivity',
                ],
            ],
            'recorded_at' => now()->subSeconds(5),
        ]);

        return $run->refresh();
    }

    private function seedActiveWorkerRegistration(): void
    {
        DB::connection('server_storage')->table('workflow_worker_registrations')->insert([
            'worker_id' => 'python-sagas-worker',
            'namespace' => 'default',
            'task_queue' => 'sagas-python',
            'runtime' => 'python',
            'sdk_version' => '0.4.88',
            'build_id' => 'python-v1',
            'supported_workflow_types' => json_encode(['python.book-trip']),
            'workflow_definition_fingerprints' => json_encode([]),
            'supported_activity_types' => json_encode(['pause_after_refund']),
            'max_concurrent_workflow_tasks' => 10,
            'max_concurrent_activity_tasks' => 10,
            'max_concurrent_worker_sessions' => null,
            'available_workflow_slots' => 9,
            'available_activity_slots' => 9,
            'available_session_slots' => null,
            'process_metrics' => json_encode([]),
            'heartbeat_interval_seconds' => 60,
            'last_heartbeat_at' => now(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
            '01JWVPKGHOSTV1RUN000001',
            'completed',
            'build-v1',
            now()->subMinutes(6),
        );
        $promotedRun = $this->seedWorkerVersioningRun(
            'worker-versioning-v2-instance',
            '01JWVPKGHOSTV2RUN000001',
            'completed',
            'build-v2',
            now()->subMinutes(2),
        );
        $noCompatibleRun = $this->seedWorkerVersioningRun(
            'worker-versioning-v3-instance',
            '01JWVPKGHOSTV3RUN000001',
            'waiting',
            'build-v3',
            null,
        );

        WorkflowTask::create([
            'id' => '01JWVPKGHOSTTASK0000001',
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
        $payload = $this->getJson(
            '/waterline/api/instances/'
            .rawurlencode($run->workflow_instance_id)
            .'/runs/'
            .rawurlencode($run->id)
            .'?history_limit=all',
        )
            ->assertOk()
            ->assertJsonPath('id', $run->id)
            ->assertJsonPath('workflow_instance_id', $run->workflow_instance_id)
            ->assertJsonPath('workflow_run_id', $run->id)
            ->assertJsonPath('instance_id', $run->workflow_instance_id)
            ->assertJsonPath('run_id', $run->id)
            ->assertJsonPath('selected_run_id', $run->id)
            ->assertJsonPath('namespace', 'worker-versioning-conformance')
            ->assertJsonPath('status', $status)
            ->assertJsonPath('status_bucket', $statusBucket)
            ->assertJsonPath('compatibility', $compatibility)
            ->assertJsonPath('connection', 'redis')
            ->assertJsonPath('queue', 'worker-versioning-shared')
            ->json();

        return $payload;
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

    private function createServerWorkerRegistrationsTable(): void
    {
        $schema = Schema::connection('server_storage');

        if ($schema->hasTable('workflow_worker_registrations')) {
            return;
        }

        $schema->create('workflow_worker_registrations', static function (Blueprint $table): void {
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
        $schema = Schema::connection('server_storage');

        if ($schema->hasTable('workflow_worker_build_id_rollouts')) {
            return;
        }

        $schema->create('workflow_worker_build_id_rollouts', static function (Blueprint $table): void {
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
            $table->string('compatibility_policy', 32)->nullable();
            $table->json('workflow_types')->nullable();
            $table->timestamps();

            $table->unique(
                ['namespace', 'task_queue', 'build_id'],
                'worker_build_rollouts_ns_queue_build_uq',
            );
            $table->index(
                ['namespace', 'task_queue', 'drain_intent'],
                'worker_build_rollouts_ns_queue_drain_idx',
            );
        });
    }
}
