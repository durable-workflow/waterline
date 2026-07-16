<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class RealComposerLaravelPackageHostWorkerVersioningTest extends TestCase
{
    private const NAMESPACE = 'worker-versioning-conformance';

    private const TASK_QUEUE = 'worker-versioning-shared';

    private ?string $temporaryDirectory = null;

    private ?string $hostDatabase = null;

    private ?string $database = null;

    private ?string $databaseUsernameOverride = null;

    private ?string $databasePasswordOverride = null;

    /**
     * @var resource|null
     */
    private $serverProcess = null;

    /**
     * @var array<int, resource>
     */
    private array $serverPipes = [];

    protected function setUp(): void
    {
        parent::setUp();

        if ((string) getenv('DB_CONNECTION') !== 'mysql') {
            $this->markTestSkipped('The real Laravel package-host regression runs in the MySQL profile.');
        }

        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('The real Laravel package-host regression requires pdo_mysql.');
        }

        try {
            $this->runCommand(['composer', '--version'], sys_get_temp_dir(), [], 30);
        } catch (\RuntimeException $exception) {
            $this->markTestSkipped('Composer is not available: '.$exception->getMessage());
        }

        $this->temporaryDirectory = sys_get_temp_dir()
            .'/waterline-real-laravel-host-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($this->temporaryDirectory, 0777, true);

        $this->hostDatabase = 'waterline_real_host_app_'.getmypid().'_'.bin2hex(random_bytes(4));
        $this->database = 'waterline_real_host_workflow_'.getmypid().'_'.bin2hex(random_bytes(4));
        $this->createDatabase($this->hostDatabase);
        $this->createDatabase($this->database);
    }

    protected function tearDown(): void
    {
        $this->stopServer();

        foreach ([$this->hostDatabase, $this->database] as $database) {
            if ($database !== null) {
                $this->dropDatabase($database);
            }
        }

        $this->removeDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function testComposerCreatedLaravelHostServesWaterlineV2JsonFromEnvOnlyConfiguration(): void
    {
        $appDirectory = $this->temporaryDirectory.'/app';
        $this->createLaravelHost($appDirectory);
        $this->installPackagesThroughComposer($appDirectory);
        $this->cacheInstallTimeLaravelConfiguration($appDirectory);

        $environment = $this->hostEnvironment();
        $this->seedWorkerVersioningTopology();
        $this->assertFalse(
            $this->tableExists($this->databaseConnection((string) $this->hostDatabase), 'workflow_runs'),
            'The real Laravel host application database must not contain workflow runtime tables.',
        );
        $this->assertTrue(
            $this->tableExists($this->databaseConnection((string) $this->database), 'workflow_runs'),
            'The published server workflow database must contain workflow runtime tables.',
        );

        $port = $this->freePort();
        $baseUrl = 'http://127.0.0.1:'.$port;
        $environment['APP_URL'] = $baseUrl;

        $this->startServer($appDirectory, $port, $environment);
        $health = $this->waitForJson($baseUrl.'/waterline/api/v2/health', 200);

        $this->assertSame(self::NAMESPACE, $health['namespace'] ?? null);
        $this->assertSame(true, $health['engine_source']['uses_v2'] ?? null);
        $this->assertSame('mysql', $health['engine_source']['storage_connection']['default_connection'] ?? null);
        $this->assertSame('waterline_workflow', $health['engine_source']['storage_connection']['configured'] ?? null);
        $this->assertSame('waterline_workflow', $health['engine_source']['storage_connection']['effective_connection'] ?? null);
        $this->assertSame(true, $health['engine_source']['storage_connection']['core_tables_available'] ?? null);
        $this->assertSame('available', $health['engine_source']['storage_connection']['core_table_status'] ?? null);
        $this->assertStorageConnectionDiagnosticsRedacted(
            $health['engine_source']['storage_connection'] ?? [],
            [
                $this->hostDatabase,
                $this->database,
                $this->databaseHost(),
                $this->databasePort(),
            ],
        );
        $workflowConnection = $this->firstWhere(
            $health['engine_source']['storage_connection']['connections'] ?? [],
            'name',
            'waterline_workflow',
        );
        $this->assertIsArray($workflowConnection);
        $this->assertSame('available', $workflowConnection['core_table_status'] ?? null);
        $this->assertSame(true, $health['queue_visibility']['available'] ?? null);

        $queue = $this->firstWhere($health['queue_visibility']['task_queues'] ?? [], 'task_queue', self::TASK_QUEUE);
        $this->assertIsArray($queue);
        $this->assertSame('build-v2', $queue['rollout_state']['selected_new_start_build_id'] ?? null);
        $this->assertContains('build-v1', $health['worker_versioning']['worker_cohorts'] ?? []);
        $this->assertContains('build-v2', $health['worker_versioning']['worker_cohorts'] ?? []);

        $buildV3 = $this->firstWhere($queue['build_ids'] ?? [], 'build_id', 'build-v3');
        $this->assertIsArray($buildV3);
        $this->assertSame('no_compatible_worker', $buildV3['pending_workflow_tasks']['status'] ?? null);

        $running = $this->requestJson('GET', $baseUrl.'/waterline/api/flows/running', expectedStatus: 200);
        $noCompatibleRow = $this->listRowForRun($running, 'worker-versioning-v3-instance', '01JWVFRESHV3RUN0000001');
        $this->assertSame('waiting', $noCompatibleRow['status'] ?? null);
        $this->assertSame('running', $noCompatibleRow['status_bucket'] ?? null);
        $this->assertSame('build-v3', $noCompatibleRow['compatibility'] ?? null);

        $completed = $this->requestJson('GET', $baseUrl.'/waterline/api/flows/completed', expectedStatus: 200);
        $this->assertSame(
            'build-v1',
            $this->listRowForRun($completed, 'worker-versioning-v1-instance', '01JWVFRESHV1RUN0000001')['compatibility'] ?? null,
        );
        $this->assertSame(
            'build-v2',
            $this->listRowForRun($completed, 'worker-versioning-v2-instance', '01JWVFRESHV2RUN0000001')['compatibility'] ?? null,
        );

        $this->assertSelectedRunDetail($baseUrl, 'worker-versioning-v1-instance', '01JWVFRESHV1RUN0000001', 'completed', 'completed', 'build-v1');
        $this->assertSelectedRunDetail($baseUrl, 'worker-versioning-v2-instance', '01JWVFRESHV2RUN0000001', 'completed', 'completed', 'build-v2');
        $noCompatibleDetail = $this->assertSelectedRunDetail(
            $baseUrl,
            'worker-versioning-v3-instance',
            '01JWVFRESHV3RUN0000001',
            'waiting',
            'running',
            'build-v3',
        );

        $this->assertIsArray(
            $this->firstWhere($noCompatibleDetail['run_diagnostics'] ?? [], 'code', 'no_compatible_worker_for_task'),
        );

        $missing = $this->requestJson(
            'GET',
            $baseUrl.'/waterline/api/instances/missing-workflow/runs/01JWVMISSINGRUN00000000001',
            expectedStatus: 404,
        );
        $this->assertSame('not_found', $missing['error'] ?? null);

        $csrfHeaders = $this->csrfHeaders($baseUrl);
        $validation = $this->requestJson(
            'PUT',
            $baseUrl.'/waterline/api/preferences/workflow-list',
            ['preferences' => ['row_density' => 'wide']],
            expectedStatus: 422,
            headers: $csrfHeaders,
        );
        $this->assertArrayHasKey('errors', $validation);
        $this->assertArrayHasKey('preferences.row_density', $validation['errors'] ?? []);
        $this->assertArrayNotHasKey('error', $validation);
    }

    private function createLaravelHost(string $appDirectory): void
    {
        $this->runCommand(
            ['composer', 'create-project', '--no-interaction', '--no-progress', 'laravel/laravel', $appDirectory],
            $this->temporaryDirectory,
            $this->composerEnvironment(),
            360,
        );
    }

    private function installPackagesThroughComposer(string $appDirectory): void
    {
        $waterlineRoot = dirname(__DIR__, 2);
        $workflowRoot = dirname($waterlineRoot).'/workflow';

        $this->runCommand(['composer', 'config', 'minimum-stability', 'dev'], $appDirectory, $this->composerEnvironment(), 30);
        $this->runCommand(['composer', 'config', 'prefer-stable', 'true'], $appDirectory, $this->composerEnvironment(), 30);
        $this->runCommand(
            [
                'composer',
                'config',
                'repositories.waterline',
                json_encode([
                    'type' => 'path',
                    'url' => $waterlineRoot,
                    'options' => [
                        'symlink' => false,
                        'versions' => [
                            'durable-workflow/waterline' => '2.0.0-alpha.999',
                        ],
                    ],
                ], JSON_UNESCAPED_SLASHES),
            ],
            $appDirectory,
            $this->composerEnvironment(),
            30,
        );

        $workflowRequirement = 'durable-workflow/workflow:^2.0.0-alpha';
        if (is_file($workflowRoot.'/composer.json')) {
            $this->runCommand(
                [
                    'composer',
                    'config',
                    'repositories.workflow',
                    json_encode([
                        'type' => 'path',
                        'url' => $workflowRoot,
                        'options' => [
                            'symlink' => false,
                            'versions' => [
                                'durable-workflow/workflow' => '2.0.0-alpha.999',
                            ],
                        ],
                    ], JSON_UNESCAPED_SLASHES),
                ],
                $appDirectory,
                $this->composerEnvironment(),
                30,
            );
            $workflowRequirement = 'durable-workflow/workflow:2.0.0-alpha.999';
        }

        $this->runCommand(
            [
                'composer',
                'require',
                '--no-interaction',
                '--no-progress',
                $workflowRequirement,
                'durable-workflow/waterline:2.0.0-alpha.999',
            ],
            $appDirectory,
            $this->composerEnvironment(),
            360,
        );
    }

    private function cacheInstallTimeLaravelConfiguration(string $appDirectory): void
    {
        @touch($appDirectory.'/database/database.sqlite');

        $this->runCommand(
            [$this->phpBinary(), 'artisan', 'config:cache', '--no-interaction'],
            $appDirectory,
            array_merge($this->composerEnvironment(), [
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $appDirectory.'/database/database.sqlite',
                'DW_V2_TASK_DISPATCH_MODE' => 'queue',
                'WATERLINE_ALLOW_UNAUTHENTICATED' => 'false',
                'WATERLINE_ENGINE_SOURCE' => 'auto',
                'WATERLINE_HEALTH_TASK_DISPATCH_MODE' => 'queue',
                'WATERLINE_NAMESPACE' => '',
            ]),
            120,
        );
    }

    /**
     * @return array<string, string>
     */
    private function composerEnvironment(): array
    {
        $home = $this->temporaryDirectory.'/composer-home';
        if (! is_dir($home)) {
            mkdir($home, 0777, true);
        }

        return [
            'COMPOSER_ALLOW_SUPERUSER' => '1',
            'COMPOSER_HOME' => $home,
            'COMPOSER_MEMORY_LIMIT' => '-1',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function hostEnvironment(): array
    {
        return [
            'APP_ENV' => 'local',
            'APP_DEBUG' => 'false',
            'APP_KEY' => 'base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI=',
            'APP_URL' => 'http://127.0.0.1',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $this->databaseHost(),
            'DB_PORT' => $this->databasePort(),
            'DB_DATABASE' => (string) $this->hostDatabase,
            'DB_USERNAME' => $this->databaseUsername(),
            'DB_PASSWORD' => $this->databasePassword(),
            'DW_WV_WATERLINE_DB_HOST' => $this->databaseHost(),
            'DW_WV_WATERLINE_DB_PORT' => $this->databasePort(),
            'DW_WV_WATERLINE_DB_DATABASE' => (string) $this->database,
            'DW_WV_WATERLINE_DB_USERNAME' => $this->databaseUsername(),
            'DW_WV_WATERLINE_DB_PASSWORD' => $this->databasePassword(),
            'QUEUE_CONNECTION' => 'sync',
            'CACHE_DRIVER' => 'array',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'WATERLINE_ALLOW_UNAUTHENTICATED' => 'true',
            'WATERLINE_ENGINE_SOURCE' => 'v2',
            'WATERLINE_HEALTH_TASK_DISPATCH_MODE' => 'poll',
            'WATERLINE_NAMESPACE' => self::NAMESPACE,
            'DW_V2_TASK_DISPATCH_MODE' => 'poll',
        ];
    }

    private function seedWorkerVersioningTopology(): void
    {
        $pdo = $this->databaseConnection((string) $this->database);
        $this->createServerWorkflowTables($pdo);
        $this->createServerWorkerTables($pdo);

        $now = gmdate('Y-m-d H:i:s');
        $oneMinuteAgo = gmdate('Y-m-d H:i:s', time() - 60);
        $fiveMinutesAgo = gmdate('Y-m-d H:i:s', time() - 300);
        $tenMinutesAgo = gmdate('Y-m-d H:i:s', time() - 600);
        $this->insert($pdo, 'workflow_worker_registrations', [
            'worker_id' => 'wv-v1-worker',
            'namespace' => self::NAMESPACE,
            'task_queue' => self::TASK_QUEUE,
            'runtime' => 'php',
            'sdk_version' => '2.0.0-alpha.999',
            'build_id' => 'build-v1',
            'supported_workflow_types' => ['workflow.worker-versioning'],
            'workflow_definition_fingerprints' => [],
            'supported_activity_types' => ['activity.worker-versioning'],
            'max_concurrent_workflow_tasks' => 8,
            'max_concurrent_activity_tasks' => 4,
            'max_concurrent_worker_sessions' => 10,
            'available_workflow_slots' => 7,
            'available_activity_slots' => 3,
            'available_session_slots' => null,
            'process_metrics' => [
                'process_id' => 1001,
                'host' => self::NAMESPACE,
                'process_started_at' => gmdate('c', time() - 300),
                'process_uptime_seconds' => 300,
            ],
            'heartbeat_interval_seconds' => 60,
            'last_heartbeat_at' => gmdate('Y-m-d H:i:s', time() - 10),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->insert($pdo, 'workflow_worker_registrations', [
            'worker_id' => 'wv-v2-worker',
            'namespace' => self::NAMESPACE,
            'task_queue' => self::TASK_QUEUE,
            'runtime' => 'python',
            'sdk_version' => '0.4.89',
            'build_id' => 'build-v2',
            'supported_workflow_types' => ['workflow.worker-versioning'],
            'workflow_definition_fingerprints' => [],
            'supported_activity_types' => ['activity.worker-versioning'],
            'max_concurrent_workflow_tasks' => 8,
            'max_concurrent_activity_tasks' => 4,
            'max_concurrent_worker_sessions' => 10,
            'available_workflow_slots' => 7,
            'available_activity_slots' => 3,
            'available_session_slots' => null,
            'process_metrics' => [
                'process_id' => 1002,
                'host' => self::NAMESPACE,
                'process_started_at' => gmdate('c', time() - 300),
                'process_uptime_seconds' => 300,
            ],
            'heartbeat_interval_seconds' => 60,
            'last_heartbeat_at' => gmdate('Y-m-d H:i:s', time() - 5),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->insert($pdo, 'workflow_worker_build_id_rollouts', [
            'namespace' => self::NAMESPACE,
            'task_queue' => self::TASK_QUEUE,
            'build_id' => 'build-v1',
            'drain_intent' => 'active',
            'promoted_at' => $tenMinutesAgo,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->insert($pdo, 'workflow_worker_build_id_rollouts', [
            'namespace' => self::NAMESPACE,
            'task_queue' => self::TASK_QUEUE,
            'build_id' => 'build-v2',
            'drain_intent' => 'active',
            'promoted_at' => $oneMinuteAgo,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->seedRun($pdo, 'worker-versioning-v1-instance', '01JWVFRESHV1RUN0000001', 'completed', 'build-v1', '2026-04-09 11:54:00');
        $this->seedRun($pdo, 'worker-versioning-v2-instance', '01JWVFRESHV2RUN0000001', 'completed', 'build-v2', '2026-04-09 11:58:00');
        $this->seedRun($pdo, 'worker-versioning-v3-instance', '01JWVFRESHV3RUN0000001', 'waiting', 'build-v3', null);

        $this->insert($pdo, 'workflow_tasks', [
            'id' => '01JWVFRESHTASK0000001',
            'workflow_run_id' => '01JWVFRESHV3RUN0000001',
            'namespace' => self::NAMESPACE,
            'task_type' => 'workflow',
            'status' => 'ready',
            'compatibility' => 'build-v3',
            'payload' => [],
            'connection' => 'redis',
            'queue' => self::TASK_QUEUE,
            'available_at' => $oneMinuteAgo,
            'created_at' => $fiveMinutesAgo,
            'updated_at' => $oneMinuteAgo,
        ]);
    }

    private function seedRun(PDO $pdo, string $workflowId, string $runId, string $status, string $compatibility, ?string $closedAt): void
    {
        $this->insert($pdo, 'workflow_instances', [
            'id' => $workflowId,
            'workflow_class' => 'WorkerVersioningWorkflow',
            'workflow_type' => 'workflow.worker-versioning',
            'namespace' => self::NAMESPACE,
            'current_run_id' => $runId,
            'run_count' => 1,
            'started_at' => '2026-04-09 11:48:00',
            'created_at' => '2026-04-09 11:48:00',
            'updated_at' => '2026-04-09 12:00:00',
        ]);

        $this->insert($pdo, 'workflow_runs', [
            'id' => $runId,
            'workflow_instance_id' => $workflowId,
            'run_number' => 1,
            'workflow_class' => 'WorkerVersioningWorkflow',
            'workflow_type' => 'workflow.worker-versioning',
            'namespace' => self::NAMESPACE,
            'status' => $status,
            'closed_reason' => $closedAt === null ? null : $status,
            'closed_at' => $closedAt,
            'compatibility' => $compatibility,
            'connection' => 'redis',
            'queue' => self::TASK_QUEUE,
            'started_at' => '2026-04-09 11:48:00',
            'last_progress_at' => $closedAt ?? '2026-04-09 11:59:00',
            'last_history_sequence' => $closedAt === null ? 1 : 2,
            'created_at' => '2026-04-09 11:48:00',
            'updated_at' => '2026-04-09 12:00:00',
        ]);

        $this->insert($pdo, 'workflow_history_events', [
            'id' => $this->eventId($runId, 1),
            'workflow_run_id' => $runId,
            'sequence' => 1,
            'event_type' => 'WorkflowStarted',
            'payload' => [
                'workflow_type' => 'workflow.worker-versioning',
                'compatibility' => $compatibility,
            ],
            'recorded_at' => '2026-04-09 11:48:00',
            'created_at' => '2026-04-09 11:48:00',
            'updated_at' => '2026-04-09 11:48:00',
        ]);

        if ($closedAt !== null) {
            $this->insert($pdo, 'workflow_history_events', [
                'id' => $this->eventId($runId, 2),
                'workflow_run_id' => $runId,
                'sequence' => 2,
                'event_type' => 'WorkflowCompleted',
                'payload' => [
                    'status' => $status,
                    'compatibility' => $compatibility,
                ],
                'recorded_at' => $closedAt,
                'created_at' => $closedAt,
                'updated_at' => $closedAt,
            ]);
        }
    }

    private function eventId(string $runId, int $sequence): string
    {
        return substr(str_pad($runId, 24, '0'), 0, 24).$sequence;
    }

    private function createServerWorkflowTables(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS workflow_instances (
                id VARCHAR(191) NOT NULL PRIMARY KEY,
                workflow_class VARCHAR(255) NOT NULL,
                workflow_type VARCHAR(255) NOT NULL,
                namespace VARCHAR(128) NULL,
                business_key VARCHAR(191) NULL,
                visibility_labels JSON NULL,
                memo JSON NULL,
                current_run_id VARCHAR(26) NULL,
                run_count INT UNSIGNED NOT NULL DEFAULT 0,
                started_at TIMESTAMP NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                KEY workflow_instances_namespace_index (namespace),
                KEY workflow_instances_current_run_id_index (current_run_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS workflow_runs (
                id VARCHAR(26) NOT NULL PRIMARY KEY,
                workflow_instance_id VARCHAR(191) NOT NULL,
                run_number INT UNSIGNED NOT NULL,
                workflow_class VARCHAR(255) NOT NULL,
                workflow_type VARCHAR(255) NOT NULL,
                namespace VARCHAR(128) NULL,
                business_key VARCHAR(191) NULL,
                visibility_labels JSON NULL,
                status VARCHAR(32) NOT NULL,
                closed_reason VARCHAR(32) NULL,
                compatibility VARCHAR(255) NULL,
                payload_codec VARCHAR(64) NULL,
                arguments LONGTEXT NULL,
                output LONGTEXT NULL,
                connection VARCHAR(255) NULL,
                queue VARCHAR(255) NULL,
                last_history_sequence INT UNSIGNED NOT NULL DEFAULT 0,
                last_command_sequence INT UNSIGNED NOT NULL DEFAULT 0,
                message_cursor_position INT UNSIGNED NOT NULL DEFAULT 0,
                started_at TIMESTAMP NULL,
                closed_at TIMESTAMP NULL,
                archived_at TIMESTAMP NULL,
                archive_command_id VARCHAR(26) NULL,
                archive_reason VARCHAR(255) NULL,
                last_progress_at TIMESTAMP NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                KEY workflow_runs_instance_index (workflow_instance_id),
                KEY workflow_runs_namespace_index (namespace),
                KEY workflow_runs_status_index (status),
                KEY workflow_runs_last_progress_index (last_progress_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS workflow_history_events (
                id VARCHAR(26) NOT NULL PRIMARY KEY,
                workflow_run_id VARCHAR(26) NOT NULL,
                sequence INT UNSIGNED NOT NULL,
                event_type VARCHAR(255) NOT NULL,
                payload JSON NULL,
                recorded_at TIMESTAMP NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                KEY workflow_history_events_run_index (workflow_run_id),
                UNIQUE KEY workflow_history_events_run_sequence_unique (workflow_run_id, sequence)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS workflow_tasks (
                id VARCHAR(26) NOT NULL PRIMARY KEY,
                workflow_run_id VARCHAR(26) NOT NULL,
                namespace VARCHAR(128) NULL,
                task_type VARCHAR(32) NOT NULL,
                status VARCHAR(32) NOT NULL,
                compatibility VARCHAR(255) NULL,
                payload JSON NULL,
                connection VARCHAR(255) NULL,
                queue VARCHAR(255) NULL,
                available_at TIMESTAMP NULL,
                leased_at TIMESTAMP NULL,
                lease_owner VARCHAR(255) NULL,
                lease_expires_at TIMESTAMP NULL,
                attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
                last_dispatch_attempt_at TIMESTAMP NULL,
                last_dispatched_at TIMESTAMP NULL,
                last_dispatch_error TEXT NULL,
                last_claim_failed_at TIMESTAMP NULL,
                last_claim_error TEXT NULL,
                repair_count INT UNSIGNED NOT NULL DEFAULT 0,
                repair_available_at TIMESTAMP NULL,
                last_error TEXT NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                KEY workflow_tasks_run_index (workflow_run_id),
                KEY workflow_tasks_status_available_index (status, available_at),
                KEY workflow_tasks_namespace_queue_index (namespace, queue)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    private function createServerWorkerTables(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS workflow_worker_registrations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                worker_id VARCHAR(255) NOT NULL,
                namespace VARCHAR(128) NOT NULL,
                task_queue VARCHAR(255) NOT NULL,
                runtime VARCHAR(32) NOT NULL,
                sdk_version VARCHAR(64) NULL,
                build_id VARCHAR(255) NULL,
                supported_workflow_types JSON NULL,
                workflow_definition_fingerprints JSON NULL,
                workflow_command_contracts JSON NULL,
                supported_activity_types JSON NULL,
                capabilities JSON NULL,
                max_concurrent_workflow_tasks INT UNSIGNED NOT NULL DEFAULT 100,
                max_concurrent_activity_tasks INT UNSIGNED NOT NULL DEFAULT 100,
                max_concurrent_worker_sessions INT UNSIGNED NOT NULL DEFAULT 10,
                available_workflow_slots INT UNSIGNED NULL,
                available_activity_slots INT UNSIGNED NULL,
                available_session_slots INT UNSIGNED NULL,
                process_metrics JSON NULL,
                heartbeat_interval_seconds INT UNSIGNED NULL,
                last_heartbeat_at TIMESTAMP NULL,
                status VARCHAR(32) NOT NULL DEFAULT "active",
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                UNIQUE KEY worker_namespace_unique (worker_id, namespace),
                KEY worker_scope_index (namespace, task_queue, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS workflow_worker_build_id_rollouts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                namespace VARCHAR(128) NOT NULL,
                task_queue VARCHAR(255) NOT NULL,
                build_id VARCHAR(255) NOT NULL DEFAULT "",
                drain_intent VARCHAR(32) NOT NULL DEFAULT "active",
                drained_at TIMESTAMP NULL,
                promoted_at TIMESTAMP NULL,
                rolled_back_at TIMESTAMP NULL,
                required_compatibility VARCHAR(255) NULL,
                recorded_fingerprint VARCHAR(255) NULL,
                compatibility_policy JSON NULL,
                workflow_types JSON NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                UNIQUE KEY workflow_build_id_rollouts_scope_unique (namespace, task_queue, build_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insert(PDO $pdo, string $table, array $row): void
    {
        $columns = array_keys($row);
        $placeholders = array_map(static fn (string $column): string => ':'.$column, $columns);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            implode(', ', array_map([$this, 'quoteIdentifier'], $columns)),
            implode(', ', $placeholders),
        );

        $statement = $pdo->prepare($sql);
        foreach ($row as $column => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES);
            }

            if ($value === null) {
                $statement->bindValue(':'.$column, null, PDO::PARAM_NULL);
            } else {
                $statement->bindValue(':'.$column, $value);
            }
        }
        $statement->execute();
    }

    private function startServer(string $appDirectory, int $port, array $environment): void
    {
        $command = $this->shellCommand([
            $this->phpBinary(),
            '-d',
            'variables_order=GPCS',
            'artisan',
            'serve',
            '--host=127.0.0.1',
            '--port='.$port,
        ]);

        $this->serverProcess = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $this->serverPipes,
            $appDirectory,
            $this->processEnvironment($environment),
        );

        if (! is_resource($this->serverProcess)) {
            throw new \RuntimeException('Unable to start php artisan serve.');
        }

        fclose($this->serverPipes[0]);
        stream_set_blocking($this->serverPipes[1], false);
        stream_set_blocking($this->serverPipes[2], false);
    }

    private function stopServer(): void
    {
        foreach ($this->serverPipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->serverPipes = [];

        if (is_resource($this->serverProcess)) {
            proc_terminate($this->serverProcess);
            proc_close($this->serverProcess);
        }
        $this->serverProcess = null;
    }

    /**
     * @return array<string, mixed>
     */
    private function waitForJson(string $url, int $expectedStatus): array
    {
        $deadline = microtime(true) + 60;
        $lastError = null;

        do {
            try {
                return $this->requestJson('GET', $url, expectedStatus: $expectedStatus);
            } catch (\RuntimeException $exception) {
                $lastError = $exception;
                usleep(250000);
            }
        } while (microtime(true) < $deadline && $this->serverIsRunning());

        $output = $this->readServerOutput();

        throw new \RuntimeException(
            'The Laravel host did not return the expected JSON response: '
            .($lastError?->getMessage() ?? 'no response')
            ."\n".$output,
        );
    }

    private function serverIsRunning(): bool
    {
        if (! is_resource($this->serverProcess)) {
            return false;
        }

        $status = proc_get_status($this->serverProcess);

        return ($status['running'] ?? false) === true;
    }

    private function readServerOutput(): string
    {
        $output = '';
        foreach ([1, 2] as $index) {
            if (isset($this->serverPipes[$index]) && is_resource($this->serverPipes[$index])) {
                $output .= stream_get_contents($this->serverPipes[$index]);
            }
        }

        return $output;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function requestJson(
        string $method,
        string $url,
        array $payload = [],
        int $expectedStatus = 200,
        array $headers = [],
    ): array
    {
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Durable-Workflow-Control-Plane-Version: 2',
            ...$headers,
        ];

        $response = $this->httpRequest(
            $method,
            $url,
            $headers,
            $method === 'GET' ? null : json_encode($payload, JSON_UNESCAPED_SLASHES),
        );

        $status = $response['status'];
        $contentType = $this->headerValue($response['headers'], 'content-type');
        if ($status !== $expectedStatus) {
            throw new \RuntimeException(sprintf(
                'Expected HTTP %d from %s, got %d: %s',
                $expectedStatus,
                $url,
                $status,
                substr($response['body'], 0, 1000),
            ));
        }
        $this->assertStringContainsString('application/json', strtolower($contentType), $response['body']);

        $decoded = json_decode($response['body'], true);
        $this->assertIsArray($decoded, $response['body']);

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function csrfHeaders(string $baseUrl): array
    {
        $response = $this->httpRequest(
            'GET',
            $baseUrl.'/waterline',
            ['Accept: text/html'],
        );

        if ($response['status'] !== 200) {
            throw new \RuntimeException(sprintf(
                'Expected HTTP 200 from %s/waterline, got %d: %s',
                $baseUrl,
                $response['status'],
                substr($response['body'], 0, 1000),
            ));
        }

        if (preg_match('/<meta\s+name="csrf-token"\s+content="([^"]+)"/', $response['body'], $matches) !== 1) {
            throw new \RuntimeException('The Waterline dashboard did not expose a CSRF token meta tag.');
        }

        $cookies = $this->cookiesFromHeaders($response['headers']);
        if ($cookies === []) {
            throw new \RuntimeException('The Waterline dashboard did not start a session cookie.');
        }

        return [
            'X-CSRF-TOKEN: '.$matches[1],
            'Cookie: '.$this->cookieHeader($cookies),
        ];
    }

    /**
     * @param list<string> $headers
     * @return array<string, string>
     */
    private function cookiesFromHeaders(array $headers): array
    {
        $cookies = [];
        foreach ($headers as $header) {
            if (! str_starts_with(strtolower($header), 'set-cookie:')) {
                continue;
            }

            $cookie = trim(substr($header, strlen('set-cookie:')));
            $pair = explode(';', $cookie, 2)[0] ?? '';
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2 && trim($parts[0]) !== '') {
                $cookies[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $cookies;
    }

    /**
     * @param array<string, string> $cookies
     */
    private function cookieHeader(array $cookies): string
    {
        $pairs = [];
        foreach ($cookies as $name => $value) {
            $pairs[] = $name.'='.$value;
        }

        return implode('; ', $pairs);
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, headers: list<string>, body: string}
     */
    private function httpRequest(string $method, string $url, array $headers, ?string $content = null): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $content ?? '',
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new \RuntimeException('No HTTP response from '.$url);
        }

        $headers = $http_response_header ?? [];

        return [
            'status' => $this->statusCode($headers),
            'headers' => $headers,
            'body' => $response,
        ];
    }

    /**
     * @param array<int, string> $headers
     */
    private function statusCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return 0;
    }

    /**
     * @param array<int, string> $headers
     */
    private function headerValue(array $headers, string $name): string
    {
        $prefix = strtolower($name).':';
        foreach ($headers as $header) {
            if (str_starts_with(strtolower($header), $prefix)) {
                return trim(substr($header, strlen($prefix)));
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function assertSelectedRunDetail(
        string $baseUrl,
        string $workflowId,
        string $runId,
        string $status,
        string $statusBucket,
        string $compatibility,
    ): array {
        $payload = $this->requestJson(
            'GET',
            $baseUrl.'/waterline/api/instances/'.rawurlencode($workflowId).'/runs/'.rawurlencode($runId).'?history_limit=all',
            expectedStatus: 200,
        );

        $this->assertSame($runId, $payload['id'] ?? null);
        $this->assertSame($workflowId, $payload['workflow_instance_id'] ?? null);
        $this->assertSame($runId, $payload['workflow_run_id'] ?? null);
        $this->assertSame($workflowId, $payload['instance_id'] ?? null);
        $this->assertSame($runId, $payload['run_id'] ?? null);
        $this->assertSame($runId, $payload['selected_run_id'] ?? null);
        $this->assertSame(self::NAMESPACE, $payload['namespace'] ?? null);
        $this->assertSame($status, $payload['status'] ?? null);
        $this->assertSame($statusBucket, $payload['status_bucket'] ?? null);
        $this->assertSame($compatibility, $payload['compatibility'] ?? null);
        $this->assertSame('redis', $payload['connection'] ?? null);
        $this->assertSame(self::TASK_QUEUE, $payload['queue'] ?? null);

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function listRowForRun(array $payload, string $workflowId, string $runId): array
    {
        $row = null;
        foreach ($payload['data'] ?? [] as $item) {
            if (is_array($item)
                && ($item['workflow_instance_id'] ?? null) === $workflowId
                && ($item['run_id'] ?? null) === $runId) {
                $row = $item;
                break;
            }
        }

        $this->assertIsArray($row);

        return $row;
    }

    /**
     * @param mixed $items
     * @return array<string, mixed>|null
     */
    private function firstWhere(mixed $items, string $key, string $value): ?array
    {
        if (! is_array($items)) {
            return null;
        }

        foreach ($items as $item) {
            if (is_array($item) && ($item[$key] ?? null) === $value) {
                return $item;
            }
        }

        return null;
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

    /**
     * @param array<int, string> $command
     * @param array<string, string> $environment
     */
    private function runCommand(array $command, string $workingDirectory, array $environment = [], int $timeoutSeconds = 120): string
    {
        $process = proc_open(
            $this->shellCommand($command),
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $workingDirectory,
            $this->processEnvironment($environment),
        );

        if (! is_resource($process)) {
            throw new \RuntimeException('Unable to start command: '.implode(' ', $command));
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $deadline = microtime(true) + $timeoutSeconds;
        $observedExitCode = null;
        do {
            $output .= stream_get_contents($pipes[1]);
            $output .= stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (($status['running'] ?? false) !== true) {
                $observedExitCode = is_int($status['exitcode'] ?? null) ? $status['exitcode'] : null;
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process);
                throw new \RuntimeException('Command timed out: '.implode(' ', $command)."\n".$output);
            }
            usleep(100000);
        } while (true);

        $output .= stream_get_contents($pipes[1]);
        $output .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode === -1 && $observedExitCode !== null) {
            $exitCode = $observedExitCode;
        }
        if ($exitCode !== 0) {
            throw new \RuntimeException(sprintf(
                "Command failed with exit code %d: %s\n%s",
                $exitCode,
                implode(' ', $command),
                $output,
            ));
        }

        return $output;
    }

    /**
     * @param array<int, string> $command
     */
    private function shellCommand(array $command): string
    {
        return implode(' ', array_map('escapeshellarg', $command));
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function processEnvironment(array $overrides): array
    {
        $base = getenv();
        $base = is_array($base) ? $base : [];

        if (! array_key_exists('SESSION_DRIVER', $overrides)) {
            unset($base['SESSION_DRIVER']);
        }

        return array_merge($base, $overrides);
    }

    private function createDatabase(string $database): void
    {
        try {
            $this->databaseConnection()->exec(
                'CREATE DATABASE '.$this->quoteIdentifier($database).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            );

            return;
        } catch (PDOException $exception) {
            $configuredUserException = $exception;
        }

        foreach ($this->rootPasswordCandidates() as $password) {
            $this->databaseUsernameOverride = 'root';
            $this->databasePasswordOverride = $password;

            try {
                $this->databaseConnection()->exec(
                    'CREATE DATABASE '.$this->quoteIdentifier($database).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                );

                return;
            } catch (PDOException) {
                $this->databaseUsernameOverride = null;
                $this->databasePasswordOverride = null;
            }
        }

        $this->markTestSkipped(
            'Unable to create a temporary MySQL database: '.$configuredUserException->getMessage(),
        );
    }

    /**
     * @return list<string>
     */
    private function rootPasswordCandidates(): array
    {
        $candidates = [
            getenv('MYSQL_ROOT_PASSWORD'),
            getenv('DB_ROOT_PASSWORD'),
            $this->databasePassword(),
            '',
        ];

        $values = [];
        foreach ($candidates as $candidate) {
            if ($candidate === false) {
                continue;
            }

            $candidate = (string) $candidate;
            if (! in_array($candidate, $values, true)) {
                $values[] = $candidate;
            }
        }

        return $values;
    }

    private function dropDatabase(string $database): void
    {
        try {
            $this->databaseConnection()->exec('DROP DATABASE IF EXISTS '.$this->quoteIdentifier($database));
        } catch (PDOException) {
            // Best-effort cleanup for an integration-only database.
        }
    }

    private function databaseConnection(?string $database = null): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s%s;charset=utf8mb4',
            $this->databaseHost(),
            $this->databasePort(),
            $database === null ? '' : ';dbname='.$database,
        );

        return new PDO($dsn, $this->databaseUsername(), $this->databasePassword(), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name',
        );
        $statement->execute(['table_name' => $table]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function databaseHost(): string
    {
        return (string) (getenv('DB_HOST') ?: '127.0.0.1');
    }

    private function databasePort(): string
    {
        return (string) (getenv('DB_PORT') ?: '3306');
    }

    private function databaseUsername(): string
    {
        if ($this->databaseUsernameOverride !== null) {
            return $this->databaseUsernameOverride;
        }

        return (string) (getenv('DB_USERNAME') ?: 'testing');
    }

    private function databasePassword(): string
    {
        if ($this->databasePasswordOverride !== null) {
            return $this->databasePasswordOverride;
        }

        return (string) (getenv('DB_PASSWORD') ?: '');
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    private function phpBinary(): string
    {
        return PHP_BINARY;
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            throw new \RuntimeException('Unable to allocate a free port: '.$error);
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        if (! is_string($name) || preg_match('/:(\d+)$/', $name, $matches) !== 1) {
            throw new \RuntimeException('Unable to determine allocated port.');
        }

        return (int) $matches[1];
    }

    private function removeDirectory(?string $directory): void
    {
        if (! is_string($directory) || ! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($directory);
    }
}
