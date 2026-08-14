<?php

declare(strict_types=1);

namespace Waterline\Support\Remote;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Throwable;

final class RemoteCapacityEvidenceContract
{
    public const SCHEMA = 'durable-workflow.v2.namespace-capacity-evidence';

    public const VERSION = 1;

    public const CLOCK_SKEW_SECONDS = 1;

    /** @var array<string, list<string>> */
    private const REQUIRED_DIMENSIONS = [
        'throughput' => [
            'workflow_starts',
            'workflow_completions',
            'activity_dispatches',
            'activity_completions',
            'timers_scheduled',
            'timers_fired',
            'signals',
            'queries',
            'updates',
        ],
        'latency' => ['schedule_to_start', 'execution', 'replay', 'inspection'],
        'growth' => ['history_events', 'history_payload_bytes', 'durable_payload_bytes'],
        'reliability' => ['retries', 'timeouts', 'failures', 'stale_heartbeats', 'overload_or_throttling'],
    ];

    /** @var list<string> */
    private const PROHIBITED_IDENTIFIER_KEYS = [
        'workflow_id',
        'run_id',
        'task_id',
        'worker_id',
    ];

    /**
     * @param  array<string, mixed>  $response
     * @return array{available: bool, reason: string|null, metrics: array<string, mixed>, window: array<string, mixed>}
     */
    public function inspect(
        array $response,
        string $namespace,
        int $windowSeconds,
        CarbonInterface $requestTime,
    ): array
    {
        $metrics = is_array($response['operator_metrics'] ?? null)
            ? $response['operator_metrics']
            : $response;
        $capacity = is_array($metrics['capacity_evidence'] ?? null)
            ? $metrics['capacity_evidence']
            : [];

        if ($capacity === []) {
            return $this->unavailable('capacity_evidence_missing', $metrics);
        }
        if (($capacity['schema'] ?? null) !== self::SCHEMA
            || ($capacity['schema_version'] ?? null) !== self::VERSION) {
            return $this->unavailable('capacity_evidence_version_unsupported', $metrics);
        }
        if (($capacity['namespace'] ?? null) !== $namespace) {
            return $this->unavailable('capacity_evidence_namespace_mismatch', $metrics);
        }

        $supported = is_array($capacity['supported_window_seconds'] ?? null)
            ? array_values(array_filter(
                $capacity['supported_window_seconds'],
                static fn (mixed $value): bool => is_int($value),
            ))
            : [];
        if (! in_array($windowSeconds, $supported, true)) {
            return $this->unavailable('capacity_evidence_window_unsupported', $metrics);
        }

        $windows = is_array($capacity['windows'] ?? null) ? $capacity['windows'] : [];
        $window = $windows[(string) $windowSeconds] ?? $windows[$windowSeconds] ?? null;
        if (! is_array($window) || ! $this->exactWindow($window, $windowSeconds)) {
            return $this->unavailable('capacity_evidence_window_mismatch', $metrics);
        }
        $freshness = $this->freshnessInterval($capacity);
        if ($freshness === null
            || ! $this->hasRequiredDimensions($window)
            || ! $this->hasSustainedEvidence($window)
            || ! $this->hasBoundedCardinality($capacity)
            || $this->containsExecutionIdentifier($window)) {
            return $this->unavailable('capacity_evidence_contract_incomplete', $metrics);
        }
        if ($requestTime->lessThan(
            $freshness['generated_at']->copy()->subSeconds(self::CLOCK_SKEW_SECONDS),
        )) {
            return $this->unavailable('capacity_evidence_not_yet_valid', $metrics);
        }
        if ($requestTime->greaterThan(
            $freshness['valid_until']->copy()->addSeconds(self::CLOCK_SKEW_SECONDS),
        )) {
            return $this->unavailable('capacity_evidence_expired', $metrics);
        }

        return [
            'available' => true,
            'reason' => null,
            'metrics' => $metrics,
            'window' => $window,
        ];
    }

    /**
     * @param array<string, mixed> $capacity
     * @return array{generated_at: Carbon, valid_until: Carbon}|null
     */
    private function freshnessInterval(array $capacity): ?array
    {
        $freshness = is_array($capacity['freshness'] ?? null) ? $capacity['freshness'] : [];
        $generatedAt = $capacity['generated_at'] ?? null;
        $validUntil = $freshness['valid_until'] ?? null;
        $maxAge = $freshness['max_age_seconds'] ?? null;

        if (($freshness['strategy'] ?? null) !== 'namespace_snapshot_cache'
            || ! is_int($maxAge)
            || $maxAge < 1
            || ! is_string($generatedAt)
            || ! is_string($validUntil)) {
            return null;
        }

        try {
            $generated = Carbon::parse($generatedAt);
            $valid = Carbon::parse($validUntil);

            if (! $valid->greaterThan($generated)
                || abs($generated->diffInSeconds($valid) - $maxAge) > 1) {
                return null;
            }

            return [
                'generated_at' => $generated,
                'valid_until' => $valid,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $window */
    private function exactWindow(array $window, int $windowSeconds): bool
    {
        $observation = is_array($window['observation_window'] ?? null)
            ? $window['observation_window']
            : [];

        if (($observation['duration_seconds'] ?? null) !== $windowSeconds
            || ! is_string($observation['starts_at'] ?? null)
            || ! is_string($observation['ends_at'] ?? null)) {
            return false;
        }

        try {
            $start = Carbon::parse($observation['starts_at']);
            $end = Carbon::parse($observation['ends_at']);

            return $end->greaterThanOrEqualTo($start)
                && abs($start->diffInSeconds($end) - $windowSeconds) <= 1;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $window */
    private function hasRequiredDimensions(array $window): bool
    {
        $runtime = is_array($window['runtime_evidence'] ?? null)
            ? $window['runtime_evidence']
            : [];

        foreach (self::REQUIRED_DIMENSIONS as $category => $dimensions) {
            $measurements = is_array($runtime[$category] ?? null) ? $runtime[$category] : [];

            foreach ($dimensions as $dimension) {
                $measurement = $measurements[$dimension] ?? null;

                if (! is_array($measurement)
                    || ! $this->validMeasurement($measurement, $category === 'latency')) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param array<string, mixed> $measurement */
    private function validMeasurement(array $measurement, bool $distribution): bool
    {
        $available = $measurement['available'] ?? null;
        $source = $measurement['source'] ?? null;

        if (! is_bool($available) || ! $this->nonEmptyString($source)) {
            return false;
        }

        if ($available === false) {
            return $this->nonEmptyString($measurement['reason'] ?? null);
        }

        if ($distribution) {
            $samples = $measurement['samples_ms'] ?? null;
            $population = $measurement['population_count'] ?? null;
            $sampleLimit = $measurement['sample_limit'] ?? null;
            $sampleTruncated = $measurement['sample_truncated'] ?? null;

            if (! is_array($samples)
                || $samples === []
                || array_keys($samples) !== range(0, count($samples) - 1)
                || ! is_int($population)
                || $population < count($samples)
                || ! is_int($sampleLimit)
                || $sampleLimit < count($samples)
                || ! is_bool($sampleTruncated)
                || $sampleTruncated !== ($population > count($samples))) {
                return false;
            }

            foreach ($samples as $sample) {
                if (! $this->nonNegativeFiniteNumber($sample)) {
                    return false;
                }
            }

            return true;
        }

        return $this->nonNegativeFiniteNumber($measurement['value'] ?? null)
            && $this->nonEmptyString($measurement['unit'] ?? null)
            && is_string($measurement['kind'] ?? null)
            && in_array($measurement['kind'], ['gauge', 'window_count', 'rolling_window_gauge'], true);
    }

    private function nonNegativeFiniteNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value))
            && is_finite((float) $value)
            && $value >= 0;
    }

    private function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /** @param array<string, mixed> $window */
    private function hasSustainedEvidence(array $window): bool
    {
        $sustained = is_array($window['sustained_evidence'] ?? null)
            ? $window['sustained_evidence']
            : [];

        return is_int($sustained['observation_windows'] ?? null)
            && ($sustained['observation_windows'] ?? 0) >= 1
            && is_int($sustained['minimum_windows_required_for_recommendation'] ?? null)
            && ($sustained['minimum_windows_required_for_recommendation'] ?? 0) >= 1
            && $this->validWindowCounts($sustained['upgrade_breach_windows'] ?? null)
            && $this->validWindowCounts($sustained['downgrade_clear_windows'] ?? null);
    }

    private function validWindowCounts(mixed $counts): bool
    {
        if (! is_array($counts)) {
            return false;
        }

        foreach ($counts as $dimension => $count) {
            if (! is_string($dimension)
                || trim($dimension) === ''
                || ! is_int($count)
                || $count < 0) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $capacity */
    private function hasBoundedCardinality(array $capacity): bool
    {
        $cardinality = is_array($capacity['cardinality'] ?? null)
            ? $capacity['cardinality']
            : [];

        return ($cardinality['bounded'] ?? false) === true
            && ($cardinality['individual_execution_identifiers_included'] ?? true) === false;
    }

    /** @param array<string, mixed> $payload */
    private function containsExecutionIdentifier(array $payload): bool
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array($key, self::PROHIBITED_IDENTIFIER_KEYS, true)) {
                return true;
            }
            if (is_array($value) && $this->containsExecutionIdentifier($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return array{available: false, reason: string, metrics: array<string, mixed>, window: array<string, mixed>}
     */
    private function unavailable(string $reason, array $metrics): array
    {
        return [
            'available' => false,
            'reason' => $reason,
            'metrics' => $metrics,
            'window' => [],
        ];
    }
}
