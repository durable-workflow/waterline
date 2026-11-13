<?php

declare(strict_types=1);

namespace Waterline\Support;

/** A current-run observation is separate from the recorded dispatch result. */
final class ScheduleCurrentRun
{
    /** @return array{current_run_state: string, current_run: array|null} */
    public static function read(array $source): array
    {
        $unavailable = ['current_run_state' => 'unavailable', 'current_run' => null];
        if (! array_key_exists('latest_workflow_instance_id', $source)) {
            return $unavailable;
        }
        $current = $source['current_run'] ?? null;
        if ($source['latest_workflow_instance_id'] === null && $current === null) {
            return ['current_run_state' => 'none', 'current_run' => null];
        }
        if (self::identifier($source['latest_workflow_instance_id'], 191) === null || ! is_array($current)) {
            return $unavailable;
        }
        $expected = self::fromStatus($current['run_id'] ?? null, $current['status'] ?? null);
        if ($expected === null || ($current['status_bucket'] ?? null) !== $expected['status_bucket']
            || ($current['is_terminal'] ?? null) !== $expected['is_terminal']) {
            return $unavailable;
        }

        return ['current_run_state' => 'known', 'current_run' => $expected];
    }

    /** @return array{run_id: string, status: string, status_bucket: string, is_terminal: bool}|null */
    public static function fromStatus(mixed $runId, mixed $status): ?array
    {
        if (self::identifier($runId, 26) === null || ! is_string($status)) {
            return null;
        }
        $bucket = match ($status) {
            'pending', 'running', 'waiting' => 'running',
            'completed' => 'completed',
            'failed', 'cancelled', 'terminated' => 'failed',
            default => null,
        };

        return $bucket === null ? null : [
            'run_id' => $runId, 'status' => $status, 'status_bucket' => $bucket, 'is_terminal' => $bucket !== 'running',
        ];
    }

    // Stored pointers need not be ULIDs. Validate the complete scalar, without
    // trimming, truncating, or replacing Unicode/NUL identity bytes.
    public static function identifier(mixed $value, int $maximum): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 4 * $maximum) {
            return null;
        }
        $length = preg_match_all('/./us', $value);

        return $length !== false && $length <= $maximum ? $value : null;
    }
}
