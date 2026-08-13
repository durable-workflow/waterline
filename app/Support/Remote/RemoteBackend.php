<?php

declare(strict_types=1);

namespace Waterline\Support\Remote;

use BadMethodCallException;
use DurableWorkflow\Client;
use Throwable;
use Waterline\Support\BackendConfiguration;
use Waterline\Support\CapacityEvidence;

final class RemoteBackend
{
    /** @var array<string, mixed>|null */
    private ?array $operatorMetricsResponse = null;

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
     * @return array{available: bool, reason: string|null, metrics: array<string, mixed>, window: array<string, mixed>}
     */
    public function capacityEvidenceContract(int $windowSeconds): array
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
        );
    }

    public function supportsCapacityEvidence(): bool
    {
        foreach (CapacityEvidence::allowedWindowSeconds() as $windowSeconds) {
            try {
                $available = $this->capacityEvidenceContract($windowSeconds)['available'] === true;
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
    public function capabilities(): array
    {
        $capabilities = BackendConfiguration::declaredCapabilities();
        $capabilities['health'] = $this->supports('systemHealth');
        $capabilities['metrics'] = $this->supports('operatorMetrics');
        $capabilities['capacity_evidence'] = $this->supportsCapacityEvidence();
        $capabilities['dashboard_summary'] = $this->supports('operatorDashboard');
        $capabilities['workers'] = $this->supports('listWorkers');
        $capabilities['task_queues'] = $this->supports('listTaskQueues');
        $capabilities['repair'] = $capabilities['repair'] && $this->supports('repairWorkflow');
        $capabilities['archive'] = $capabilities['archive'] && $this->supports('archiveWorkflow');

        return $capabilities;
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        return BackendConfiguration::payload($this->capabilities());
    }
}
