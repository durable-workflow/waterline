<?php

namespace Waterline\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Waterline\Models\WorkerBuildIdRollout;
use Waterline\Tests\TestCase;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use function Orchestra\Testbench\artisan;

class PackageInstalledSharedStorageHostTest extends TestCase
{
    private ?string $temporaryDirectory = null;

    private ?string $hostDatabase = null;

    private ?string $serverDatabase = null;

    protected function getEnvironmentSetUp($app)
    {
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

        $this->createServerWorkerRegistrationsTable();
        $this->createServerWorkerBuildIdRolloutsTable();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ([$this->hostDatabase, $this->serverDatabase] as $path) {
            if (is_string($path) && is_file($path)) {
                @unlink($path);
            }
        }

        if (is_string($this->temporaryDirectory) && is_dir($this->temporaryDirectory)) {
            @rmdir($this->temporaryDirectory);
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
