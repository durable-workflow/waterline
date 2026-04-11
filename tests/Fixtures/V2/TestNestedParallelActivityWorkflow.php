<?php

declare(strict_types=1);

namespace Waterline\Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use function Workflow\V2\all;
use function Workflow\V2\parallel;
use function Workflow\V2\startActivity;
use Workflow\V2\Workflow;

#[Type('waterline-test-nested-parallel-activity-workflow')]
final class TestNestedParallelActivityWorkflow extends Workflow
{
    public function execute(string $firstName, string $secondName, string $thirdName): array
    {
        return all([
            startActivity(TestParallelGreetingActivity::class, $firstName),
            parallel([
                startActivity(TestParallelGreetingActivity::class, $secondName),
                startActivity(TestParallelGreetingActivity::class, $thirdName),
            ]),
        ]);
    }
}
