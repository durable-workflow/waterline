<?php

declare(strict_types=1);

namespace Waterline\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

final class CapacityEvidence
{
    public const SCHEMA = 'waterline.namespace_capacity_evidence';

    public const SCHEMA_VERSION = 1;

    /**
     * The only plan-envelope dimensions Waterline will evaluate. This fixed
     * catalog prevents customer labels or execution identifiers from becoming
     * metric dimensions through configuration.
     *
     * @var array<string, array{path: string, kind: string}>
     */
    private const ENVELOPE_DIMENSIONS = [
        'workflow_starts_per_second' => ['path' => 'throughput.workflow_starts', 'kind' => 'window_rate'],
        'workflow_completions_per_second' => ['path' => 'throughput.workflow_completions', 'kind' => 'window_rate'],
        'activity_dispatches_per_second' => ['path' => 'throughput.activity_dispatches', 'kind' => 'window_rate'],
        'activity_completions_per_second' => ['path' => 'throughput.activity_completions', 'kind' => 'window_rate'],
        'timers_scheduled_per_second' => ['path' => 'throughput.timers_scheduled', 'kind' => 'window_rate'],
        'timers_fired_per_second' => ['path' => 'throughput.timers_fired', 'kind' => 'window_rate'],
        'signals_per_second' => ['path' => 'throughput.signals', 'kind' => 'window_rate'],
        'queries_per_second' => ['path' => 'throughput.queries', 'kind' => 'window_rate'],
        'updates_per_second' => ['path' => 'throughput.updates', 'kind' => 'window_rate'],
        'open_workflows' => ['path' => 'concurrency.open_workflows', 'kind' => 'value'],
        'outstanding_tasks' => ['path' => 'concurrency.outstanding_tasks', 'kind' => 'value'],
        'active_leases' => ['path' => 'concurrency.active_leases', 'kind' => 'value'],
        'oldest_ready_task_age_ms' => ['path' => 'concurrency.oldest_ready_task_age', 'kind' => 'value'],
        'schedule_to_start_p95_ms' => ['path' => 'latency.schedule_to_start', 'kind' => 'p95'],
        'execution_p95_ms' => ['path' => 'latency.execution', 'kind' => 'p95'],
        'replay_p95_ms' => ['path' => 'latency.replay', 'kind' => 'p95'],
        'inspection_p95_ms' => ['path' => 'latency.inspection', 'kind' => 'p95'],
        'history_events_per_second' => ['path' => 'growth.history_events', 'kind' => 'window_rate'],
        'history_payload_bytes_per_second' => ['path' => 'growth.history_payload_bytes', 'kind' => 'window_rate'],
        'durable_payload_bytes_per_second' => ['path' => 'growth.durable_payload_bytes', 'kind' => 'window_rate'],
    ];

    /** @return list<int> */
    public static function allowedWindowSeconds(): array
    {
        $configured = config('waterline.capacity_evidence.allowed_window_seconds', [3600]);
        $allowed = is_array($configured)
            ? array_values(array_unique(array_filter(
                array_map(static fn (mixed $value): int => (int) $value, $configured),
                static fn (int $value): bool => $value >= 60 && $value <= 86400,
            )))
            : [];

        sort($allowed);

        return $allowed === [] ? [3600] : $allowed;
    }

    public static function defaultWindowSeconds(): int
    {
        $default = (int) config('waterline.capacity_evidence.default_window_seconds', 3600);
        $allowed = self::allowedWindowSeconds();

        return in_array($default, $allowed, true) ? $default : $allowed[0];
    }

    /**
     * @param array<string, mixed> $metrics
     * @param array<string, mixed> $windowed
     * @param array<string, mixed> $operatorScope
     * @return array<string, mixed>
     */
    public function build(
        array $metrics,
        array $windowed,
        CarbonInterface $now,
        int $windowSeconds,
        array $operatorScope,
        string $transport,
    ): array {
        $window = $this->observationWindow($windowed, $now, $windowSeconds);
        $runtime = $this->fallbackRuntimeEvidence($metrics, $window);
        $windowedRuntime = is_array($windowed['runtime_evidence'] ?? null)
            ? $windowed['runtime_evidence']
            : $windowed;
        $runtime = $this->mergeRuntimeEvidence($runtime, $windowedRuntime, $window);
        $scope = $this->scope($operatorScope);

        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => $now->toJSON(),
            'scope' => $scope,
            'observation_window' => $window,
            'transport' => $transport,
            'cardinality' => [
                'bounded' => true,
                'dimensions' => array_values(array_filter([
                    $scope['tenant'] === null ? null : 'tenant',
                    $scope['namespace'] === null ? null : 'namespace',
                ])),
                'prohibited_dimensions' => [
                    'workflow_id',
                    'run_id',
                    'task_id',
                    'worker_id',
                    'arbitrary_customer_label',
                ],
                'individual_execution_identifiers_included' => false,
            ],
            'runtime_evidence' => $runtime,
            'cluster_evidence_boundary' => $this->clusterEvidenceBoundary(),
            'recommendation_input' => $this->recommendationInput(
                $runtime,
                $window,
                $windowed,
                $now,
            ),
            'commercial_boundary' => [
                'diagnostic_and_advisory_only' => true,
                'invoice_unit' => false,
                'automatic_plan_change' => false,
                'automatic_billing_change' => false,
                'automatic_infrastructure_change' => false,
            ],
        ];
    }

    /**
     * Preserve an exact upstream window when it is internally consistent.
     * Invalid or partial timestamp metadata falls back to the bounded window
     * ending at generation time; values from an unmatched service window have
     * already been rejected by the remote controller.
     *
     * @param array<string, mixed> $windowed
     * @return array{starts_at: string, ends_at: string, duration_seconds: int}
     */
    private function observationWindow(
        array $windowed,
        CarbonInterface $now,
        int $windowSeconds,
    ): array {
        $startsAt = data_get($windowed, 'observation_window.starts_at');
        $endsAt = data_get($windowed, 'observation_window.ends_at');

        if (is_string($startsAt) && is_string($endsAt)) {
            try {
                $start = Carbon::parse($startsAt);
                $end = Carbon::parse($endsAt);
                $duration = (int) round($start->diffInSeconds($end));

                if ($end->greaterThanOrEqualTo($start) && abs($duration - $windowSeconds) <= 1) {
                    return [
                        'starts_at' => $start->toJSON(),
                        'ends_at' => $end->toJSON(),
                        'duration_seconds' => $windowSeconds,
                    ];
                }
            } catch (\Throwable) {
                // The fixed fallback below keeps malformed upstream metadata
                // out of the public evidence contract.
            }
        }

        return [
            'starts_at' => $now->copy()->subSeconds($windowSeconds)->toJSON(),
            'ends_at' => $now->toJSON(),
            'duration_seconds' => $windowSeconds,
        ];
    }

    /**
     * @param array<string, mixed> $metrics
     * @param array<string, int|string> $window
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function fallbackRuntimeEvidence(array $metrics, array $window): array
    {
        $unavailableWindow = fn (string $unit): array => $this->unavailable(
            $unit,
            'window_count',
            $window,
            'windowed_runtime_measurement_unavailable',
        );

        return [
            'throughput' => [
                'workflow_starts' => $unavailableWindow('count'),
                'workflow_completions' => $unavailableWindow('count'),
                'activity_dispatches' => $unavailableWindow('count'),
                'activity_completions' => $unavailableWindow('count'),
                'timers_scheduled' => $unavailableWindow('count'),
                'timers_fired' => $unavailableWindow('count'),
                'signals' => $unavailableWindow('count'),
                'queries' => $unavailableWindow('count'),
                'updates' => $unavailableWindow('count'),
            ],
            'concurrency' => [
                'open_workflows' => $this->metricValue($metrics, 'runs.running', 'count'),
                'running_workflows' => $this->metricValue($metrics, 'runs.running', 'count'),
                'outstanding_tasks' => $this->metricValue($metrics, 'tasks.open', 'count'),
                'active_leases' => $this->metricValue($metrics, 'tasks.leased', 'count'),
                'oldest_ready_task_age' => $this->metricValue($metrics, 'tasks.max_ready_due_age_ms', 'milliseconds'),
            ],
            'latency' => [
                'schedule_to_start' => $this->unavailableDistribution($window),
                'execution' => $this->unavailableDistribution($window),
                'replay' => $this->unavailableDistribution($window),
                'inspection' => $this->unavailableDistribution($window),
            ],
            'growth' => [
                'history_events' => $unavailableWindow('count'),
                'history_payload_bytes' => $unavailableWindow('bytes'),
                'durable_payload_bytes' => $unavailableWindow('bytes'),
                'current_history_events' => $this->metricValue($metrics, 'history.events', 'count'),
                'largest_history_event_count' => $this->metricValue($metrics, 'history.max_event_count', 'count'),
                'largest_history_payload_bytes' => $this->metricValue($metrics, 'history.max_size_bytes', 'bytes'),
            ],
            'pressure' => [
                'redis_queue_backlog' => $this->metricValue($metrics, 'backlog.runnable_tasks', 'count'),
                'redis_dispatch_deficit' => $this->dispatchDeficit($metrics),
                'redis_sticky_cache_tasks' => $this->metricValue(
                    $metrics,
                    'sticky_execution.capacity_pressure_tasks',
                    'count',
                ),
                'database_projection_repairs' => $this->projectionRepairs($metrics),
                'database_connections' => $this->externalMetric('count', 'cloud_or_database_telemetry'),
                'database_lock_waits' => $this->externalMetric('count', 'cloud_or_database_telemetry'),
            ],
            'reliability' => [
                'retries' => $unavailableWindow('count'),
                'timeouts' => $this->metricValue($metrics, 'activities.timeout_overdue', 'count'),
                'failures' => $unavailableWindow('count'),
                'stale_heartbeats' => $this->metricValue($metrics, 'activities.timeout_overdue', 'count'),
                'overload_or_throttling' => $this->overloadEvidence($metrics),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $windowed
     * @param array<string, int|string> $window
     * @return array<string, mixed>
     */
    private function mergeRuntimeEvidence(array $base, array $windowed, array $window): array
    {
        foreach ($base as $category => $measurements) {
            if (! is_array($measurements) || ! is_array($windowed[$category] ?? null)) {
                continue;
            }

            foreach ($measurements as $name => $fallback) {
                $candidate = $windowed[$category][$name] ?? null;

                if (! is_array($candidate) || ($fallback['kind'] ?? null) === 'external_gauge') {
                    continue;
                }

                $base[$category][$name] = $category === 'latency'
                    ? $this->normalizeDistribution($candidate, $window)
                    : $this->normalizeMeasurement($candidate, $fallback, $window);
            }
        }

        return $base;
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $fallback
     * @param array<string, int|string> $window
     * @return array<string, mixed>
     */
    private function normalizeMeasurement(array $candidate, array $fallback, array $window): array
    {
        if (($candidate['available'] ?? true) !== true || ! $this->finiteNumber($candidate['value'] ?? null)) {
            return [
                ...$fallback,
                'available' => false,
                'value' => null,
                'source' => $this->safeSource($candidate['source'] ?? null, 'not_available'),
                'reason' => $this->safeReason($candidate['reason'] ?? null),
            ];
        }

        $candidateKind = $candidate['kind'] ?? null;
        $kind = is_string($candidateKind) && in_array(
            $candidateKind,
            ['gauge', 'window_count', 'rolling_window_gauge'],
            true,
        ) ? $candidateKind : ($fallback['kind'] ?? 'gauge');
        $measurement = [
            'available' => true,
            'value' => $candidate['value'] + 0,
            'unit' => $fallback['unit'] ?? 'count',
            'kind' => $kind,
            'source' => $this->safeSource($candidate['source'] ?? null, 'workflow_runtime'),
        ];

        if ($kind === 'window_count') {
            $measurement['window'] = $window;
        }

        if (isset($candidate['estimated'])) {
            $measurement['estimated'] = ($candidate['estimated'] ?? false) === true;
        }

        return $measurement;
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, int|string> $window
     * @return array<string, mixed>
     */
    private function normalizeDistribution(array $candidate, array $window): array
    {
        $sampleLimit = max(1, min(50000, (int) config('waterline.capacity_evidence.latency_sample_limit', 10000)));
        $candidateSamples = is_array($candidate['samples_ms'] ?? null)
            ? array_values($candidate['samples_ms'])
            : [];
        $samples = $candidateSamples !== []
            ? array_values(array_filter(
                array_slice($candidateSamples, 0, $sampleLimit),
                fn (mixed $value): bool => $this->finiteNumber($value) && (float) $value >= 0,
            ))
            : [];
        $population = max(
            count($candidateSamples),
            count($samples),
            (int) ($candidate['population_count'] ?? count($candidateSamples)),
        );
        $sampleTruncated = $population > count($samples);
        $locallyTruncated = count($candidateSamples) > $sampleLimit;
        $samplingPopulation = (
            ! $sampleTruncated
            || ($candidate['sampling_population'] ?? null) === 'eligible_rows_in_observation_window'
        )
            ? 'eligible_rows_in_observation_window'
            : 'unknown';
        $samplingMethod = ! $sampleTruncated
            ? 'full_population'
            : (($candidate['sampling_method'] ?? null) === 'systematic_population_rank_midpoint'
                ? 'systematic_population_rank_midpoint'
                : 'unknown');
        $representative = ! $sampleTruncated || (
            ! $locallyTruncated
            && $samplingMethod === 'systematic_population_rank_midpoint'
            && $samplingPopulation === 'eligible_rows_in_observation_window'
        );
        $source = $this->safeSource($candidate['source'] ?? null, 'not_available');

        if (($candidate['available'] ?? true) !== true || $samples === []) {
            return $this->unavailableDistribution(
                $window,
                $this->safeReason($candidate['reason'] ?? null),
                $source,
                count($samples),
                $population,
                $samples === [] ? 'not_sampled' : $samplingMethod,
                $samplingPopulation,
            );
        }

        if (! $representative) {
            return $this->unavailableDistribution(
                $window,
                'unrepresentative_truncated_sample',
                $source,
                count($samples),
                $population,
                $samplingMethod,
                $samplingPopulation,
            );
        }

        sort($samples, SORT_NUMERIC);
        $minimums = $this->percentileMinimums();

        return [
            'available' => true,
            'unit' => 'milliseconds',
            'kind' => 'window_distribution',
            'source' => $this->safeSource($candidate['source'] ?? null, 'workflow_runtime'),
            'sample_count' => count($samples),
            'population_count' => $population,
            'sample_truncated' => $sampleTruncated,
            'sampling_method' => $samplingMethod,
            'sampling_population' => $samplingPopulation,
            'representative_across_window' => true,
            'p50_ms' => count($samples) >= $minimums['p50'] ? $this->percentile($samples, 50) : null,
            'p95_ms' => count($samples) >= $minimums['p95'] ? $this->percentile($samples, 95) : null,
            'p99_ms' => count($samples) >= $minimums['p99'] ? $this->percentile($samples, 99) : null,
            'percentile_min_samples' => $minimums,
            'window' => $window,
        ];
    }

    /** @param list<int|float|string> $samples */
    private function percentile(array $samples, int $percentile): float
    {
        $index = max(0, (int) ceil(($percentile / 100) * count($samples)) - 1);

        return round((float) $samples[$index], 3);
    }

    /** @return array{p50: int, p95: int, p99: int} */
    private function percentileMinimums(): array
    {
        $configured = config('waterline.capacity_evidence.percentile_min_samples', []);

        return [
            'p50' => max(1, (int) (is_array($configured) ? ($configured['p50'] ?? 1) : 1)),
            'p95' => max(1, (int) (is_array($configured) ? ($configured['p95'] ?? 20) : 20)),
            'p99' => max(1, (int) (is_array($configured) ? ($configured['p99'] ?? 100) : 100)),
        ];
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array<string, mixed>
     */
    private function metricValue(array $metrics, string $path, string $unit): array
    {
        $value = data_get($metrics, $path);

        return $this->finiteNumber($value)
            ? [
                'available' => true,
                'value' => $value + 0,
                'unit' => $unit,
                'kind' => 'gauge',
                'source' => 'operator_metrics',
            ]
            : $this->unavailable($unit, 'gauge', null, 'operator_metric_unavailable');
    }

    /** @param array<string, mixed> $metrics */
    private function dispatchDeficit(array $metrics): array
    {
        $added = data_get($metrics, 'backlog.tasks_added_last_minute');
        $dispatched = data_get($metrics, 'backlog.tasks_dispatched_last_minute');

        if (! $this->finiteNumber($added) || ! $this->finiteNumber($dispatched)) {
            return $this->unavailable('count', 'rolling_window_gauge', null, 'operator_metric_unavailable');
        }

        return [
            'available' => true,
            'value' => max(0, (int) $added - (int) $dispatched),
            'unit' => 'count',
            'kind' => 'rolling_window_gauge',
            'source' => 'operator_metrics',
            'source_window_seconds' => 60,
        ];
    }

    /** @param array<string, mixed> $metrics */
    private function projectionRepairs(array $metrics): array
    {
        $projections = data_get($metrics, 'projections');

        if (! is_array($projections)) {
            return $this->unavailable('count', 'gauge', null, 'operator_metric_unavailable');
        }

        $total = 0;
        $found = false;
        foreach ($projections as $projection) {
            if (is_array($projection) && $this->finiteNumber($projection['needs_rebuild'] ?? null)) {
                $total += (int) $projection['needs_rebuild'];
                $found = true;
            }
        }

        return $found
            ? [
                'available' => true,
                'value' => $total,
                'unit' => 'count',
                'kind' => 'gauge',
                'source' => 'operator_metrics',
            ]
            : $this->unavailable('count', 'gauge', null, 'operator_metric_unavailable');
    }

    /** @param array<string, mixed> $metrics */
    private function overloadEvidence(array $metrics): array
    {
        $paths = [
            'tasks.dispatch_failed',
            'tasks.claim_failed',
            'tasks.dispatch_overdue',
            'backlog.compatibility_blocked_runs',
            'activities.timeout_overdue',
        ];
        $total = 0;
        $found = false;

        foreach ($paths as $path) {
            $value = data_get($metrics, $path);
            if ($this->finiteNumber($value)) {
                $total += (int) $value;
                $found = true;
            }
        }

        return $found
            ? [
                'available' => true,
                'value' => $total,
                'unit' => 'count',
                'kind' => 'gauge',
                'source' => 'operator_metrics',
            ]
            : $this->unavailable('count', 'gauge', null, 'operator_metric_unavailable');
    }

    /**
     * @param array<string, mixed> $operatorScope
     * @return array{mode: string, tenant: string|null, namespace: string|null, authority: string}
     */
    private function scope(array $operatorScope): array
    {
        $tenant = config('waterline.capacity_evidence.tenant');
        $tenant = is_string($tenant) && trim($tenant) !== '' ? trim($tenant) : null;
        $namespace = $operatorScope['namespace'] ?? null;

        return [
            'mode' => is_string($operatorScope['mode'] ?? null) ? $operatorScope['mode'] : 'namespace',
            'tenant' => $tenant,
            'namespace' => is_string($namespace) && $namespace !== '' ? $namespace : null,
            'authority' => is_string($operatorScope['authority'] ?? null)
                ? $operatorScope['authority']
                : 'tenant',
        ];
    }

    /** @return array<string, mixed> */
    private function clusterEvidenceBoundary(): array
    {
        return [
            'waterline_owns_cluster_measurements' => false,
            'external_measurements' => [
                'cpu',
                'memory',
                'disk_capacity',
                'disk_io',
                'network',
                'durable_database_physical_bytes',
                'database_connections',
                'database_lock_waits',
                'redis_memory',
                'redis_operations',
            ],
            'combination_contract' => 'Cloud may correlate these external measurements with runtime_evidence using the same tenant, namespace, and observation window.',
        ];
    }

    /**
     * @param array<string, mixed> $runtime
     * @param array<string, int|string> $window
     * @param array<string, mixed> $windowed
     * @return array<string, mixed>
     */
    private function recommendationInput(
        array $runtime,
        array $window,
        array $windowed,
        CarbonInterface $now,
    ): array {
        $configuredPlan = config('waterline.capacity_evidence.plan', []);
        $configuredPlan = is_array($configuredPlan) ? $configuredPlan : [];
        $version = is_string($configuredPlan['version'] ?? null) && trim($configuredPlan['version']) !== ''
            ? trim($configuredPlan['version'])
            : null;
        $limits = $this->planLimits($configuredPlan['limits'] ?? null);
        $constraints = [];

        foreach ($limits as $dimension => $limit) {
            $definition = self::ENVELOPE_DIMENSIONS[$dimension];
            $observed = $this->envelopeObservedValue($runtime, $definition, (int) $window['duration_seconds']);
            $available = $observed !== null;
            $constraints[] = [
                'dimension' => $dimension,
                'kind' => str_contains($dimension, '_p95_ms') ? 'latency_slo' : 'runtime_resource',
                'available' => $available,
                'observed' => $observed,
                'limit' => $limit,
                'headroom' => $available ? round($limit - $observed, 6) : null,
                'headroom_ratio' => $available ? round(($limit - $observed) / $limit, 6) : null,
                'utilization_ratio' => $available ? round($observed / $limit, 6) : null,
            ];
        }

        $availableConstraints = array_values(array_filter(
            $constraints,
            static fn (array $constraint): bool => $constraint['available'] === true,
        ));
        usort(
            $availableConstraints,
            static fn (array $left, array $right): int => $right['utilization_ratio'] <=> $left['utilization_ratio'],
        );
        $constrained = $availableConstraints[0] ?? null;
        $policy = $this->recommendationPolicy();
        $allBelowDowngrade = $availableConstraints !== []
            && count($availableConstraints) === count($constraints)
            && max(array_column($availableConstraints, 'utilization_ratio')) <= $policy['downgrade_utilization_ratio'];
        $observationWindows = max(1, (int) data_get($windowed, 'sustained_evidence.observation_windows', 1));
        $sustainedWindows = $this->sustainedWindows(
            $windowed,
            $constrained,
            $availableConstraints,
            $allBelowDowngrade,
            $policy['upgrade_utilization_ratio'],
        );
        $confidence = $this->confidence($version, $limits, $constraints, $sustainedWindows, $policy);
        $cooldown = $this->cooldown($now, $policy['cooldown_seconds']);
        $eligible = $version !== null
            && $limits !== []
            && $constrained !== null
            && count($availableConstraints) === count($constraints)
            && $sustainedWindows >= $policy['sustained_windows']
            && $cooldown['active'] === false;
        $suggestion = null;

        if ($eligible && $constrained['utilization_ratio'] >= $policy['upgrade_utilization_ratio']) {
            $suggestion = 'upgrade_review';
        } elseif ($eligible && $allBelowDowngrade) {
            $suggestion = 'downgrade_review';
        }

        return [
            'observation_window' => $window,
            'current_plan_envelope' => [
                'configured' => $version !== null && $limits !== [],
                'version' => $version,
                'limits' => $limits,
            ],
            'constraints' => $constraints,
            'constrained_resource_or_latency_slo' => $constrained === null ? null : [
                'dimension' => $constrained['dimension'],
                'kind' => $constrained['kind'],
            ],
            'headroom' => $constrained === null ? null : [
                'absolute' => $constrained['headroom'],
                'ratio' => $constrained['headroom_ratio'],
            ],
            'confidence' => $confidence,
            'decision_guardrails' => [
                'sustained_windows_required' => $policy['sustained_windows'],
                'observation_windows_available' => $observationWindows,
                'sustained_windows_observed' => $sustainedWindows,
                'upgrade_utilization_ratio' => $policy['upgrade_utilization_ratio'],
                'downgrade_utilization_ratio' => $policy['downgrade_utilization_ratio'],
                'hysteresis_ratio' => round(
                    $policy['upgrade_utilization_ratio'] - $policy['downgrade_utilization_ratio'],
                    6,
                ),
                'cooldown_seconds' => $policy['cooldown_seconds'],
                'cooldown_active' => $cooldown['active'],
                'cooldown_until' => $cooldown['until'],
                'eligible_for_suggestion' => $eligible,
            ],
            'advisory' => [
                'suggestion' => $suggestion,
                'automatic_plan_change' => false,
                'automatic_billing_change' => false,
                'automatic_infrastructure_change' => false,
                'authenticated_customer_action_required' => true,
            ],
        ];
    }

    /** @return array<string, float> */
    private function planLimits(mixed $configured): array
    {
        if (! is_array($configured)) {
            return [];
        }

        $limits = [];
        foreach (self::ENVELOPE_DIMENSIONS as $dimension => $_definition) {
            $value = $configured[$dimension] ?? null;
            if ($this->finiteNumber($value) && (float) $value > 0) {
                $limits[$dimension] = (float) $value;
            }
        }

        return $limits;
    }

    /**
     * @param array<string, mixed> $runtime
     * @param array{path: string, kind: string} $definition
     */
    private function envelopeObservedValue(array $runtime, array $definition, int $windowSeconds): ?float
    {
        $measurement = data_get($runtime, $definition['path']);
        if (! is_array($measurement) || ($measurement['available'] ?? false) !== true) {
            return null;
        }

        $value = match ($definition['kind']) {
            'p95' => $measurement['p95_ms'] ?? null,
            default => $measurement['value'] ?? null,
        };

        if (! $this->finiteNumber($value)) {
            return null;
        }

        if ($definition['kind'] === 'window_rate' && ($measurement['kind'] ?? null) !== 'window_count') {
            return null;
        }

        return $definition['kind'] === 'window_rate'
            ? round((float) $value / max(1, $windowSeconds), 6)
            : (float) $value;
    }

    /**
     * Accept only fixed envelope dimensions from an upstream rolling-window
     * evaluator. Merely having several snapshots is not sustained evidence:
     * every qualifying window must have breached the upgrade threshold or
     * remained below the downgrade threshold in the same direction.
     *
     * @param array<string, mixed> $windowed
     * @param array<string, mixed>|null $constrained
     * @param list<array<string, mixed>> $availableConstraints
     */
    private function sustainedWindows(
        array $windowed,
        ?array $constrained,
        array $availableConstraints,
        bool $allBelowDowngrade,
        float $upgradeThreshold,
    ): int {
        if ($constrained === null || ! is_string($constrained['dimension'] ?? null)) {
            return 0;
        }

        $dimension = $constrained['dimension'];
        if (! isset(self::ENVELOPE_DIMENSIONS[$dimension])) {
            return 0;
        }

        $observations = max(1, (int) data_get($windowed, 'sustained_evidence.observation_windows', 1));

        if ((float) ($constrained['utilization_ratio'] ?? 0) >= $upgradeThreshold) {
            $count = data_get($windowed, "sustained_evidence.upgrade_breach_windows.{$dimension}");

            return $this->finiteNumber($count) ? min($observations, max(0, (int) $count)) : 0;
        }

        if (! $allBelowDowngrade || $availableConstraints === []) {
            return 0;
        }

        $counts = [];
        foreach ($availableConstraints as $constraint) {
            $candidateDimension = $constraint['dimension'] ?? null;
            if (! is_string($candidateDimension) || ! isset(self::ENVELOPE_DIMENSIONS[$candidateDimension])) {
                return 0;
            }

            $count = data_get(
                $windowed,
                "sustained_evidence.downgrade_clear_windows.{$candidateDimension}",
            );
            if (! $this->finiteNumber($count)) {
                return 0;
            }

            $counts[] = min($observations, max(0, (int) $count));
        }

        return min($counts);
    }

    /** @return array{sustained_windows: int, upgrade_utilization_ratio: float, downgrade_utilization_ratio: float, cooldown_seconds: int} */
    private function recommendationPolicy(): array
    {
        $configured = config('waterline.capacity_evidence.recommendation_policy', []);
        $configured = is_array($configured) ? $configured : [];
        $upgrade = min(1.0, max(0.01, (float) ($configured['upgrade_utilization_ratio'] ?? 0.8)));
        $downgrade = min($upgrade, max(0.0, (float) ($configured['downgrade_utilization_ratio'] ?? 0.5)));

        return [
            'sustained_windows' => max(2, (int) ($configured['sustained_windows'] ?? 3)),
            'upgrade_utilization_ratio' => $upgrade,
            'downgrade_utilization_ratio' => $downgrade,
            'cooldown_seconds' => max(0, (int) ($configured['cooldown_seconds'] ?? 86400)),
        ];
    }

    /**
     * @param array<string, float> $limits
     * @param list<array<string, mixed>> $constraints
     * @param array{sustained_windows: int} $policy
     * @return array{level: string, score: float, reasons: list<string>}
     */
    private function confidence(
        ?string $version,
        array $limits,
        array $constraints,
        int $observedWindows,
        array $policy,
    ): array {
        $reasons = [];
        $available = count(array_filter(
            $constraints,
            static fn (array $constraint): bool => $constraint['available'] === true,
        ));
        $score = 0.0;

        if ($version !== null && $limits !== []) {
            $score += 0.3;
        } else {
            $reasons[] = 'current_plan_envelope_unconfigured';
        }

        if ($constraints !== [] && $available === count($constraints)) {
            $score += 0.4;
        } elseif ($available > 0) {
            $score += 0.2;
            $reasons[] = 'partial_runtime_evidence';
        } else {
            $reasons[] = 'runtime_evidence_unavailable_for_plan_limits';
        }

        if ($observedWindows >= $policy['sustained_windows']) {
            $score += 0.3;
        } else {
            $reasons[] = 'sustained_evidence_not_yet_met';
        }

        $score = round(min(1.0, $score), 2);

        return [
            'level' => $score >= 0.8 ? 'high' : ($score >= 0.5 ? 'medium' : 'low'),
            'score' => $score,
            'reasons' => $reasons,
        ];
    }

    /** @return array{active: bool, until: string|null} */
    private function cooldown(CarbonInterface $now, int $cooldownSeconds): array
    {
        $last = config('waterline.capacity_evidence.recommendation_policy.last_recommendation_at');

        if (! is_string($last) || trim($last) === '' || $cooldownSeconds === 0) {
            return ['active' => false, 'until' => null];
        }

        try {
            $until = Carbon::parse($last)->addSeconds($cooldownSeconds);
        } catch (\Throwable) {
            return ['active' => true, 'until' => null];
        }

        return [
            'active' => $until->greaterThan($now),
            'until' => $until->toJSON(),
        ];
    }

    /**
     * @param array<string, int|string>|null $window
     * @return array<string, mixed>
     */
    private function unavailable(string $unit, string $kind, ?array $window, string $reason): array
    {
        return array_filter([
            'available' => false,
            'value' => null,
            'unit' => $unit,
            'kind' => $kind,
            'source' => 'not_available',
            'reason' => $reason,
            'window' => $kind === 'window_count' ? $window : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    private function externalMetric(string $unit, string $source): array
    {
        return [
            'available' => false,
            'value' => null,
            'unit' => $unit,
            'kind' => 'external_gauge',
            'source' => $source,
            'reason' => 'measurement_owned_outside_waterline',
        ];
    }

    /**
     * @param array<string, int|string> $window
     * @return array<string, mixed>
     */
    private function unavailableDistribution(
        array $window,
        string $reason = 'windowed_runtime_measurement_unavailable',
        string $source = 'not_available',
        int $sampleCount = 0,
        int $populationCount = 0,
        string $samplingMethod = 'not_sampled',
        string $samplingPopulation = 'eligible_rows_in_observation_window',
    ): array {
        return [
            'available' => false,
            'unit' => 'milliseconds',
            'kind' => 'window_distribution',
            'source' => $source,
            'reason' => $reason,
            'sample_count' => $sampleCount,
            'population_count' => $populationCount,
            'sample_truncated' => $populationCount > $sampleCount,
            'sampling_method' => $samplingMethod,
            'sampling_population' => $samplingPopulation,
            'representative_across_window' => false,
            'p50_ms' => null,
            'p95_ms' => null,
            'p99_ms' => null,
            'percentile_min_samples' => $this->percentileMinimums(),
            'window' => $window,
        ];
    }

    private function safeSource(mixed $source, string $fallback): string
    {
        $allowed = [
            'workflow_runtime',
            'operator_metrics',
            'waterline_embedded_store',
            'durable_workflow_service',
            'not_available',
        ];

        return is_string($source) && in_array($source, $allowed, true) ? $source : $fallback;
    }

    private function safeReason(mixed $reason): string
    {
        $allowed = [
            'windowed_runtime_measurement_unavailable',
            'operator_metric_unavailable',
            'runtime_table_unavailable',
            'query_telemetry_unavailable',
            'inspection_telemetry_unavailable',
            'insufficient_samples',
            'incomplete_representative_sample',
            'unrepresentative_truncated_sample',
        ];

        return is_string($reason) && in_array($reason, $allowed, true)
            ? $reason
            : 'windowed_runtime_measurement_unavailable';
    }

    private function finiteNumber(mixed $value): bool
    {
        return is_numeric($value) && is_finite((float) $value);
    }
}
