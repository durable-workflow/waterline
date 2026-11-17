<?php

declare(strict_types=1);

namespace Waterline\Support;

use Workflow\V2\Support\VersionUsageQuery;

final class EmbeddedVersionUsage
{
    public function __construct(private readonly ?object $reader = null)
    {
    }

    public function available(): bool
    {
        return $this->reader !== null || class_exists(VersionUsageQuery::class);
    }

    /** @param array<string, mixed> $query
     *  @return array<string, mixed>
     */
    public function page(array $query): array
    {
        $reader = $this->reader ?? new VersionUsageQuery();

        return VersionUsagePage::validate($reader->page(
            $query['namespace'],
            $query['workflow_type'],
            $query['change_id'],
            $query['min_version'],
            $query['limit'],
            $query['cursor'],
            $query['state'],
        ), $query);
    }
}
