<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Waterline\Tests\TestCase;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;

final class V2CapacityEvidenceControllerTest extends TestCase
{
    public function testNamespaceCapacityEvidenceAggregatesFixedWindowRuntimeSignals(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'billing');
        config()->set('waterline.capacity_evidence.tenant', 'tenant-a');
        config()->set('waterline.capacity_evidence.plan', [
            'version' => 'cloud-test-v1',
            'limits' => [
                'workflow_completions_per_second' => 0.001,
                'workflow_id' => 1,
            ],
        ]);

        $instance = WorkflowInstance::create([
            'id' => 'capacity-instance',
            'workflow_class' => 'CapacityWorkflow',
            'workflow_type' => 'capacity.workflow',
            'run_count' => 1,
        ]);
        $run = WorkflowRun::create([
            'id' => 'capacity-run',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'CapacityWorkflow',
            'workflow_type' => 'capacity.workflow',
            'namespace' => 'billing',
            'status' => 'completed',
            'started_at' => now()->subMinutes(30),
            'closed_at' => now()->subMinutes(10),
            'last_progress_at' => now()->subMinutes(10),
            'created_at' => now()->subMinutes(31),
            'updated_at' => now()->subMinutes(10),
        ]);
        $instance->update(['current_run_id' => $run->id]);
        WorkflowRunSummary::create([
            'id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'CapacityWorkflow',
            'workflow_type' => 'capacity.workflow',
            'namespace' => 'billing',
            'status' => 'completed',
            'status_bucket' => 'completed',
            'started_at' => $run->started_at,
            'closed_at' => $run->closed_at,
            'duration_ms' => 1_200_000,
            'exception_count' => 0,
            'history_event_count' => 1,
            'history_size_bytes' => 80,
            'created_at' => now()->subMinutes(31),
            'updated_at' => now()->subMinutes(10),
        ]);

        DB::table('workflow_tasks')->insert([
            'id' => 'capacity-task',
            'workflow_run_id' => $run->id,
            'namespace' => 'billing',
            'task_type' => 'workflow',
            'status' => 'completed',
            'payload' => json_encode(['opaque' => 'task-payload']),
            'available_at' => now()->subMinutes(29),
            'leased_at' => now()->subMinutes(28),
            'attempt_count' => 1,
            'repair_count' => 0,
            'created_at' => now()->subMinutes(29),
            'updated_at' => now()->subMinutes(27),
        ]);
        DB::table('activity_executions')->insert([
            'id' => 'capacity-activity',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => 'CapacityActivity',
            'activity_type' => 'capacity.activity',
            'status' => 'completed',
            'arguments' => 'activity-input',
            'result' => 'activity-output',
            'attempt_count' => 1,
            'started_at' => now()->subMinutes(24)->addSeconds(10),
            'closed_at' => now()->subMinutes(20),
            'created_at' => now()->subMinutes(24),
            'updated_at' => now()->subMinutes(20),
        ]);
        DB::table('activity_attempts')->insert([
            'id' => 'capacity-attempt',
            'workflow_run_id' => $run->id,
            'activity_execution_id' => 'capacity-activity',
            'attempt_number' => 1,
            'status' => 'completed',
            'started_at' => now()->subMinutes(24)->addSeconds(10),
            'closed_at' => now()->subMinutes(20),
            'created_at' => now()->subMinutes(24),
            'updated_at' => now()->subMinutes(20),
        ]);
        DB::table('workflow_commands')->insert([
            [
                'id' => 'capacity-signal',
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'command_type' => 'signal',
                'target_scope' => 'run',
                'source' => 'php',
                'status' => 'accepted',
                'payload' => json_encode(['name' => 'capacity.signal', 'arguments' => []]),
                'accepted_at' => now()->subMinutes(15),
                'created_at' => now()->subMinutes(15),
                'updated_at' => now()->subMinutes(15),
            ],
            [
                'id' => 'capacity-update',
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'command_type' => 'update',
                'target_scope' => 'run',
                'source' => 'php',
                'status' => 'accepted',
                'payload' => json_encode(['name' => 'capacity.update', 'arguments' => []]),
                'accepted_at' => now()->subMinutes(14),
                'created_at' => now()->subMinutes(14),
                'updated_at' => now()->subMinutes(14),
            ],
        ]);
        DB::table('workflow_run_timers')->insert([
            'id' => 'capacity-timer',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'status' => 'fired',
            'delay_seconds' => 5,
            'fire_at' => now()->subMinutes(13),
            'fired_at' => now()->subMinutes(13),
            'created_at' => now()->subMinutes(14),
            'updated_at' => now()->subMinutes(13),
        ]);
        DB::table('workflow_history_events')->insert([
            'id' => 'capacity-history',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => 'WorkflowStarted',
            'payload' => json_encode(['opaque' => 'history-payload']),
            'recorded_at' => now()->subMinutes(30),
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(30),
        ]);

        $response = $this->getJson('/waterline/api/v2/capacity-evidence?window_seconds=3600')
            ->assertOk()
            ->assertJsonPath('schema', 'waterline.namespace_capacity_evidence')
            ->assertJsonPath('schema_version', 1)
            ->assertJsonPath('scope.tenant', 'tenant-a')
            ->assertJsonPath('scope.namespace', 'billing')
            ->assertJsonPath('observation_window.duration_seconds', 3600)
            ->assertJsonPath('runtime_evidence.throughput.workflow_starts.value', 1)
            ->assertJsonPath('runtime_evidence.throughput.workflow_completions.value', 1)
            ->assertJsonPath('runtime_evidence.throughput.activity_dispatches.value', 1)
            ->assertJsonPath('runtime_evidence.throughput.activity_completions.value', 1)
            ->assertJsonPath('runtime_evidence.throughput.timers_scheduled.value', 1)
            ->assertJsonPath('runtime_evidence.throughput.timers_fired.value', 1)
            ->assertJsonPath('runtime_evidence.throughput.signals.value', 1)
            ->assertJsonPath('runtime_evidence.throughput.updates.value', 1)
            ->assertJsonPath('runtime_evidence.throughput.queries.available', false)
            ->assertJsonPath('runtime_evidence.latency.schedule_to_start.p50_ms', 10000)
            ->assertJsonPath('runtime_evidence.latency.schedule_to_start.p95_ms', null)
            ->assertJsonPath('runtime_evidence.growth.history_events.value', 1)
            ->assertJsonPath('runtime_evidence.growth.history_payload_bytes.available', true)
            ->assertJsonPath('runtime_evidence.growth.durable_payload_bytes.available', true)
            ->assertJsonPath('cluster_evidence_boundary.waterline_owns_cluster_measurements', false)
            ->assertJsonPath('recommendation_input.current_plan_envelope.version', 'cloud-test-v1')
            ->assertJsonMissingPath('recommendation_input.current_plan_envelope.limits.workflow_id')
            ->assertJsonPath('recommendation_input.decision_guardrails.observation_windows_available', 1)
            ->assertJsonPath('recommendation_input.decision_guardrails.sustained_windows_observed', 0)
            ->assertJsonPath('recommendation_input.decision_guardrails.eligible_for_suggestion', false)
            ->assertJsonPath('recommendation_input.advisory.suggestion', null)
            ->assertJsonPath('commercial_boundary.invoice_unit', false)
            ->assertJsonPath('commercial_boundary.automatic_plan_change', false);

        $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('capacity-run', $encoded);
        $this->assertStringNotContainsString('capacity-task', $encoded);
        $this->assertStringNotContainsString('capacity-history', $encoded);
    }

    public function testCapacityEvidenceRejectsUnboundedObservationWindows(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $this->getJson('/waterline/api/v2/capacity-evidence?window_seconds=1234')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('window_seconds');
    }

    public function testLatencySamplingRepresentsTheWholeWindowRegardlessOfRowOrder(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.capacity_evidence.latency_sample_limit', 4);
        config()->set('waterline.capacity_evidence.percentile_min_samples', [
            'p50' => 1,
            'p95' => 1,
            'p99' => 1,
        ]);

        $this->seedLatencyWindow('slow-then-fast', 's', [
            ...array_fill(0, 10, 10_000),
            ...array_fill(0, 10, 1_000),
        ]);
        $this->seedLatencyWindow('fast-then-slow', 'f', [
            ...array_fill(0, 10, 1_000),
            ...array_fill(0, 10, 10_000),
        ]);

        $distributions = [];
        foreach (['slow-then-fast', 'fast-then-slow'] as $namespace) {
            config()->set('waterline.namespace', $namespace);
            $latency = $this->getJson('/waterline/api/v2/capacity-evidence?window_seconds=3600')
                ->assertOk()
                ->json('runtime_evidence.latency');

            foreach (['schedule_to_start', 'execution', 'replay'] as $dimension) {
                $distribution = $latency[$dimension];
                $this->assertTrue($distribution['available']);
                $this->assertSame(4, $distribution['sample_count']);
                $this->assertSame(20, $distribution['population_count']);
                $this->assertTrue($distribution['sample_truncated']);
                $this->assertSame('systematic_population_rank_midpoint', $distribution['sampling_method']);
                $this->assertSame(
                    'eligible_rows_in_observation_window',
                    $distribution['sampling_population'],
                );
                $this->assertTrue($distribution['representative_across_window']);
                $this->assertSame(1_000, $distribution['p50_ms']);
                $this->assertSame(10_000, $distribution['p95_ms']);
                $this->assertSame(10_000, $distribution['p99_ms']);
            }

            $distributions[$namespace] = $latency;
        }

        foreach (['schedule_to_start', 'execution', 'replay'] as $dimension) {
            $this->assertSame(
                $distributions['slow-then-fast'][$dimension],
                $distributions['fast-then-slow'][$dimension],
            );
        }
    }

    public function testEqualLatencyBoundaryTimestampsHaveDeterministicSampling(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'equal-boundary');
        config()->set('waterline.capacity_evidence.latency_sample_limit', 4);
        config()->set('waterline.capacity_evidence.percentile_min_samples', [
            'p50' => 1,
            'p95' => 1,
            'p99' => 1,
        ]);
        $this->seedLatencyWindow(
            'equal-boundary',
            'e',
            array_map(static fn (int $index): int => $index % 2 === 0 ? 1_000 : 10_000, range(0, 19)),
            true,
        );

        $first = $this->getJson('/waterline/api/v2/capacity-evidence?window_seconds=3600')
            ->assertOk()
            ->json('runtime_evidence.latency');
        $second = $this->getJson('/waterline/api/v2/capacity-evidence?window_seconds=3600')
            ->assertOk()
            ->json('runtime_evidence.latency');

        $this->assertSame($first, $second);
        foreach (['schedule_to_start', 'execution', 'replay'] as $dimension) {
            $this->assertSame('systematic_population_rank_midpoint', $first[$dimension]['sampling_method']);
            $this->assertSame(1_000, $first[$dimension]['p50_ms']);
            $this->assertSame(10_000, $first[$dimension]['p95_ms']);
        }
    }

    /** @param list<int> $durationsMs */
    private function seedLatencyWindow(
        string $namespace,
        string $idPrefix,
        array $durationsMs,
        bool $equalBoundary = false,
    ): void {
        $timestamp = now()->subMinutes(59);
        DB::table('workflow_instances')->insert([
            'id' => "capacity-{$namespace}",
            'workflow_class' => 'CapacityWorkflow',
            'workflow_type' => 'capacity.workflow',
            'namespace' => $namespace,
            'run_count' => count($durationsMs),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $runs = [];
        $activities = [];
        $tasks = [];

        foreach ($durationsMs as $index => $durationMs) {
            $boundary = now()->subMinutes(50)->addSeconds($equalBoundary ? 0 : $index * 120);
            $started = $boundary->copy()->subMilliseconds($durationMs);
            $runId = sprintf('r%s%024d', $idPrefix, $index);

            $runs[] = [
                'id' => $runId,
                'workflow_instance_id' => "capacity-{$namespace}",
                'run_number' => $index + 1,
                'workflow_class' => 'CapacityWorkflow',
                'workflow_type' => 'capacity.workflow',
                'namespace' => $namespace,
                'status' => 'completed',
                'started_at' => $started,
                'closed_at' => $boundary,
                'last_progress_at' => $boundary,
                'created_at' => $started,
                'updated_at' => $boundary,
            ];
            $activities[] = [
                'id' => sprintf('a%s%024d', $idPrefix, $index),
                'workflow_run_id' => $runId,
                'sequence' => 1,
                'activity_class' => 'CapacityActivity',
                'activity_type' => 'capacity.activity',
                'status' => 'completed',
                'attempt_count' => 1,
                'started_at' => $boundary,
                'closed_at' => $boundary,
                'created_at' => $started,
                'updated_at' => $boundary,
            ];
            $tasks[] = [
                'id' => sprintf('t%s%024d', $idPrefix, $index),
                'workflow_run_id' => $runId,
                'namespace' => $namespace,
                'task_type' => 'workflow',
                'status' => 'completed',
                'available_at' => $started,
                'leased_at' => $started,
                'attempt_count' => 1,
                'repair_count' => 0,
                'created_at' => $started,
                'updated_at' => $boundary,
            ];
        }

        DB::table('workflow_runs')->insert($runs);
        DB::table('activity_executions')->insert($activities);
        DB::table('workflow_tasks')->insert($tasks);
    }
}
