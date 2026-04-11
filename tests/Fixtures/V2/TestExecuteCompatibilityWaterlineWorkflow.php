<?php

declare(strict_types=1);

namespace Waterline\Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('waterline.execute-compatibility')]
final class TestExecuteCompatibilityWaterlineWorkflow extends Workflow
{
    public function execute(string $name = 'Taylor'): array
    {
        return [
            'greeting' => "Hello, {$name}!",
        ];
    }
}
