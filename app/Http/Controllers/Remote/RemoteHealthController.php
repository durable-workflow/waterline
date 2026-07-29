<?php

declare(strict_types=1);

namespace Waterline\Http\Controllers\Remote;

use DurableWorkflow\Exception\ServerException;
use Illuminate\Http\JsonResponse;
use Waterline\Support\BackendConfiguration;
use Waterline\Support\Remote\RemoteBackend;
use Waterline\Support\WorkerRegistrationRoster;

final class RemoteHealthController extends RemoteController
{
    public function __construct(RemoteBackend $backend)
    {
        parent::__construct($backend);
    }

    public function show(): JsonResponse
    {
        if ($response = $this->requireCapability('listWorkers', 'workers')) {
            return $response;
        }
        if ($response = $this->requireCapability('listTaskQueues', 'task_queues')) {
            return $response;
        }

        $workers = $this->backend->client()->listWorkers();
        $staleWorkers = $this->backend->client()->listWorkers(status: 'stale');
        $queues = $this->backend->client()->listTaskQueues();
        $health = [];
        $checks = [];

        if ($this->backend->supports('systemHealth')) {
            try {
                $response = $this->backend->client()->systemHealth();
                $health = is_array($response['health'] ?? null) ? $response['health'] : $response;
                $checks = is_array($health['checks'] ?? null) ? $health['checks'] : [];
            } catch (ServerException $exception) {
                $details = is_array($exception->details) ? $exception->details : [];
                $remoteHealth = is_array($details['health'] ?? null) ? $details['health'] : [];
                if ($remoteHealth !== []) {
                    $health = $remoteHealth;
                    $checks = is_array($health['checks'] ?? null) ? $health['checks'] : [];
                }
                $checks[] = $this->failureCheck('server_health', $exception);
            }
        } else {
            $checks[] = [
                'name' => 'server_health',
                'category' => 'correctness',
                'status' => 'warning',
                'message' => 'The installed PHP SDK does not expose system health.',
                'reason' => 'backend_capability_unavailable',
            ];
        }

        $roster = WorkerRegistrationRoster::from(
            $this->workerRegistrations($workers),
            $this->workerRegistrations($staleWorkers),
        );
        $taskQueues = is_array($queues['task_queues'] ?? null) ? $queues['task_queues'] : [];
        $operatorMetrics = is_array($health['operator_metrics'] ?? null) ? $health['operator_metrics'] : [];
        $workerMetrics = is_array($operatorMetrics['workers'] ?? null) ? $operatorMetrics['workers'] : [];

        $payload = $this->scoped([
            ...$health,
            'status' => $health['status'] ?? ($checks === [] ? 'healthy' : 'warning'),
            'namespace' => BackendConfiguration::namespace(),
            'checks' => $checks,
            'operator_metrics' => [
                ...$operatorMetrics,
                'workers' => [
                    ...$workerMetrics,
                    'active_workers' => $workerMetrics['active_workers'] ?? $roster['active_registration_count'],
                    'active_worker_scopes' => $workerMetrics['active_worker_scopes'] ?? $roster['active_registration_count'],
                    'active_workers_supporting_required' => $workerMetrics['active_workers_supporting_required'] ?? $roster['active_registration_count'],
                    ...$roster,
                ],
            ],
            'queue_visibility' => [
                'available' => true,
                'namespace' => BackendConfiguration::namespace(),
                'task_queues' => $taskQueues,
            ],
        ]);

        $status = ($payload['status'] ?? null) === 'error' || ($payload['healthy'] ?? true) === false
            ? 503
            : 200;

        return response()->json($payload, $status);
    }

    /**
     * @param array<string, mixed> $response
     * @return list<array<string, mixed>>
     */
    private function workerRegistrations(array $response): array
    {
        $registrations = [];

        foreach (is_array($response['workers'] ?? null) ? $response['workers'] : [] as $worker) {
            if (! is_array($worker)) {
                continue;
            }

            $registrations[] = $worker;
        }

        return $registrations;
    }

    /** @return array<string, mixed> */
    private function failureCheck(string $name, ServerException $exception): array
    {
        return [
            'name' => $name,
            'category' => 'correctness',
            'status' => $exception->status === 401 || $exception->status === 403 ? 'warning' : 'error',
            'message' => $exception->getMessage(),
            'reason' => $exception->reason ?? 'remote_transport_failure',
            'http_status' => $exception->status,
        ];
    }
}
