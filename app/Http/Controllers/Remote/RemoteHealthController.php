<?php

declare(strict_types=1);

namespace Waterline\Http\Controllers\Remote;

use DurableWorkflow\Exception\ServerException;
use Illuminate\Http\JsonResponse;
use Waterline\Support\BackendConfiguration;
use Waterline\Support\Remote\RemoteBackend;

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

        $registrations = $this->workerRegistrations($workers, $staleWorkers);
        $taskQueues = is_array($queues['task_queues'] ?? null) ? $queues['task_queues'] : [];
        $operatorMetrics = is_array($health['operator_metrics'] ?? null) ? $health['operator_metrics'] : [];
        $workerMetrics = is_array($operatorMetrics['workers'] ?? null) ? $operatorMetrics['workers'] : [];
        $stale = count(array_filter(
            $registrations,
            static fn (mixed $worker): bool => is_array($worker) && ($worker['status'] ?? null) === 'stale',
        ));

        $payload = $this->scoped([
            ...$health,
            'status' => $health['status'] ?? ($checks === [] ? 'healthy' : 'warning'),
            'namespace' => BackendConfiguration::namespace(),
            'checks' => $checks,
            'operator_metrics' => [
                ...$operatorMetrics,
                'workers' => [
                    ...$workerMetrics,
                    'active_workers' => $workerMetrics['active_workers'] ?? count($registrations) - $stale,
                    'active_worker_scopes' => $workerMetrics['active_worker_scopes'] ?? count($registrations) - $stale,
                    'active_workers_supporting_required' => $workerMetrics['active_workers_supporting_required'] ?? count($registrations) - $stale,
                    'stale_registration_count' => $stale,
                    'registrations' => $registrations,
                    'stale_registrations' => array_values(array_filter(
                        $registrations,
                        static fn (mixed $worker): bool => is_array($worker) && ($worker['status'] ?? null) === 'stale',
                    )),
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
     * @param array<string, mixed> $active
     * @param array<string, mixed> $stale
     * @return list<array<string, mixed>>
     */
    private function workerRegistrations(array $active, array $stale): array
    {
        $registrations = [];

        foreach ([$active['workers'] ?? [], $stale['workers'] ?? []] as $workers) {
            foreach (is_array($workers) ? $workers : [] as $worker) {
                if (! is_array($worker)) {
                    continue;
                }

                $key = (string) ($worker['worker_id'] ?? '').'|'.(string) ($worker['task_queue'] ?? '');
                $registrations[$key] = $worker;
            }
        }

        return array_values($registrations);
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
