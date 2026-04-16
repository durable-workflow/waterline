<?php

declare(strict_types=1);

namespace Waterline\Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use function Workflow\V2\activity;
use function Workflow\V2\all;
use function Workflow\V2\child;
use Workflow\V2\Workflow;

#[Type('waterline-test-mixed-parallel-workflow')]
final class TestMixedParallelWorkflow extends Workflow
{
    public function handle(string $name, int $seconds): array
    {
        return all([
            fn () => activity(TestParallelGreetingActivity::class, $name),
            fn () => child(TestTimerChildWorkflow::class, $seconds),
        ]);
    }
}
