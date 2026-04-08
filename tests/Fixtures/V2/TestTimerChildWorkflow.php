<?php

declare(strict_types=1);

namespace Waterline\Tests\Fixtures\V2;

use Generator;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\timer;
use Workflow\V2\Workflow;

#[Type('waterline-test-timer-child-workflow')]
final class TestTimerChildWorkflow extends Workflow
{
    public function execute(int $seconds): Generator
    {
        yield timer($seconds);

        return [
            'waited' => true,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }
}
