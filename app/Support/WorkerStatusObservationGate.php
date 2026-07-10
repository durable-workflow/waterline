<?php

declare(strict_types=1);

namespace Waterline\Support;

final class WorkerStatusObservationGate
{
    public const CAPTURED_BY = 'waterline.worker-status.published-artifact-runner';

    /**
     * Reject projections supplied by fixtures, plans, or callers. A passing
     * worker-status row may only be assembled from envelopes captured by the
     * runner's HTTP client and the executed published CLI process.
     *
     * @param  array<string, array<string, mixed>>  $observations
     * @return array<string, bool>
     */
    public static function checks(array $observations): array
    {
        $checks = [];

        foreach ($observations as $name => $observation) {
            $expectedKind = str_starts_with($name, 'cli.') ? 'live_cli_process' : 'live_http';
            $provenance = is_array($observation['provenance'] ?? null)
                ? $observation['provenance']
                : [];

            $checks['authoritative_capture.'.$name] = ($provenance['kind'] ?? null) === $expectedKind
                && ($provenance['captured_by'] ?? null) === self::CAPTURED_BY
                && self::timestamp($observation['observed_at'] ?? null)
                && self::requestWasExecuted($observation, $expectedKind);
        }

        return $checks;
    }

    /**
     * @param  array<string, mixed>  $observation
     */
    private static function requestWasExecuted(array $observation, string $kind): bool
    {
        if ($kind === 'live_cli_process') {
            return is_array($observation['command'] ?? null)
                && ($observation['command'] ?? []) !== []
                && is_int($observation['exit_code'] ?? null)
                && is_string($observation['stdout'] ?? null);
        }

        return is_string($observation['url'] ?? null)
            && filter_var($observation['url'] ?? null, FILTER_VALIDATE_URL) !== false
            && is_int($observation['status_code'] ?? null)
            && is_array($observation['body'] ?? null);
    }

    private static function timestamp(mixed $value): bool
    {
        return is_string($value) && strtotime($value) !== false;
    }
}
