<?php

declare(strict_types=1);

namespace Waterline\Repositories\Workflow\Infrastructure;

use Illuminate\Http\Request;
use Waterline\Models\SavedWorkflowView;
use Workflow\V2\Support\VisibilityFilters;

final class V2VisibilityFilterContext
{
    /**
     * @return array<string, mixed>
     */
    public static function resolve(Request $request, ?string $bucket = null): array
    {
        $savedViewId = $request->query('view');
        $savedViewId = is_string($savedViewId) && $savedViewId !== '' ? $savedViewId : null;
        $savedView = self::savedView($savedViewId);

        if ($savedViewId !== null) {
            abort_if($savedView === null, 404, 'Waterline saved view not found.');
            abort_if(
                $bucket !== null
                && is_string($savedView['bucket'] ?? null)
                && $savedView['bucket'] !== $bucket,
                422,
                'Waterline saved view bucket does not match the current list.',
            );
        }

        $savedViewApplied = $savedView === null
            ? null
            : (($savedView['filter_version_supported'] ?? true) === true);
        $savedFilters = $savedViewApplied && is_array($savedView['filters'] ?? null) ? $savedView['filters'] : [];
        $requestFilters = VisibilityFilters::fromRequest($request);
        $namespaceFilters = self::namespaceFilters();

        return [
            'saved_view' => $savedView,
            'saved_view_applied' => $savedViewApplied,
            'saved_view_warning' => $savedViewApplied ? null : ($savedView['filter_version_message'] ?? null),
            'saved_filters' => $savedFilters,
            'request_filters' => $requestFilters,
            'applied_filters' => VisibilityFilters::merge($namespaceFilters, $savedFilters, $requestFilters),
            'definition' => VisibilityFilters::definition(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function namespaceFilters(): array
    {
        $namespace = config('waterline.namespace');

        if (! is_string($namespace) || trim($namespace) === '') {
            return [];
        }

        return ['namespace' => trim($namespace)];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function savedView(?string $id): ?array
    {
        if ($id === null) {
            return null;
        }

        if (! config('waterline.saved_views.enabled', true)) {
            return null;
        }

        $system = SavedWorkflowView::systemView($id);

        if ($system !== null) {
            return $system;
        }

        $model = config('waterline.saved_views.model', SavedWorkflowView::class);
        $model = is_string($model) && is_a($model, SavedWorkflowView::class, true)
            ? $model
            : SavedWorkflowView::class;

        /** @var SavedWorkflowView|null $view */
        $view = $model::currentScopeQuery()->find($id);

        return $view?->toWaterlinePayload();
    }
}
