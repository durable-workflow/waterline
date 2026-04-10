<?php

namespace Waterline\Http\Controllers;

use Waterline\Repositories\Workflow\Interfaces\WorkflowRepositoryInterface;

class DashboardStatsController extends Controller
{
    public function index(WorkflowRepositoryInterface $repository) {

        return response()->json($repository->dashboardStats());
    }
}
