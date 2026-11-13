<?php

declare(strict_types=1);

namespace Waterline\Support\Remote;

use BadMethodCallException;
use Carbon\CarbonInterface;
use DurableWorkflow\Client;
use DurableWorkflow\Exception\DurableWorkflowException;
use DurableWorkflow\Exception\ServerException;
use Throwable;
use Waterline\Support\BackendConfiguration;
use Waterline\Support\CapacityEvidence;
use Waterline\Support\RequestTimeouts;

final class RemoteBackend
{
    /** Cluster info capability for the per-queue activity dispatch pause (native Server 3.8.0). */
    public const ACTIVITY_DISPATCH_PAUSE_CAPABILITY = 'task_queue_activity_dispatch_pause';

    /** SDK methods the pause and resume actions need. */
    public const ACTIVITY_DISPATCH_METHODS = [
        'clusterInfo',
        'pauseTaskQueueActivityDispatch',
        'resumeTaskQueueActivityDispatch',
    ];

    /** @var array{available: bool, reason: string|null}|null */
    private ?array $activityDispatchControl = null;

    /** @var array<string, mixed>|null */
    private ?array $operatorMetricsResponse = null;

    /** @var array<string, mixed>|null */
    private ?array $boundedDashboardResponse = null;

    private ?bool $boundedDashboardAvailable = null;

    private ?bool $workflowStreamsAvailable = null;

    private ?bool $serviceCatalogAvailable = null;

    public function __construct(private readonly object $client)
    {
    }

    public static function fromConfig(): self
    {
        $endpoint = trim((string) config('waterline.service.endpoint', ''));

        if ($endpoint === '') {
            throw new \InvalidArgumentException('WATERLINE_SERVER_ENDPOINT is required in service mode.');
        }

        $token = trim((string) config('waterline.service.token', ''));

        $options = [
            'namespace' => BackendConfiguration::namespace(),
            'controlToken' => $token !== '' ? $token : null,
        ] + RequestTimeouts::serviceClientOptions(Client::class);

        return new self(new Client($endpoint, ...$options));
    }

    public function client(): object
    {
        return $this->client;
    }

    public function supports(string $method): bool
    {
        return method_exists($this->client, $method);
    }

    public function require(string $method, string $capability): void
    {
        if (! $this->supports($method)) {
            throw new BadMethodCallException(sprintf(
                'The installed durable-workflow/sdk does not expose the [%s] capability required for %s.',
                $method,
                $capability,
            ));
        }
    }

    public function readOnly(): bool
    {
        return BackendConfiguration::accessMode() === 'read_only';
    }

    /** @return array<string, mixed> */
    public function serviceCatalogRead(string $method, array $arguments): array
    {
        try {
            $response = $this->client->{$method}(...$arguments);
        } catch (ServerException $exception) {
            if ($exception->status === 501
                && $exception->reason === 'backend_capability_unavailable'
                && ($exception->details['capability'] ?? null) === 'service_catalog_reads') {
                $this->serviceCatalogAvailable = false;
            }

            throw $exception;
        }

        $this->serviceCatalogAvailable = true;

        return $response;
    }

    /**
     * Whether this backend can pause and resume activity dispatch on a task
     * queue: the SDK must expose the calls and the Server must advertise the
     * capability. Servers without it (published PHP 2.x, older native) have
     * no pause routes, so nothing is offered. Read once per request.
     *
     * @return array{available: bool, reason: string|null}
     */
    public function activityDispatchControl(): array
    {
        if ($this->activityDispatchControl !== null) {
            return $this->activityDispatchControl;
        }

        foreach (self::ACTIVITY_DISPATCH_METHODS as $method) {
            if (! $this->supports($method)) {
                return $this->activityDispatchControl = [
                    'available' => false,
                    'reason' => 'activity_dispatch_sdk_unavailable',
                ];
            }
        }

        try {
            $capabilities = $this->client->clusterInfo()->capabilities;
        } catch (DurableWorkflowException) {
            return $this->activityDispatchControl = [
                'available' => false,
                'reason' => 'activity_dispatch_capability_unreadable',
            ];
        }

        return $this->activityDispatchControl = ($capabilities[self::ACTIVITY_DISPATCH_PAUSE_CAPABILITY] ?? null) === true
            ? ['available' => true, 'reason' => null]
            : ['available' => false, 'reason' => 'activity_dispatch_server_unavailable'];
    }

    /** @return array<string, mixed> */
    public function operatorMetrics(): array
    {
        if ($this->operatorMetricsResponse !== null) {
            return $this->operatorMetricsResponse;
        }

        /** @var array<string, mixed> $response */
        $response = $this->client->operatorMetrics();
        $this->operatorMetricsResponse = $response;

        return $response;
    }

    /**
     * @param list<string>|null $workflowTypes
     * @return array<string, mixed>
     */
    public function operatorDashboard(?array $workflowTypes = null): array
    {
        if ($workflowTypes !== null) {
            $this->require('workflowTypeOperatorDashboard', 'workflow type dashboard');
            $response = $this->client->workflowTypeOperatorDashboard($workflowTypes);
            $this->boundedDashboardResponse = $response;
            $this->boundedDashboardAvailable = ($response['dashboard']['operator_metrics']['history_audit_evaluation'] ?? null)
                === 'not_requested';

            return $response;
        }

        return $this->boundedDashboard() ?? $this->client->operatorDashboard();
    }

    /** @return array<string, mixed>|null */
    private function boundedDashboard(): ?array
    {
        if ($this->boundedDashboardResponse !== null) {
            return $this->boundedDashboardResponse;
        }
        if ($this->boundedDashboardAvailable === false || ! $this->supports('boundedOperatorDashboard')) {
            return null;
        }

        try {
            $response = $this->client->boundedOperatorDashboard();
        } catch (ServerException $exception) {
            // Older Servers lack this additive route. Other refusals retain
            // their original evidence and must not trigger another read.
            if ($exception->status !== 404 || ! in_array($exception->reason, [null, 'not_found'], true)) {
                throw $exception;
            }
            $this->boundedDashboardAvailable = false;

            return null;
        }

        $this->boundedDashboardResponse = $response;
        $this->boundedDashboardAvailable = ($response['dashboard']['operator_metrics']['history_audit_evaluation'] ?? null)
            === 'not_requested';

        return $response;
    }

    /**
     * @return array{state: string, available: bool, reason: string|null, streams: iterable<object|array<string, mixed>>}
     */
    public function workflowStreams(string $workflowId, string $runId): array
    {
        if (! $this->supports('listWorkflowStreams')) {
            $this->workflowStreamsAvailable = false;

            return [
                'state' => 'unavailable',
                'available' => false,
                'reason' => 'workflow_streams_sdk_method_missing',
                'streams' => [],
            ];
        }

        try {
            $streams = $this->client->listWorkflowStreams($workflowId, $runId);
        } catch (ServerException $exception) {
            if (! $this->workflowStreamsRouteUnsupported($exception)) {
                throw $exception;
            }

            $this->workflowStreamsAvailable = false;

            return [
                'state' => 'unavailable',
                'available' => false,
                'reason' => 'workflow_streams_route_unsupported',
                'streams' => [],
            ];
        }

        if (! is_iterable($streams)) {
            $this->workflowStreamsAvailable = false;

            return [
                'state' => 'degraded',
                'available' => false,
                'reason' => 'workflow_streams_invalid_response',
                'streams' => [],
            ];
        }

        $this->workflowStreamsAvailable = true;

        return [
            'state' => 'available',
            'available' => true,
            'reason' => null,
            'streams' => $streams,
        ];
    }

    /**
     * @return array{available: bool, reason: string|null, metrics: array<string, mixed>, window: array<string, mixed>}
     */
    public function capacityEvidenceContract(
        int $windowSeconds,
        ?CarbonInterface $requestTime = null,
    ): array
    {
        if (! $this->supports('operatorMetrics')) {
            return [
                'available' => false,
                'reason' => 'operator_metrics_sdk_method_missing',
                'metrics' => [],
                'window' => [],
            ];
        }

        $dashboard = $this->boundedDashboard();
        $metricsResponse = $dashboard === null
            ? $this->operatorMetrics()
            : [
                'namespace' => $dashboard['namespace'] ?? null,
                'operator_metrics' => $dashboard['dashboard']['operator_metrics'] ?? [],
            ];

        return app(RemoteCapacityEvidenceContract::class)->inspect(
            $metricsResponse,
            BackendConfiguration::namespace(),
            $windowSeconds,
            $requestTime ?? now(),
        );
    }

    public function supportsCapacityEvidence(?CarbonInterface $requestTime = null): bool
    {
        $requestTime ??= now();

        foreach (CapacityEvidence::allowedWindowSeconds() as $windowSeconds) {
            try {
                $available = $this->capacityEvidenceContract(
                    $windowSeconds,
                    $requestTime,
                )['available'] === true;
            } catch (Throwable) {
                return false;
            }

            if (! $available) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, bool> */
    public function capabilities(?CarbonInterface $requestTime = null): array
    {
        $capabilities = BackendConfiguration::declaredCapabilities();
        $capabilities['health'] = $this->supports('systemHealth');
        $capabilities['metrics'] = $this->supports('operatorMetrics');
        $capabilities['capacity_evidence'] = $this->supportsCapacityEvidence($requestTime);
        $capabilities['dashboard_summary'] = $this->supports('operatorDashboard');
        $capabilities['bounded_dashboard_summary'] = $this->boundedDashboardAvailable === true;
        $capabilities['workflow_type_dashboard_summary'] = $this->boundedDashboardAvailable === true
            && is_array($this->boundedDashboardResponse['dashboard']['workflow_scope'] ?? null)
            && $this->supports('workflowTypeOperatorDashboard');
        $capabilities['workers'] = $this->supports('listWorkers');
        $capabilities['task_queues'] = $this->supports('listTaskQueues');
        $capabilities['workflow_streams'] = $this->workflowStreamsAvailable
            ?? $this->supports('listWorkflowStreams');
        $capabilities['services'] = $this->serviceCatalogAvailable === true;
        $capabilities['repair'] = $capabilities['repair'] && $this->supports('repairWorkflow');
        $capabilities['redrive'] = $capabilities['redrive'] && $this->supports('redriveWorkflow');
        $capabilities['archive'] = $capabilities['archive'] && $this->supports('archiveWorkflow');

        return $capabilities;
    }

    /** @return array<string, mixed> */
    public function status(?CarbonInterface $requestTime = null): array
    {
        return BackendConfiguration::payload($this->capabilities($requestTime));
    }

    private function workflowStreamsRouteUnsupported(ServerException $exception): bool
    {
        return $exception->status === 404
            && in_array($exception->reason, [null, '', 'not_found', 'route_not_found'], true);
    }
}
