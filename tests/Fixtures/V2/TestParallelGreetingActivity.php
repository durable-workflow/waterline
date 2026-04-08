<?php

declare(strict_types=1);

namespace Waterline\Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Activity;

#[Type('parallel-greeting')]
final class TestParallelGreetingActivity extends Activity
{
    public function execute(string $name): string
    {
        return "Hello, {$name}!";
    }
}
