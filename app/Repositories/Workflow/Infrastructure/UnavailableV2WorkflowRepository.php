<?php

declare(strict_types=1);

namespace Waterline\Repositories\Workflow\Infrastructure;

use Illuminate\Http\Exceptions\HttpResponseException;
use Waterline\Repositories\Workflow\Interfaces\WorkflowRepositoryInterface;
use Waterline\Support\EngineSourceReadiness;
use Waterline\Support\WorkflowEngineSourceResolver;

final class UnavailableV2WorkflowRepository implements WorkflowRepositoryInterface
{
    /**
     * @param array<string, mixed>|null $status
     */
    public function __construct(
        private readonly ?array $status = null,
    ) {}

    public function engineSource(): string
    {
        $this->throwUnavailable();
    }

    public function completedFlows()
    {
        $this->throwUnavailable();
    }

    public function failedFlows()
    {
        $this->throwUnavailable();
    }

    public function cancelledFlows()
    {
        $this->throwUnavailable();
    }

    public function terminatedFlows()
    {
        $this->throwUnavailable();
    }

    public function runningFlows()
    {
        $this->throwUnavailable();
    }

    public function findFlow(string $id)
    {
        $this->throwUnavailable();
    }

    public function findFlowSelection(string $instanceId, ?string $runId = null)
    {
        $this->throwUnavailable();
    }

    public function dashboardStats()
    {
        $this->throwUnavailable();
    }

    public function flowsPastHour(): int
    {
        $this->throwUnavailable();
    }

    public function exceptionsPastHour(): int
    {
        $this->throwUnavailable();
    }

    public function failedFlowsPastWeek(): int
    {
        $this->throwUnavailable();
    }

    public function maxWaitTimeWorkflow()
    {
        $this->throwUnavailable();
    }

    public function maxDurationWorkflow()
    {
        $this->throwUnavailable();
    }

    public function maxExceptionsWorkflow()
    {
        $this->throwUnavailable();
    }

    public function totalFlows(): int
    {
        $this->throwUnavailable();
    }

    public function operatorMetrics()
    {
        $this->throwUnavailable();
    }

    /**
     * @return never
     */
    private function throwUnavailable(): void
    {
        throw new HttpResponseException(
            EngineSourceReadiness::unavailableResponse(
                $this->status ?? WorkflowEngineSourceResolver::status(),
            ),
        );
    }
}
