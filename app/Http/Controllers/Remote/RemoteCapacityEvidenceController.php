<?php

declare(strict_types=1);

namespace Waterline\Http\Controllers\Remote;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Waterline\Support\CapacityEvidence;
use Waterline\Support\OperatorScope;
use Waterline\Support\Remote\RemoteBackend;
use Waterline\Support\Remote\RemoteCapacityEvidenceContract;

final class RemoteCapacityEvidenceController extends RemoteController
{
    public function __construct(RemoteBackend $backend)
    {
        parent::__construct($backend);
    }

    public function show(Request $request): JsonResponse
    {
        if ($response = $this->requireCapability('operatorMetrics', 'capacity_evidence')) {
            return $response;
        }

        $allowed = CapacityEvidence::allowedWindowSeconds();
        $validated = $request->validate([
            'window_seconds' => ['nullable', 'integer', Rule::in($allowed)],
        ]);
        $windowSeconds = (int) ($validated['window_seconds'] ?? CapacityEvidence::defaultWindowSeconds());
        $contract = $this->backend->capacityEvidenceContract($windowSeconds);

        if ($contract['available'] !== true) {
            return response()->json([
                'message' => 'The connected backend does not expose the required namespace capacity-evidence contract.',
                'reason' => 'capacity_evidence_contract_unavailable',
                'capability' => 'capacity_evidence',
                'contract_failure' => $contract['reason'],
                'required_contract' => [
                    'schema' => RemoteCapacityEvidenceContract::SCHEMA,
                    'schema_version' => RemoteCapacityEvidenceContract::VERSION,
                    'namespace' => OperatorScope::namespace(),
                    'window_seconds' => $windowSeconds,
                ],
                'backend' => $this->backend->status(),
            ], 501);
        }

        return response()->json($this->scoped(app(CapacityEvidence::class)->build(
            $contract['metrics'],
            $contract['window'],
            now(),
            $windowSeconds,
            OperatorScope::payload(),
            'service',
        )));
    }
}
