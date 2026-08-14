<?php

declare(strict_types=1);

namespace Waterline\Http\Controllers\Remote;

use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Waterline\Http\Controllers\Controller;
use Waterline\Support\OperatorScope;
use Waterline\Support\Remote\RemoteBackend;

abstract class RemoteController extends Controller
{
    public function __construct(protected readonly RemoteBackend $backend)
    {
        parent::__construct();
    }

    protected function capabilityUnavailable(string $capability, ?string $method = null): JsonResponse
    {
        return response()->json([
            'message' => sprintf('The connected backend does not expose the [%s] capability.', $capability),
            'reason' => 'backend_capability_unavailable',
            'capability' => $capability,
            'required_sdk_method' => $method,
            'backend' => $this->backend->status(),
        ], 501);
    }

    protected function requireCapability(string $method, string $capability): ?JsonResponse
    {
        return $this->backend->supports($method)
            ? null
            : $this->capabilityUnavailable($capability, $method);
    }

    protected function requireWriteAccess(string $capability): ?JsonResponse
    {
        if (! $this->backend->readOnly()) {
            return null;
        }

        return response()->json([
            'message' => sprintf('Waterline is configured read-only; [%s] is not authorized.', $capability),
            'reason' => 'waterline_read_only',
            'capability' => $capability,
            'operator_scope' => OperatorScope::payload(),
            'backend' => $this->backend->status(),
        ], 403);
    }

    /** @param array<string, mixed> $payload */
    protected function scoped(array $payload, ?CarbonInterface $requestTime = null): array
    {
        $payload['operator_scope'] = OperatorScope::payload();
        $payload['backend'] = $this->backend->status($requestTime);

        return $payload;
    }
}
