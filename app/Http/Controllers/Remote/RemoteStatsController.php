<?php

declare(strict_types=1);

namespace Waterline\Http\Controllers\Remote;

use Illuminate\Http\JsonResponse;
use Waterline\Support\BackendConfiguration;
use Waterline\Support\Remote\RemoteBackend;

final class RemoteStatsController extends RemoteController
{
    public function __construct(RemoteBackend $backend)
    {
        parent::__construct($backend);
    }

    public function index(): JsonResponse
    {
        if ($this->backend->supports('operatorDashboard')) {
            $response = $this->backend->client()->operatorDashboard();
            $summary = is_array($response['dashboard'] ?? null)
                ? $response['dashboard']
                : $response;
        } else {
            if ($response = $this->requireCapability('operatorMetrics', 'metrics')) {
                return $response;
            }

            $response = $this->backend->client()->operatorMetrics();
            $metrics = is_array($response['operator_metrics'] ?? null)
                ? $response['operator_metrics']
                : $response;
            $summary = $this->summaryFromMetrics($metrics);
        }

        return response()->json($this->scoped([
            ...$summary,
            'engine_source' => [
                'configured' => 'service',
                'resolved' => 'service',
                'uses_v2' => true,
                'v2_operator_surface_available' => true,
                'status' => 'remote_service',
                'message' => 'Waterline is observing a standalone Durable Workflow server through the PHP SDK.',
            ],
            'backend_contract' => BackendConfiguration::payload($this->backend->capabilities()),
        ]));
    }

    /**
     * Older SDK releases expose operator metrics without the dashboard
     * summary endpoint. Preserve truthful core counts while advertising the
     * unavailable richer capability through the backend contract.
     *
     * @param array<string, mixed> $metrics
     * @return array<string, mixed>
     */
    private function summaryFromMetrics(array $metrics): array
    {
        return [
            'operator_metrics' => $metrics,
            'fleet_overview' => [
                'current' => [
                    'running' => (int) ($metrics['runs']['running'] ?? 0),
                    'failed' => (int) ($metrics['runs']['failed'] ?? 0),
                ],
            ],
            'flows' => (int) ($metrics['runs']['total'] ?? 0),
            'needs_attention' => [
                'total_alerts' => 0,
                'has_critical' => false,
                'alerts' => [],
                'reason' => 'backend_capability_unavailable',
            ],
            'workflow_type_health' => [],
            'fleet_trends_series' => null,
        ];
    }
}
