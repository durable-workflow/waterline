<?php

declare(strict_types=1);

namespace Waterline\Support\Remote;

use BadMethodCallException;
use Carbon\CarbonInterface;
use DurableWorkflow\Client;
use DurableWorkflow\Exception\ServerException;
use Throwable;
use Waterline\Support\BackendConfiguration;
use Waterline\Support\CapacityEvidence;

final class RemoteBackend
{
    /** @var array<string, mixed>|null */
    private ?array $operatorMetricsResponse = null;

    private ?bool $workflowStreamsAvailable = null;

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

        return new self(new Client(
            $endpoint,
            namespace: BackendConfiguration::namespace(),
            controlToken: $token !== '' ? $token : null,
        ));
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
     * @return array{available: bool, reason: string|null, streams: iterable<object|array<string, mixed>>}
     */
    public function workflowStreams(string $workflowId, string $runId): array
    {
        if (! $this->supports('listWorkflowStreams')) {
            $this->workflowStreamsAvailable = false;

            return [
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
                'available' => false,
                'reason' => 'workflow_streams_route_unsupported',
                'streams' => [],
            ];
        }

        $this->workflowStreamsAvailable = true;

        return [
            'available' => true,
            'reason' => null,
            'streams' => is_iterable($streams) ? $streams : [],
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

        return app(RemoteCapacityEvidenceContract::class)->inspect(
            $this->operatorMetrics(),
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
        $capabilities['workers'] = $this->supports('listWorkers');
        $capabilities['task_queues'] = $this->supports('listTaskQueues');
        $capabilities['workflow_streams'] = $this->workflowStreamsAvailable
            ?? $this->supports('listWorkflowStreams');
        $capabilities['repair'] = $capabilities['repair'] && $this->supports('repairWorkflow');
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
