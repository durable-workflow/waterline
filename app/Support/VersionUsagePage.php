<?php

declare(strict_types=1);

namespace Waterline\Support;

use UnexpectedValueException;

/** Validates the v1 producer response without deriving decisions from code or deployment metadata. */
final class VersionUsagePage
{
    /** @param array<string, mixed> $query
     *  @return array<string, mixed>
     */
    public static function validate(mixed $page, array $query): array
    {
        self::require(self::keys($page, ['schema_version', 'namespace', 'workflow_type', 'change_id', 'min_version', 'state', 'consistency', 'through_run_id', 'counts', 'runs', 'limit', 'returned_count', 'has_more', 'next_cursor']));
        self::require($page['schema_version'] === 1 && $page['consistency'] === 'live');
        foreach (['namespace', 'workflow_type', 'change_id', 'min_version', 'state', 'limit'] as $key) {
            self::require($page[$key] === $query[$key]);
        }

        self::counts($page['counts']);
        self::paging($page, $query);
        self::runs($page);

        return $page;
    }

    private static function counts(mixed $counts): void
    {
        self::require(self::keys($counts, ['total', 'legacy', 'current', 'unknown']));
        foreach ($counts as $count) {
            self::require(is_int($count) && $count >= 0);
        }
        self::require($counts['total'] === $counts['legacy'] + $counts['current'] + $counts['unknown']);
    }

    /** @param array<string, mixed> $page
     *  @param array<string, mixed> $query
     */
    private static function paging(array $page, array $query): void
    {
        $counts = $page['counts'];
        self::require(is_array($page['runs']) && array_is_list($page['runs']) && count($page['runs']) <= $page['limit']);
        self::require($page['returned_count'] === count($page['runs']) && is_bool($page['has_more']));
        self::require($page['has_more'] ? self::text($page['next_cursor'], 8192) && $page['runs'] !== [] : $page['next_cursor'] === null);
        self::require($page['through_run_id'] === null || self::text($page['through_run_id']));
        self::require($counts['total'] === 0 || $page['through_run_id'] !== null);
        $selectedCount = $page['state'] === null ? $counts['total'] : $counts[$page['state']];
        if ($page['has_more']) {
            self::require(count($page['runs']) === $page['limit'] && $selectedCount > count($page['runs']));
            self::require($page['next_cursor'] !== ($query['cursor'] ?? null));
        } elseif (($query['cursor'] ?? null) === null) {
            self::require($selectedCount === count($page['runs']));
        }
    }

    /** @param array<string, mixed> $page */
    private static function runs(array $page): void
    {
        $previous = null;
        $seen = ['legacy' => 0, 'current' => 0, 'unknown' => 0];
        foreach ($page['runs'] as $run) {
            self::require(self::keys($run, ['instance_id', 'run_id', 'status', 'state', 'recorded_version', 'unknown_reason']));
            self::require(self::text($run['instance_id']) && self::text($run['run_id']));
            self::require(in_array($run['status'], ['pending', 'running', 'waiting'], true));
            self::require(in_array($run['state'], ['legacy', 'current', 'unknown'], true));
            self::require($page['state'] === null || $run['state'] === $page['state']);
            self::require(is_string($page['through_run_id']) && strcmp($run['run_id'], $page['through_run_id']) <= 0);
            self::require($previous === null || strcmp($previous, $run['run_id']) < 0);
            self::classification($run, $page['min_version']);
            $previous = $run['run_id'];
            $seen[$run['state']]++;
        }
        foreach ($seen as $state => $count) {
            self::require($page['counts'][$state] >= $count);
        }
    }

    /** @param array<string, mixed> $run */
    private static function classification(array $run, int $minimum): void
    {
        if ($run['state'] === 'unknown') {
            self::require($run['recorded_version'] === null && in_array($run['unknown_reason'], ['marker_absent', 'history_unavailable', 'history_invalid'], true));

            return;
        }

        self::require(is_int($run['recorded_version']) && $run['recorded_version'] >= -2147483648 && $run['recorded_version'] <= 2147483647);
        self::require($run['unknown_reason'] === null);
        self::require(($run['recorded_version'] < $minimum) === ($run['state'] === 'legacy'));
    }

    /** @param list<string> $keys */
    private static function keys(mixed $value, array $keys): bool
    {
        return is_array($value) && count($value) === count($keys) && array_diff($keys, array_keys($value)) === [];
    }

    private static function text(mixed $value, int $maximum = PHP_INT_MAX): bool
    {
        return is_string($value) && trim($value) !== '' && strlen($value) <= $maximum && mb_check_encoding($value, 'UTF-8');
    }

    private static function require(bool $valid): void
    {
        if (! $valid) {
            throw new UnexpectedValueException('Invalid version usage response.');
        }
    }
}
