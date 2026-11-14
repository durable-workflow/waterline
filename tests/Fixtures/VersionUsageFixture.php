<?php

declare(strict_types=1);

namespace Waterline\Tests\Fixtures;

final class VersionUsageFixture
{
    /** @return array<string, mixed> */
    public static function query(): array
    {
        return ['namespace' => null, 'workflow_type' => 'Orders', 'change_id' => 'carrier-api', 'min_version' => 1, 'state' => null, 'limit' => 50, 'cursor' => null];
    }

    /** @param array<string, mixed>|null $query
     *  @return array<string, mixed>
     */
    public static function page(?array $query = null): array
    {
        $query ??= self::query();

        return array_diff_key($query, ['cursor' => true]) + [
            'schema_version' => 1, 'consistency' => 'live', 'through_run_id' => 'run-3',
            'counts' => ['total' => 3, 'legacy' => 1, 'current' => 1, 'unknown' => 1],
            'runs' => [
                ['instance_id' => 'order-1', 'run_id' => 'run-1', 'status' => 'waiting', 'state' => 'legacy', 'recorded_version' => 0, 'unknown_reason' => null],
                ['instance_id' => 'order-2', 'run_id' => 'run-2', 'status' => 'running', 'state' => 'current', 'recorded_version' => 1, 'unknown_reason' => null],
                ['instance_id' => 'order-3', 'run_id' => 'run-3', 'status' => 'pending', 'state' => 'unknown', 'recorded_version' => null, 'unknown_reason' => 'marker_absent'],
            ],
            'returned_count' => 3, 'has_more' => false, 'next_cursor' => null,
        ];
    }
}
