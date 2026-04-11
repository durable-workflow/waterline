<?php

declare(strict_types=1);

namespace Waterline\Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use function Workflow\V2\activity;
use function Workflow\V2\async;
use Workflow\V2\Workflow;

#[Type('waterline-test-async-workflow')]
final class TestAsyncWorkflow extends Workflow
{
    public function handle(string $name): string
    {
        return async(static fn (): string => activity(TestParallelGreetingActivity::class, $name));
    }
}
