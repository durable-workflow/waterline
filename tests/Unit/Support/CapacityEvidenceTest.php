<?php

declare(strict_types=1);

namespace Waterline\Tests\Unit\Support;

use Illuminate\Support\Carbon;
use Waterline\Support\CapacityEvidence;
use Waterline\Tests\TestCase;

final class CapacityEvidenceTest extends TestCase
{
    protected function requiresDatabaseMigrations(): bool
    {
        return false;
    }

    public function testPercentileVolumeAndRecommendationGuardrailsAreMachineEnforced(): void
    {
        config()->set('waterline.capacity_evidence.plan', [
            'version' => 'cloud-test-v1',
            'limits' => [
                'schedule_to_start_p95_ms' => 50,
                'workflow_starts_per_second' => 0.01,
                'arbitrary_label' => 1,
            ],
        ]);
        config()->set('waterline.capacity_evidence.recommendation_policy', [
            'sustained_windows' => 3,
            'upgrade_utilization_ratio' => 0.8,
            'downgrade_utilization_ratio' => 0.5,
            'cooldown_seconds' => 3600,
        ]);
        $samples = range(1, 100);
        $windowed = [
            'sustained_evidence' => [
                'observation_windows' => 3,
                'upgrade_breach_windows' => ['schedule_to_start_p95_ms' => 3],
            ],
            'throughput' => [
                'workflow_starts' => [
                    'available' => true,
                    'value' => 40,
                    'unit' => 'count',
                    'kind' => 'window_count',
                    'source' => 'durable_workflow_service',
                    'workflow_id' => 'must-not-leak',
                ],
            ],
            'latency' => [
                'schedule_to_start' => [
                    'available' => true,
                    'samples_ms' => $samples,
                    'population_count' => 100,
                    'source' => 'durable_workflow_service',
                    'run_id' => 'must-not-leak',
                ],
            ],
            'pressure' => [
                'database_connections' => [
                    'available' => true,
                    'value' => 80,
                    'source' => 'durable_workflow_service',
                ],
            ],
        ];

        $payload = app(CapacityEvidence::class)->build(
            ['runs' => ['running' => 2], 'tasks' => ['open' => 3, 'leased' => 1]],
            $windowed,
            Carbon::parse('2026-08-11T12:00:00Z'),
            3600,
            ['mode' => 'namespace', 'namespace' => 'orders', 'authority' => 'tenant'],
            'service',
        );

        $this->assertSame(50.0, $payload['runtime_evidence']['latency']['schedule_to_start']['p50_ms']);
        $this->assertSame(95.0, $payload['runtime_evidence']['latency']['schedule_to_start']['p95_ms']);
        $this->assertSame(99.0, $payload['runtime_evidence']['latency']['schedule_to_start']['p99_ms']);
        $this->assertSame('schedule_to_start_p95_ms', $payload['recommendation_input']['constrained_resource_or_latency_slo']['dimension']);
        $this->assertTrue($payload['recommendation_input']['decision_guardrails']['eligible_for_suggestion']);
        $this->assertSame('upgrade_review', $payload['recommendation_input']['advisory']['suggestion']);
        $this->assertFalse($payload['recommendation_input']['advisory']['automatic_plan_change']);
        $this->assertFalse($payload['recommendation_input']['advisory']['automatic_billing_change']);
        $this->assertArrayNotHasKey('arbitrary_label', $payload['recommendation_input']['current_plan_envelope']['limits']);
        $this->assertFalse($payload['runtime_evidence']['pressure']['database_connections']['available']);
        $this->assertSame(
            'cloud_or_database_telemetry',
            $payload['runtime_evidence']['pressure']['database_connections']['source'],
        );

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('must-not-leak', $encoded);

        $windowed['sustained_evidence'] = ['observation_windows' => 10];
        $singleSpike = app(CapacityEvidence::class)->build(
            ['runs' => ['running' => 2], 'tasks' => ['open' => 3, 'leased' => 1]],
            $windowed,
            Carbon::parse('2026-08-11T12:00:00Z'),
            3600,
            ['mode' => 'namespace', 'namespace' => 'orders', 'authority' => 'tenant'],
            'service',
        );

        $this->assertSame(0, $singleSpike['recommendation_input']['decision_guardrails']['sustained_windows_observed']);
        $this->assertFalse($singleSpike['recommendation_input']['decision_guardrails']['eligible_for_suggestion']);
        $this->assertNull($singleSpike['recommendation_input']['advisory']['suggestion']);

        config()->set(
            'waterline.capacity_evidence.recommendation_policy.last_recommendation_at',
            '2026-08-11T11:30:00Z',
        );
        $coolingDown = app(CapacityEvidence::class)->build(
            ['runs' => ['running' => 2], 'tasks' => ['open' => 3, 'leased' => 1]],
            [
                ...$windowed,
                'sustained_evidence' => [
                    'observation_windows' => 3,
                    'upgrade_breach_windows' => ['schedule_to_start_p95_ms' => 3],
                ],
            ],
            Carbon::parse('2026-08-11T12:00:00Z'),
            3600,
            ['mode' => 'namespace', 'namespace' => 'orders', 'authority' => 'tenant'],
            'service',
        );

        $this->assertTrue($coolingDown['recommendation_input']['decision_guardrails']['cooldown_active']);
        $this->assertFalse($coolingDown['recommendation_input']['decision_guardrails']['eligible_for_suggestion']);
        $this->assertNull($coolingDown['recommendation_input']['advisory']['suggestion']);
    }

    public function testDowngradeReviewRequiresSustainedClearanceAcrossEveryPlanDimension(): void
    {
        config()->set('waterline.capacity_evidence.plan', [
            'version' => 'cloud-test-v1',
            'limits' => [
                'workflow_starts_per_second' => 1,
                'open_workflows' => 100,
            ],
        ]);
        config()->set('waterline.capacity_evidence.recommendation_policy', [
            'sustained_windows' => 3,
            'upgrade_utilization_ratio' => 0.8,
            'downgrade_utilization_ratio' => 0.5,
            'cooldown_seconds' => 3600,
        ]);
        $windowed = [
            'sustained_evidence' => [
                'observation_windows' => 3,
                'downgrade_clear_windows' => [
                    'workflow_starts_per_second' => 3,
                ],
            ],
            'throughput' => [
                'workflow_starts' => [
                    'available' => true,
                    'value' => 360,
                    'kind' => 'window_count',
                    'source' => 'durable_workflow_service',
                ],
            ],
        ];
        $now = Carbon::parse('2026-08-11T12:00:00Z');
        $metrics = ['runs' => ['running' => 40]];
        $scope = ['mode' => 'namespace', 'namespace' => 'orders', 'authority' => 'tenant'];

        $partial = app(CapacityEvidence::class)->build(
            $metrics,
            $windowed,
            $now,
            3600,
            $scope,
            'service',
        );

        $this->assertSame(0, $partial['recommendation_input']['decision_guardrails']['sustained_windows_observed']);
        $this->assertNull($partial['recommendation_input']['advisory']['suggestion']);

        $windowed['sustained_evidence']['downgrade_clear_windows']['open_workflows'] = 3;
        $sustained = app(CapacityEvidence::class)->build(
            $metrics,
            $windowed,
            $now,
            3600,
            $scope,
            'service',
        );

        $this->assertSame(3, $sustained['recommendation_input']['decision_guardrails']['sustained_windows_observed']);
        $this->assertSame('downgrade_review', $sustained['recommendation_input']['advisory']['suggestion']);
        $this->assertFalse($sustained['recommendation_input']['advisory']['automatic_plan_change']);
    }
}
