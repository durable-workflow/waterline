<?php

declare(strict_types=1);

namespace Waterline\Support;

use DateTimeImmutable;
use DateTimeZone;

/** Reads the complete, bounded native dispatch-summary v1 observation. */
final class ScheduleDispatchSummary
{
    private const MAX_COUNT = 9007199254740991;

    /**
     * Invalid observations revoke authority; legacy detail must not fill gaps.
     *
     * @return array{version: int, state: string, last_outcome: array|null, buffered_actions_count: int|null}
     */
    public static function read(mixed $value): array
    {
        $unavailable = [
            'version' => 1,
            'state' => 'unavailable',
            'last_outcome' => null,
            'buffered_actions_count' => null,
        ];

        if (! self::fields($value, ['version', 'state', 'last_outcome', 'buffered_actions_count'])
            || $value['version'] !== 1) {
            return $unavailable;
        }

        if ($value['state'] === 'never') {
            return $value['last_outcome'] === null && $value['buffered_actions_count'] === 0 ? $value : $unavailable;
        }

        return self::knownObservation($value) ? $value : $unavailable;
    }

    private static function knownObservation(array $value): bool
    {
        $count = $value['buffered_actions_count'];
        $outcome = $value['last_outcome'];

        if ($value['state'] !== 'known' || ! is_int($count) || $count < 0
            || $count > self::MAX_COUNT || ! self::outcome($outcome)) {
            return false;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) && strlen($encoded) <= 4096;
    }

    private static function fields(mixed $value, array $fields): bool
    {
        return is_array($value)
            && count($value) === count($fields)
            && array_diff($fields, array_keys($value)) === [];
    }

    private static function outcome(mixed $value): bool
    {
        if (! self::fields($value, [
            'id', 'outcome', 'recorded_at', 'reason', 'workflow_instance_id', 'workflow_run_id',
        ]) || ! self::ulid($value['id']) || ! self::instant($value['recorded_at'])) {
            return false;
        }

        if ($value['outcome'] === 'started') {
            return $value['reason'] === null
                && self::ulid($value['workflow_instance_id'])
                && self::ulid($value['workflow_run_id']);
        }

        return $value['workflow_instance_id'] === null && $value['workflow_run_id'] === null
            && self::outcomeWithoutRun($value);
    }

    private static function outcomeWithoutRun(array $value): bool
    {
        if ($value['outcome'] === 'buffered') {
            return $value['reason'] === null;
        }

        return in_array($value['outcome'], ['skipped', 'failed'], true)
            && is_string($value['reason'])
            && strlen($value['reason']) <= 256
            && preg_match('/\A.{0,64}\z/us', $value['reason']) === 1;
    }

    private static function ulid(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $value) === 1;
    }

    private static function instant(mixed $value): bool
    {
        if (! is_string($value)
            || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-5][0-9]\.[0-9]{6}Z\z/', $value) !== 1) {
            return false;
        }

        $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        return $time !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $time->format('Y-m-d\TH:i:s.u\Z') === $value;
    }
}
