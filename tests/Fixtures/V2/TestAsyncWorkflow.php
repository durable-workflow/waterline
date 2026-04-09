<?php

declare(strict_types=1);

namespace Waterline\Tests\Fixtures\V2;

use Generator;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\async;
use Workflow\V2\Workflow;

#[Type('waterline-test-async-workflow')]
final class TestAsyncWorkflow extends Workflow
{
    public function execute(string $name): Generator
    {
        return yield async(static fn (): string => 'Hello, ' . $name . '!');
    }
}
