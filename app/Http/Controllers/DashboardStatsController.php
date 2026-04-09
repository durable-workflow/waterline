<?php

namespace Waterline\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Waterline\Repositories\Workflow\Interfaces\WorkflowRepositoryInterface;
use Workflow\Models\StoredWorkflow;
use Workflow\Models\StoredWorkflowException;

class DashboardStatsController extends Controller
{
    public function index(WorkflowRepositoryInterface $repository) {

        $flowsPastHour = $repository->flowsPastHour();

        return response()->json([
            'flows' => $repository->totalFlows(),
            'flows_per_minute' => $flowsPastHour / 60,
            'flows_past_hour' => $flowsPastHour,
            'exceptions_past_hour' => $repository->exceptionsPastHour(),
            'failed_flows_past_week' => $repository->failedFlowsPastWeek(),
            'max_wait_time_workflow' => $repository->maxWaitTimeWorkflow(),
            'max_duration_workflow' => $repository->maxDurationWorkflow(),
            'max_exceptions_workflow' => $repository->maxExceptionsWorkflow(),
            'operator_metrics' => $repository->operatorMetrics(),
        ]);
    }
}
