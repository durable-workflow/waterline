<?php

declare(strict_types=1);

namespace Waterline\Tests\Fixtures\V2;

use Generator;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\activity;
use function Workflow\V2\all;
use Workflow\V2\Workflow;

#[Type('waterline-test-parallel-activity-workflow')]
final class TestParallelActivityWorkflow extends Workflow
{
    public function execute(string $firstName, string $secondName): Generator
    {
        return yield all([
            activity(TestParallelGreetingActivity::class, $firstName),
            activity(TestParallelGreetingActivity::class, $secondName),
        ]);
    }
}
