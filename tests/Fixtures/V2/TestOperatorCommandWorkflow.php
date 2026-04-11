<?php

declare(strict_types=1);

namespace Waterline\Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\UpdateMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\awaitSignal;
use Workflow\V2\Workflow;

#[Type('workflow.operator-command')]
#[Signal('name-provided', [
    ['name' => 'name', 'type' => 'string'],
])]
final class TestOperatorCommandWorkflow extends Workflow
{
    private bool $approved = false;

    /**
     * @var list<string>
     */
    private array $events = [];

    public function execute(): array
    {
        $this->events[] = 'started';

        $name = awaitSignal('name-provided');

        $this->events[] = sprintf('signal:%s', $name);

        return [
            'approved' => $this->approved,
            'events' => $this->events,
            'name' => $name,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }

    #[QueryMethod('current-state')]
    public function currentState(): array
    {
        return [
            'approved' => $this->approved,
            'events' => $this->events,
        ];
    }

    #[QueryMethod('events-starting-with')]
    public function countEventsByPrefix(string $prefix): int
    {
        return count(array_filter(
            $this->events,
            static fn (string $event): bool => str_starts_with($event, $prefix),
        ));
    }

    #[UpdateMethod('mark-approved')]
    public function approve(bool $approved, string $source = 'waterline'): array
    {
        $this->approved = $approved;
        $this->events[] = sprintf('approved:%s:%s', $approved ? 'yes' : 'no', $source);

        return [
            'approved' => $this->approved,
            'events' => $this->events,
        ];
    }
}
