<?php

namespace Waterline\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Workflow\V2\Contracts\OperatorObservabilityRepository;
use Workflow\V2\Models\WorkflowRun;
use Waterline\Support\ActionabilityContract;
use Waterline\Support\CompatibilitySemantics;
use Waterline\Support\RunDiagnostics;
use Waterline\Support\VisibilityMetadataBridge;

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
        $detail = $this->withLegacyVisibilityMetadata($detail);
        $detail = $this->withTimelineWindow($detail, $request);
        $detail['run_diagnostics'] = app(RunDiagnostics::class)->forRun($this->resource, $detail);
        $detail = CompatibilitySemantics::annotateRun($detail);

        return ActionabilityContract::annotateRun($detail);
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

    /**
     * @param array<string, mixed> $detail
     *
     * @return array<string, mixed>
     */
    private function withLegacyVisibilityMetadata(array $detail): array
    {
        $summary = $this->resource->summary;

        $detail['memo'] = VisibilityMetadataBridge::preserve(
            $detail['memo'] ?? null,
            $this->resource->memo,
            $this->attributeValue($this->resource, 'memo'),
            $this->resource->getRawOriginal('memo'),
            $this->resource->instance?->memo,
            $this->attributeValue($this->resource->instance, 'memo'),
            $this->resource->instance?->getRawOriginal('memo'),
            $this->databaseAttributeValue($this->resource, 'memo'),
            $this->databaseAttributeValue($this->resource->instance, 'memo'),
        );
        $detail['search_attributes'] = VisibilityMetadataBridge::preserve(
            $detail['search_attributes'] ?? null,
            $summary?->search_attributes,
            $this->attributeValue($summary, 'search_attributes'),
            $summary?->getRawOriginal('search_attributes'),
            $this->resource->search_attributes,
            $this->attributeValue($this->resource, 'search_attributes'),
            $this->resource->getRawOriginal('search_attributes'),
            $this->databaseAttributeValue($this->resource, 'search_attributes'),
        );

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

    private function attributeValue(mixed $model, string $attribute): mixed
    {
        if (! is_object($model) || ! method_exists($model, 'getAttributes')) {
            return null;
        }

        $attributes = $model->getAttributes();

        return $attributes[$attribute] ?? null;
    }

    private function databaseAttributeValue(mixed $model, string $attribute): mixed
    {
        if (! $model instanceof Model || ! $model->exists) {
            return null;
        }

        return DB::connection($model->getConnectionName())
            ->table($model->getTable())
            ->where($model->getKeyName(), $model->getKey())
            ->value($attribute);
    }
}
