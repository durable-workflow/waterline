<?php

declare(strict_types=1);

namespace Waterline\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Waterline\Repositories\Workflow\Interfaces\WorkflowRepositoryInterface;
use Waterline\Support\CapacityEvidence;
use Waterline\Support\EmbeddedCapacityEvidenceCollector;
use Waterline\Support\EngineSourceReadiness;
use Waterline\Support\OperatorScope;
use Waterline\Support\WorkflowEngineSourceResolver;

final class CapacityEvidenceController extends Controller
{
    public function show(Request $request, WorkflowRepositoryInterface $repository): JsonResponse
    {
        $engineSource = WorkflowEngineSourceResolver::status();

        if (EngineSourceReadiness::pinnedV2Unavailable($engineSource)) {
            return EngineSourceReadiness::unavailableResponse($engineSource);
        }

        $windowSeconds = $this->windowSeconds($request);
        $now = now();
        $window = app(EmbeddedCapacityEvidenceCollector::class)->collect(
            $now->copy()->subSeconds($windowSeconds),
            $now,
            OperatorScope::namespace(),
        );
        $metrics = $repository->operatorMetrics();

        return response()->json(app(CapacityEvidence::class)->build(
            is_array($metrics) ? $metrics : [],
            $window,
            $now,
            $windowSeconds,
            OperatorScope::payload(),
            'embedded',
        ));
    }

    private function windowSeconds(Request $request): int
    {
        $allowed = CapacityEvidence::allowedWindowSeconds();
        $validated = $request->validate([
            'window_seconds' => ['nullable', 'integer', Rule::in($allowed)],
        ]);

        return (int) ($validated['window_seconds'] ?? CapacityEvidence::defaultWindowSeconds());
    }
}
