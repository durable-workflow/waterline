<?php

declare(strict_types=1);

namespace Waterline\Tests\Unit\Support;

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
}
