<?php

namespace Waterline\Repositories\Workflow\Interfaces;

interface WorkflowRepositoryInterface
{
    public function engineSource(): string;

    public function completedFlows();

    public function failedFlows();

    public function runningFlows();

    public function findFlow(string $id);

    public function flowsPastHour();

    public function exceptionsPastHour();

    public function failedFlowsPastWeek();

    public function maxWaitTimeWorkflow();

    public function maxDurationWorkflow();

    public function maxExceptionsWorkflow();

    public function totalFlows();
}
