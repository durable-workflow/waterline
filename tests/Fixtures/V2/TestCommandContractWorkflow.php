<?php

declare(strict_types=1);

namespace Waterline\Tests\Fixtures\V2;

use Generator;
use Workflow\QueryMethod;
use Workflow\UpdateMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\awaitSignal;
use Workflow\V2\Workflow;

#[Type('workflow.command-contract')]
#[Signal('approved-by', [
    ['name' => 'actor', 'type' => 'string'],
])]
#[Signal('rejected-by')]
final class TestCommandContractWorkflow extends Workflow
{
    public function execute(): Generator
    {
        if (false) {
            yield awaitSignal('approved-by');
        }

        return [];
    }

    #[QueryMethod('current-stage')]
    public function currentStage(): string
    {
        return 'waiting';
    }

    #[QueryMethod]
    public function stageMatches(string $stage): bool
    {
        return $stage === 'waiting';
    }

    #[UpdateMethod('mark-approved')]
    public function approve(bool $approved): array
    {
        return ['approved' => $approved];
    }
}
