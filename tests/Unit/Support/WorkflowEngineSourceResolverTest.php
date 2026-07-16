<?php

declare(strict_types=1);

namespace Waterline\Tests\Unit\Support;

use Illuminate\Support\Facades\DB;
use PDO;
use ReflectionMethod;
use Waterline\Support\WorkflowEngineSourceResolver;
use Waterline\Tests\TestCase;

class WorkflowEngineSourceResolverTest extends TestCase
{
    public function testPinnedV2DegradesWhenDurableCoreExistsWithoutWorkerRegistrationProjection(): void
    {
        $status = [
            'configured' => 'v2',
            'resolved' => 'v2',
            'uses_v2' => false,
            'v2_operator_surface_available' => false,
            'status' => 'v2_pinned_unavailable',
            'severity' => 'error',
            'message' => 'The installed workflow package reported an incomplete operator surface.',
            'issues' => [
                [
                    'config_key' => 'service_call_model',
                    'model' => 'Workflow\\V2\\Models\\WorkflowServiceCall',
                    'connection' => null,
                    'table' => 'workflow_service_calls',
                    'reason' => 'missing_table',
                    'message' => 'The service-call projection table is unavailable.',
                ],
            ],
            'required_tables' => [],
        ];

        $method = new ReflectionMethod(WorkflowEngineSourceResolver::class, 'allowDegradedV2OperatorSurface');
        $method->setAccessible(true);

        $resolved = $method->invoke(null, $status, 'v2');

        $this->assertSame('v2', $resolved['resolved'] ?? null);
        $this->assertTrue($resolved['uses_v2'] ?? false);
        $this->assertTrue($resolved['v2_operator_surface_available'] ?? false);
        $this->assertSame('v2_pinned_degraded', $resolved['status'] ?? null);
        $this->assertSame('warning', $resolved['severity'] ?? null);
        $this->assertTrue($resolved['degraded_operator_surface'] ?? false);
        $this->assertTrue($resolved['durable_operator_core_available'] ?? false);
    }

    public function testEngineResolutionDoesNotProbeUnrelatedHostConnections(): void
    {
        config()->set('database.connections.unrelated_wrong_protocol', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 5432,
            'database' => 'unrelated',
            'username' => 'unrelated',
            'password' => 'unrelated',
            'options' => [PDO::ATTR_TIMEOUT => 1],
        ]);
        config()->set('workflows.storage.connection', null);
        DB::purge('unrelated_wrong_protocol');

        $startedAt = microtime(true);
        $status = WorkflowEngineSourceResolver::status('auto');
        $duration = microtime(true) - $startedAt;

        $inspectedConnections = collect($status['storage_connection']['connections'] ?? [])
            ->pluck('name')
            ->all();

        $this->assertLessThan(1.0, $duration, 'Package boot must not wait on unrelated database connections.');
        $this->assertNotContains('unrelated_wrong_protocol', $inspectedConnections);
        $this->assertSame([config('database.default')], $inspectedConnections);
    }
}
