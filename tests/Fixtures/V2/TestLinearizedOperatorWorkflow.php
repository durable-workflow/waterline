<?php

declare(strict_types=1);

namespace Waterline\Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\UpdateMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\awaitSignal;
use Workflow\V2\Workflow;

#[Type('workflow.linearized-operator')]
#[Signal('advance', [
    ['name' => 'name', 'type' => 'string'],
])]
#[Signal('finish')]
final class TestLinearizedOperatorWorkflow extends Workflow
{
    private string $stage = 'booting';

    private ?string $name = null;

    private bool $approved = false;

    /**
     * @var list<string>
     */
    private array $events = [];

    public function handle(): array
    {
        $this->stage = 'waiting-for-advance';
        $this->events[] = 'started';

        $name = awaitSignal('advance');

        $this->name = $name;
        $this->stage = 'waiting-for-finish';
        $this->events[] = sprintf('signal:%s', $name);

        awaitSignal('finish');

        $this->stage = 'completed';
        $this->events[] = 'finish';

        return [
            'stage' => $this->stage,
            'name' => $this->name,
            'approved' => $this->approved,
            'events' => $this->events,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }

    #[QueryMethod('current-state')]
    public function currentState(): array
    {
        return [
            'stage' => $this->stage,
            'name' => $this->name,
            'approved' => $this->approved,
            'events' => $this->events,
        ];
    }

    #[UpdateMethod('mark-approved')]
    public function approve(bool $approved, string $source = 'waterline'): array
    {
        $this->approved = $approved;
        $this->events[] = sprintf('approved:%s:%s', $approved ? 'yes' : 'no', $source);

        return [
            'stage' => $this->stage,
            'name' => $this->name,
            'approved' => $this->approved,
            'events' => $this->events,
        ];
    }
}
