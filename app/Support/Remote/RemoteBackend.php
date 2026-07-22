<?php

declare(strict_types=1);

namespace Waterline\Support\Remote;

use BadMethodCallException;
use DurableWorkflow\Client;
use Waterline\Support\BackendConfiguration;

final class RemoteBackend
{
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

    /** @return array<string, bool> */
    public function capabilities(): array
    {
        $capabilities = BackendConfiguration::declaredCapabilities();
        $capabilities['health'] = $this->supports('systemHealth');
        $capabilities['metrics'] = $this->supports('operatorMetrics');
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
