<?php

declare(strict_types=1);

namespace Waterline\Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use function Workflow\V2\all;
use function Workflow\V2\startActivity;
use Workflow\V2\Workflow;

#[Type('waterline-test-parallel-activity-workflow')]
final class TestParallelActivityWorkflow extends Workflow
{
    public function handle(string $firstName, string $secondName): array
    {
        return all([
            startActivity(TestParallelGreetingActivity::class, $firstName),
            startActivity(TestParallelGreetingActivity::class, $secondName),
        ]);
    }
}
