<?php

namespace Waterline\Http\Controllers;

use Waterline\Support\EngineSourceReadiness;
use Waterline\Support\WorkflowEngineSourceResolver;
use Waterline\Repositories\Workflow\Interfaces\WorkflowRepositoryInterface;

class DashboardStatsController extends Controller
{
    public function index(WorkflowRepositoryInterface $repository) {
        $engineSource = WorkflowEngineSourceResolver::status();

        if (EngineSourceReadiness::pinnedV2Unavailable($engineSource)) {
            return EngineSourceReadiness::unavailableResponse($engineSource);
        }

        return response()->json([
            ...$repository->dashboardStats(),
            'engine_source' => $engineSource,
        ]);
    }
}
