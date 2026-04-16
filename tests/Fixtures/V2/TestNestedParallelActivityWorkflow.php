<?php

declare(strict_types=1);

namespace Waterline\Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use function Workflow\V2\activity;
use function Workflow\V2\all;
use Workflow\V2\Workflow;

#[Type('waterline-test-nested-parallel-activity-workflow')]
final class TestNestedParallelActivityWorkflow extends Workflow
{
    public function handle(string $firstName, string $secondName, string $thirdName): array
    {
        return all([
            fn () => activity(TestParallelGreetingActivity::class, $firstName),
            fn () => all([
                fn () => activity(TestParallelGreetingActivity::class, $secondName),
                fn () => activity(TestParallelGreetingActivity::class, $thirdName),
            ]),
        ]);
    }
}
