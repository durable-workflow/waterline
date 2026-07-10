<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use Exception;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Waterline\Repositories\Workflow\Infrastructure\HybridWorkflowRepository;
use Waterline\Repositories\Workflow\Infrastructure\V2WorkflowRepository;
use Waterline\Repositories\Workflow\Interfaces\WorkflowRepositoryInterface;
use Waterline\Tests\TestCase;
use Workflow\Models\StoredWorkflow;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Support\RunSummarySortKey;

final class V2HybridMigrationViewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.hybrid_migration_view', true);
        config()->set('waterline.namespace', null);
    }

    public function testNormalBucketsAndDetailsExposeBothEnginesWithCollisionSafeIds(): void
    {
        $legacyCompleted = $this->legacyWorkflow('LegacyCompletedWorkflow', 'completed');
        $legacyCompleted->logs()->create([
            'index' => 0,
            'now' => now(),
            'class' => 'LegacyActivity',
            'result' => Serializer::serialize('legacy-result'),
        ]);
        $legacyCompleted->signals()->create([
            'method' => 'approve',
            'arguments' => Serializer::serialize(['Taylor']),
        ]);
        $legacyCompleted->exceptions()->create([
            'class' => 'LegacyActivity',
            'exception' => Serializer::serialize(new Exception('legacy failure evidence')),
        ]);

        $legacyWaiting = $this->legacyWorkflow('LegacyWaitingWorkflow', 'waiting');
        $legacyFailed = $this->legacyWorkflow('LegacyFailedWorkflow', 'failed');

        $v2Completed = $this->v2Workflow(
            (string) $legacyCompleted->id,
            'migration-v2-completed',
            'V2CompletedWorkflow',
            'completed',
        );
        $v2Running = $this->v2Workflow(
            '01JHYBRIDMIGRATIONRUN00001',
            'migration-v2-running',
            'V2RunningWorkflow',
            'waiting',
        );

        $this->get('/waterline/api/flows/completed')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $v2Completed->id)
            ->assertJsonPath('data.0.engine_source', 'v2')
            ->assertJsonPath('data.1.id', 'v1:'.$legacyCompleted->id)
            ->assertJsonPath('data.1.legacy_id', $legacyCompleted->id)
            ->assertJsonPath('data.1.engine_source', 'v1')
            ->assertJsonPath('data.1.engine_version', '1.x')
            ->assertJsonPath('data.1.status', 'completed')
            ->assertJsonPath('hybrid_migration_view.active', true)
            ->assertJsonPath('hybrid_migration_view.identifier_collision_policy', 'v2_bare_id_precedence');

        $this->get('/waterline/api/flows/running')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment([
                'id' => $v2Running->id,
                'engine_source' => 'v2',
                'status' => 'waiting',
            ])
            ->assertJsonFragment([
                'id' => 'v1:'.$legacyWaiting->id,
                'legacy_id' => $legacyWaiting->id,
                'engine_source' => 'v1',
                'status' => 'waiting',
                'status_bucket' => 'running',
            ]);

        $this->get('/waterline/api/flows/failed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'v1:'.$legacyFailed->id)
            ->assertJsonPath('data.0.status', 'failed')
            ->assertJsonPath('data.0.status_bucket', 'failed');

        // A bare collision keeps the existing v2 run identity and route.
        $this->get('/waterline/api/flows/'.$legacyCompleted->id)
            ->assertOk()
            ->assertJsonPath('id', $v2Completed->id)
            ->assertJsonPath('instance_id', 'migration-v2-completed')
            ->assertJsonPath('engine_source', 'v2');

        $this->get('/waterline/api/instances/migration-v2-completed/runs/'.$v2Completed->id)
            ->assertOk()
            ->assertJsonPath('id', $v2Completed->id)
            ->assertJsonPath('instance_id', 'migration-v2-completed');

        // The qualified v1 identity remains stable even when its integer collides.
        $this->get('/waterline/api/flows/v1:'.$legacyCompleted->id)
            ->assertOk()
            ->assertJsonPath('id', 'v1:'.$legacyCompleted->id)
            ->assertJsonPath('legacy_id', $legacyCompleted->id)
            ->assertJsonPath('engine_source', 'v1')
            ->assertJsonPath('engine_version', '1.x')
            ->assertJsonPath('execution_engine', 'finish-on-v1')
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('status_bucket', 'completed')
            ->assertJsonPath('logs.0.class', 'LegacyActivity')
            ->assertJsonPath('signals.0.name', 'approve')
            ->assertJsonPath('signals.0.status', 'recorded')
            ->assertJsonPath('signals.0.outcome', 'legacy_signal_recorded')
            ->assertJsonPath('exceptions.0.class', 'LegacyActivity');

        // Without a collision, the old integer path remains a compatibility lookup.
        $this->get('/waterline/api/flows/'.$legacyWaiting->id)
            ->assertOk()
            ->assertJsonPath('id', 'v1:'.$legacyWaiting->id)
            ->assertJsonPath('status', 'waiting');

        $health = $this->get('/waterline/api/v2/health')
            ->assertOk()
            ->assertJsonPath('hybrid_migration_view.available', true)
            ->assertJsonPath('hybrid_migration_view.active', true)
            ->assertJsonPath('hybrid_migration_view.legacy_workflows_present', true)
            ->assertJsonPath('hybrid_migration_view.legacy_open_workflows_present', true)
            ->json();

        $migrationCheck = collect($health['checks'] ?? [])->firstWhere('name', 'hybrid_migration_view');
        $this->assertSame('ok', $migrationCheck['status'] ?? null);
        $this->assertTrue($migrationCheck['meta']['available'] ?? false);
    }

    public function testNamespaceScopedHealthExplainsWhyLegacyRowsCannotBeMerged(): void
    {
        $this->legacyWorkflow('LegacyWaitingWorkflow', 'waiting');
        config()->set('waterline.namespace', 'tenant-a');

        $this->get('/waterline/api/v2/health')
            ->assertOk()
            ->assertJsonPath('hybrid_migration_view.available', false)
            ->assertJsonPath('hybrid_migration_view.active', false)
            ->assertJsonPath('hybrid_migration_view.reason', 'namespace_scoped_view')
            ->assertJsonPath('hybrid_migration_view.legacy_workflows_present', true);
    }

    public function testQualifiedStringAndUuidLegacyIdsPreserveBareV2Precedence(): void
    {
        Schema::create('string_workflows', static function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->text('class');
            $table->text('arguments')->nullable();
            $table->text('output')->nullable();
            $table->string('status')->default('pending')->index();
            $table->timestamps(6);
        });

        config()->set('workflows.stored_workflow_model', StringIdStoredWorkflow::class);

        $uuid = '6f9619ff-8b86-d011-b42d-00c04fc964ff';
        $stringId = 'customer-order-alpha';
        $numericStringId = '00123';
        $uuidLegacy = $this->stringIdLegacyWorkflow($uuid, 'LegacyUuidWorkflow');
        $stringLegacy = $this->stringIdLegacyWorkflow($stringId, 'LegacyStringWorkflow');
        $numericStringLegacy = $this->stringIdLegacyWorkflow(
            $numericStringId,
            'LegacyNumericStringWorkflow',
        );
        $v2Collision = $this->v2Workflow(
            $uuid,
            'migration-v2-uuid-collision',
            'V2UuidCollisionWorkflow',
            'completed',
        );

        $this->get('/waterline/api/flows/completed')
            ->assertOk()
            ->assertJsonFragment([
                'id' => 'v1:'.$uuidLegacy->id,
                'legacy_id' => $uuid,
                'engine_source' => 'v1',
            ])
            ->assertJsonFragment([
                'id' => 'v1:'.$stringLegacy->id,
                'legacy_id' => $stringId,
                'engine_source' => 'v1',
            ])
            ->assertJsonFragment([
                'id' => 'v1:'.$numericStringLegacy->id,
                'legacy_id' => $numericStringId,
                'engine_source' => 'v1',
            ]);

        // A bare string/UUID identifier continues to resolve against v2 first.
        $this->get('/waterline/api/flows/'.$uuid)
            ->assertOk()
            ->assertJsonPath('id', $v2Collision->id)
            ->assertJsonPath('engine_source', 'v2');

        $this->get('/waterline/api/flows/v1:'.$uuid)
            ->assertOk()
            ->assertJsonPath('id', 'v1:'.$uuid)
            ->assertJsonPath('legacy_id', $uuid)
            ->assertJsonPath('engine_source', 'v1')
            ->assertJsonPath('class', 'LegacyUuidWorkflow');

        $this->get('/waterline/api/flows/v1:'.$stringId)
            ->assertOk()
            ->assertJsonPath('id', 'v1:'.$stringId)
            ->assertJsonPath('legacy_id', $stringId)
            ->assertJsonPath('engine_source', 'v1')
            ->assertJsonPath('class', 'LegacyStringWorkflow');

        $this->get('/waterline/api/flows/v1:'.$numericStringId)
            ->assertOk()
            ->assertJsonPath('id', 'v1:'.$numericStringId)
            ->assertJsonPath('legacy_id', $numericStringId)
            ->assertJsonPath('engine_source', 'v1')
            ->assertJsonPath('class', 'LegacyNumericStringWorkflow');

        $this->get('/waterline/api/flows/v1:')->assertNotFound();
    }

    public function testBareNumericIdPreservesV2OperationalFailuresInsteadOfFallingBack(): void
    {
        $legacy = $this->legacyWorkflow('CollidingLegacyWorkflow', 'completed');

        $this->app->instance(V2WorkflowRepository::class, new class extends V2WorkflowRepository
        {
            public function findFlow(string $id)
            {
                throw new RuntimeException('v2 detail query failed');
            }
        });

        $repository = $this->app->make(WorkflowRepositoryInterface::class);
        $this->assertInstanceOf(HybridWorkflowRepository::class, $repository);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('v2 detail query failed');

        $repository->findFlow((string) $legacy->id);
    }

    private function legacyWorkflow(string $class, string $status): StoredWorkflow
    {
        return StoredWorkflow::create([
            'class' => $class,
            'arguments' => Serializer::serialize([]),
            'output' => Serializer::serialize(null),
            'status' => $status,
        ]);
    }

    private function stringIdLegacyWorkflow(string $id, string $class): StringIdStoredWorkflow
    {
        return StringIdStoredWorkflow::create([
            'id' => $id,
            'class' => $class,
            'arguments' => Serializer::serialize([]),
            'output' => Serializer::serialize(null),
            'status' => 'completed',
        ]);
    }

    private function v2Workflow(
        string $runId,
        string $instanceId,
        string $class,
        string $status,
    ): WorkflowRun {
        $startedAt = Carbon::parse('2022-01-01 00:00:00');
        $closedAt = $status === 'completed' ? Carbon::parse('2022-01-01 00:01:00') : null;
        $sortTimestamp = $closedAt ?? Carbon::parse('2022-01-01 00:00:30');
        $statusBucket = $status === 'completed' ? 'completed' : 'running';

        $instance = WorkflowInstance::create([
            'id' => $instanceId,
            'workflow_class' => $class,
            'workflow_type' => $class,
            'run_count' => 1,
            'started_at' => $startedAt,
        ]);

        $run = WorkflowRun::create([
            'id' => $runId,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => $class,
            'workflow_type' => $class,
            'status' => $status,
            'arguments' => Serializer::serialize([]),
            'started_at' => $startedAt,
            'closed_at' => $closedAt,
            'last_progress_at' => $sortTimestamp,
        ]);

        $instance->update(['current_run_id' => $run->id]);

        WorkflowRunSummary::create([
            'id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => $class,
            'workflow_type' => $class,
            'status' => $status,
            'status_bucket' => $statusBucket,
            'started_at' => $startedAt,
            'closed_at' => $closedAt,
            'sort_timestamp' => $sortTimestamp,
            'sort_key' => RunSummarySortKey::key($sortTimestamp, $startedAt, $startedAt, $run->id),
        ]);

        return $run;
    }
}

final class StringIdStoredWorkflow extends StoredWorkflow
{
    protected $table = 'string_workflows';

    public $incrementing = false;

    protected $keyType = 'string';
}
