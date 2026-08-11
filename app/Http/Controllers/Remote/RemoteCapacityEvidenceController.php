<?php

declare(strict_types=1);

namespace Waterline\Http\Controllers\Remote;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Waterline\Support\CapacityEvidence;
use Waterline\Support\OperatorScope;
use Waterline\Support\Remote\RemoteBackend;

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
        $response = $this->backend->client()->operatorMetrics();
        $metrics = is_array($response['operator_metrics'] ?? null)
            ? $response['operator_metrics']
            : $response;
        $window = $this->matchingWindow(
            is_array($metrics['capacity_evidence'] ?? null) ? $metrics['capacity_evidence'] : [],
            $windowSeconds,
        );

        return response()->json($this->scoped(app(CapacityEvidence::class)->build(
            is_array($metrics) ? $metrics : [],
            $window,
            now(),
            $windowSeconds,
            OperatorScope::payload(),
            'service',
        )));
    }

    /**
     * The operator-metrics SDK method intentionally remains an additive raw
     * payload contract. Only consume an upstream capacity block when it names
     * the requested window; otherwise Waterline would mislabel a fixed-window
     * count as evidence for a different observation period.
     *
     * @param array<string, mixed> $capacity
     * @return array<string, mixed>
     */
    private function matchingWindow(array $capacity, int $windowSeconds): array
    {
        $windows = is_array($capacity['windows'] ?? null) ? $capacity['windows'] : [];
        $selected = $windows[(string) $windowSeconds] ?? $windows[$windowSeconds] ?? null;

        if (is_array($selected)) {
            return $selected;
        }

        return (int) data_get($capacity, 'observation_window.duration_seconds') === $windowSeconds
            ? $capacity
            : [];
    }
}
