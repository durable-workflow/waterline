<?php

declare(strict_types=1);

namespace Waterline\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waterline\Support\VisibilityFilterContract;
use Workflow\V2\Support\VisibilityFilters;

final class VisibilityFilterContractTest extends TestCase
{
    public function testServiceMetadataMatchesTheEmbeddedVisibilityContract(): void
    {
        $this->assertSame(VisibilityFilters::definition(), VisibilityFilterContract::definition());
    }

    public function testServiceNormalizationMatchesTheEmbeddedVisibilityContract(): void
    {
        $filters = [
            'workflow_type' => ' orders.process ',
            'archived' => 'false',
            'contains' => ['instance_id' => 'order-'],
            'labels' => ['tenant' => 'acme', 'bad key' => 'ignored'],
            'search_attributes' => ['priority' => 'high'],
        ];

        $this->assertSame(
            VisibilityFilters::normalize($filters),
            VisibilityFilterContract::normalize($filters),
        );
    }
}
