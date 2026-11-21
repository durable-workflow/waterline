<?php

declare(strict_types=1);

namespace Waterline\Http\Controllers\Remote;

use DurableWorkflow\Exception\ServerException;
use Illuminate\Http\JsonResponse;
use Waterline\Support\BackendConfiguration;
use Waterline\Support\NamespaceScopeRefusalVisibility;
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

        $workers = $this->listWorkers(null);
        $staleWorkers = $this->listWorkers('stale');
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

        if (($check = $this->workerListIncompleteCheck($workers, $staleWorkers)) !== null) {
            $checks[] = $check;
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
                'activity_dispatch_control' => $this->activityDispatchControl(),
            ],
        ]);

        $status = ($payload['status'] ?? null) === 'error' || ($payload['healthy'] ?? true) === false
            ? 503
            : 200;

        return response()->json(NamespaceScopeRefusalVisibility::forRequest($payload), $status);
    }

    /**
     * Whether the queue cards may offer Pause and Resume. Read-only mode
     * refuses before the Server is asked for its capabilities.
     *
     * @return array{available: bool, reason: string|null}
     */
    private function activityDispatchControl(): array
    {
        if ($this->backend->readOnly()) {
            return ['available' => false, 'reason' => 'waterline_read_only'];
        }

        return $this->backend->activityDispatchControl();
    }

    /**
     * Read every worker registration with one status filter.
     *
     * Native Server 3.13.0 and later page the worker list. An SDK with
     * listAllWorkers() follows the pages up to its page bound; an older SDK
     * reads only the first page, and a Server that does not page returns
     * every registration in one response either way.
     *
     * @return array<string, mixed>
     */
    private function listWorkers(?string $status): array
    {
        $client = $this->backend->client();

        return $this->backend->supports('listAllWorkers')
            ? $client->listAllWorkers(status: $status)
            : $client->listWorkers(status: $status);
    }

    /**
     * Warn when a worker list is truncated or still carries an unread continuation.
     *
     * @param array<string, mixed> $workers
     * @param array<string, mixed> $staleWorkers
     * @return array<string, mixed>|null
     */
    private function workerListIncompleteCheck(array $workers, array $staleWorkers): ?array
    {
        $truncated = ($workers['truncated'] ?? false) === true || ($staleWorkers['truncated'] ?? false) === true;
        $unread = static fn (array $response): bool => is_string($response['next_page_token'] ?? null)
            && $response['next_page_token'] !== '';
        if (! $truncated && ! $unread($workers) && ! $unread($staleWorkers)) {
            return null;
        }

        $pagingSdk = $this->backend->supports('listAllWorkers');
        [$reason, $message] = match (true) {
            $truncated => ['worker_list_truncated', 'The runtime read limit truncated the worker list. Registration counts and rosters cover only the returned workers.'],
            $pagingSdk => ['worker_list_page_bound_reached', 'The Server has more worker registrations than Waterline reads; worker counts and rosters cover only the registrations read.'],
            default => ['worker_list_paging_unsupported', 'The installed PHP SDK reads only the first page of the Server\'s worker list; worker counts and rosters cover only that page. Upgrade durable-workflow/sdk to a release with listAllWorkers().'],
        };

        return [
            'name' => 'worker_list_complete',
            'category' => 'correctness',
            'status' => 'warning',
            'message' => $message,
            'reason' => $reason,
        ];
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
