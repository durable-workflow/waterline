<?php

namespace Waterline\Http\Controllers;

use Illuminate\Http\Request;
use Waterline\Support\EngineSourceReadiness;
use Waterline\Support\HybridMigrationView;
use Waterline\Support\OperatorScope;
use Waterline\Support\WorkflowEngineSourceResolver;
use Waterline\Support\WorkflowClassification;
use Waterline\Repositories\Workflow\Interfaces\WorkflowRepositoryInterface;

class DashboardStatsController extends Controller
{
    public function index(Request $request, WorkflowRepositoryInterface $repository) {
        $engineSource = WorkflowEngineSourceResolver::status();

        if (EngineSourceReadiness::pinnedV2Unavailable($engineSource)) {
            return EngineSourceReadiness::unavailableResponse($engineSource);
        }

        $selection = WorkflowClassification::selection($request);
        $available = method_exists($repository, 'supportsWorkflowTypeDashboard')
            && $repository->supportsWorkflowTypeDashboard();
        $selection['available'] = $available;
        if ($selection['workflow_types'] !== null && ! $available) {
            return response()->json([
                'message' => 'The installed workflow observer cannot filter dashboard totals by workflow type.',
                'reason' => 'backend_capability_unavailable',
                'capability' => 'workflow_type_dashboard',
                'classification_scope' => $selection,
                'operator_scope' => OperatorScope::payload(),
            ], 501);
        }

        $summary = $selection['workflow_types'] === null
            ? $repository->dashboardStats()
            : $repository->dashboardStats($selection['workflow_types']);

        return response()->json([
            ...$summary,
            'classification_scope' => $selection,
            'operator_scope' => OperatorScope::payload(),
            'engine_source' => $engineSource,
            'hybrid_migration_view' => HybridMigrationView::status($engineSource),
        ]);
    }
}
