<?php

declare(strict_types=1);

namespace Waterline\Tests\Fixtures\V2;

use Generator;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\activity;
use function Workflow\V2\all;
use Workflow\V2\Workflow;

#[Type('waterline-test-nested-parallel-activity-workflow')]
final class TestNestedParallelActivityWorkflow extends Workflow
{
    public function execute(string $firstName, string $secondName, string $thirdName): Generator
    {
        return yield all([
            activity(TestParallelGreetingActivity::class, $firstName),
            all([
                activity(TestParallelGreetingActivity::class, $secondName),
                activity(TestParallelGreetingActivity::class, $thirdName),
            ]),
        ]);
    }
}
