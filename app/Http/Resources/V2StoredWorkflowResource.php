<?php

namespace Waterline\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Workflow\V2\Contracts\OperatorObservabilityRepository;
use Workflow\V2\Models\WorkflowRun;
use Waterline\Support\ActionabilityContract;
use Waterline\Support\CompatibilitySemantics;
use Waterline\Support\ObserverStateEnvelope;
use Waterline\Support\OperatorScope;
use Waterline\Support\RunDiagnostics;

/**
 * @mixin WorkflowRun
 */
class V2StoredWorkflowResource extends JsonResource
{
    public static $wrap = null;

    private const DEFAULT_TIMELINE_WINDOW = 200;
    private const MIN_TIMELINE_WINDOW = 50;
    private const MAX_TIMELINE_WINDOW = 1000;

    public function toArray($request)
    {
        $detail = app(OperatorObservabilityRepository::class)->runDetail(
            $this->resource,
            $this->timelineLimit($request),
        );
        $detail = $this->withTimelineWindow($detail, $request);
        $detail['workflow_instance_id'] ??= $detail['instance_id'] ?? $this->resource->workflow_instance_id;
        $detail['workflow_run_id'] ??= $detail['run_id'] ?? $detail['selected_run_id'] ?? $this->resource->id;
        $detail['run_diagnostics'] = app(RunDiagnostics::class)->forRun($this->resource, $detail);
        $detail = ObserverStateEnvelope::annotateRun($detail, $this->observerPaths($request, $detail));
        $detail = CompatibilitySemantics::annotateRun($detail);
        $detail['namespace'] = $this->resource->namespace;
        $detail['operator_scope'] = OperatorScope::payload();

        return ActionabilityContract::annotateRun($detail);
    }

    /**
     * @param array<string, mixed> $detail
     *
     * @return array<string, string|null>
     */
    private function observerPaths($request, array $detail): array
    {
        $waterlinePath = trim((string) config('waterline.path', 'waterline'), '/');
        $basePath = ($waterlinePath === '' ? '' : '/'.$waterlinePath).'/api';
        $instanceId = $this->pathValue($detail['instance_id'] ?? null);
        $runId = $this->pathValue($detail['run_id'] ?? $detail['selected_run_id'] ?? null);

        return [
            'selected_run_detail' => '/'.ltrim((string) $request->path(), '/'),
            'selected_run_query_template' => $instanceId === null || $runId === null
                ? null
                : sprintf('%s/instances/%s/runs/%s/queries/{query}', $basePath, $instanceId, $runId),
            'instance_query_template' => $instanceId === null
                ? null
                : sprintf('%s/instances/%s/queries/{query}', $basePath, $instanceId),
        ];
    }

    private function pathValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? rawurlencode($value) : null;
    }

    /**
     * @param array<string, mixed> $detail
     *
     * @return array<string, mixed>
     */
    private function withTimelineWindow(array $detail, $request): array
    {
        $timeline = is_array($detail['timeline'] ?? null)
            ? array_values($detail['timeline'])
            : [];
        $limit = $this->timelineLimit($request);
        $total = $this->timelineTotal($detail, count($timeline));

        if ($limit !== null && count($timeline) > $limit) {
            $timeline = array_slice($timeline, -$limit);
        }

        $returned = count($timeline);
        $detail['timeline'] = $timeline;
        $detail['timeline_total_count'] = $total;
        $detail['timeline_returned_count'] = $returned;
        $detail['timeline_window_limit'] = $limit;
        $detail['timeline_window_direction'] = 'latest';
        $detail['timeline_truncated'] = $returned < $total;
        $detail['timeline_older_count'] = max(0, $total - $returned);
        $detail['timeline_window_start_sequence'] = $this->timelineBoundary($timeline, 'first');
        $detail['timeline_window_end_sequence'] = $this->timelineBoundary($timeline, 'last');

        return $detail;
    }

    private function timelineLimit($request): ?int
    {
        $requested = $request->query('history_limit', self::DEFAULT_TIMELINE_WINDOW);

        if ($requested === 'all') {
            return null;
        }

        $limit = filter_var($requested, FILTER_VALIDATE_INT);

        if ($limit === false) {
            $limit = self::DEFAULT_TIMELINE_WINDOW;
        }

        return min(self::MAX_TIMELINE_WINDOW, max(self::MIN_TIMELINE_WINDOW, $limit));
    }

    /**
     * @param array<string, mixed> $detail
     */
    private function timelineTotal(array $detail, int $fallback): int
    {
        $total = $detail['timeline_total_count'] ?? null;

        return is_numeric($total) ? max(0, (int) $total) : $fallback;
    }

    /**
     * @param list<array<string, mixed>> $timeline
     */
    private function timelineBoundary(array $timeline, string $position): ?int
    {
        if ($timeline === []) {
            return null;
        }

        $entry = $position === 'last'
            ? $timeline[array_key_last($timeline)]
            : $timeline[0];

        $sequence = $entry['sequence'] ?? null;

        return is_numeric($sequence) ? (int) $sequence : null;
    }

}
