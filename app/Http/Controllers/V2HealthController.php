<?php

namespace Waterline\Http\Controllers;

use Waterline\Support\WorkflowEngineSourceResolver;
use Workflow\V2\Support\HealthCheck;

class V2HealthController extends Controller
{
    public function show()
    {
        $engineSource = WorkflowEngineSourceResolver::status();

        if (($engineSource['uses_v2'] ?? false) !== true) {
            return response()->json([
                'generated_at' => now()->toJSON(),
                'status' => 'error',
                'healthy' => false,
                'checks' => [
                    [
                        'name' => 'engine_source',
                        'status' => 'error',
                        'message' => $engineSource['message'] ?? 'Waterline is not currently using the v2 operator bridge.',
                        'meta' => [
                            'configured' => $engineSource['configured'] ?? null,
                            'resolved' => $engineSource['resolved'] ?? null,
                            'uses_v2' => $engineSource['uses_v2'] ?? false,
                            'v2_operator_surface_available' => $engineSource['v2_operator_surface_available'] ?? false,
                            'issue_count' => count(is_array($engineSource['issues'] ?? null) ? $engineSource['issues'] : []),
                        ],
                    ],
                ],
                'engine_source' => $engineSource,
                'readiness_contract' => $engineSource['readiness_contract'] ?? null,
            ], 503);
        }

        $snapshot = HealthCheck::snapshot();
        array_unshift($snapshot['checks'], [
            'name' => 'engine_source',
            'status' => 'ok',
            'message' => $engineSource['message'] ?? 'Waterline is using the v2 operator bridge.',
            'meta' => [
                'configured' => $engineSource['configured'] ?? null,
                'resolved' => $engineSource['resolved'] ?? null,
                'uses_v2' => $engineSource['uses_v2'] ?? false,
                'v2_operator_surface_available' => $engineSource['v2_operator_surface_available'] ?? false,
                'issue_count' => count(is_array($engineSource['issues'] ?? null) ? $engineSource['issues'] : []),
            ],
        ]);
        $snapshot['engine_source'] = $engineSource;
        $snapshot['readiness_contract'] = $engineSource['readiness_contract'] ?? null;

        return response()->json($snapshot, HealthCheck::httpStatus($snapshot));
    }
}
