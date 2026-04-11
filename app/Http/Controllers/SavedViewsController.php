<?php

declare(strict_types=1);

namespace Waterline\Http\Controllers;

use Illuminate\Http\Request;
use Waterline\Models\SavedWorkflowView;
use Workflow\V2\Support\VisibilityFilters;

class SavedViewsController extends Controller
{
    public function index(Request $request)
    {
        if (! $this->available()) {
            return response()->json([
                'data' => [],
                'filter_version' => VisibilityFilters::VERSION,
                'supported_filter_versions' => VisibilityFilters::supportedVersions(),
                'filter_definition' => VisibilityFilters::definition(),
            ]);
        }

        $bucket = $this->bucket($request->query('bucket'), required: false);
        $model = $this->model();

        $saved = $model::currentScopeQuery()
            ->when($bucket !== null, static fn ($query) => $query->where('bucket', $bucket))
            ->orderBy('name')
            ->get()
            ->map(static fn (SavedWorkflowView $view): array => $view->toWaterlinePayload())
            ->all();

        return response()->json([
            'data' => [
                ...SavedWorkflowView::systemViews($bucket),
                ...$saved,
            ],
            'filter_version' => VisibilityFilters::VERSION,
            'supported_filter_versions' => VisibilityFilters::supportedVersions(),
            'filter_definition' => VisibilityFilters::definition(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($this->available(), 404);

        $payload = $this->payload($request);
        $model = $this->model();

        abort_if(
            $model::currentScopeQuery()
                ->where('bucket', $payload['bucket'])
                ->where('name', $payload['name'])
                ->exists(),
            409,
            'A saved view with this name already exists for the selected bucket.',
        );

        /** @var SavedWorkflowView $view */
        $view = $model::query()->create([
            'name' => $payload['name'],
            'scope' => $model::configuredScope(),
            'bucket' => $payload['bucket'],
            'filters' => $payload['filters'],
            'filter_version' => VisibilityFilters::VERSION,
            'shared' => $payload['shared'],
        ]);

        return response()->json($view->toWaterlinePayload(), 201);
    }

    public function show(string $view)
    {
        abort_unless($this->available(), 404);

        return response()->json($this->findViewPayload($view));
    }

    public function update(string $view, Request $request)
    {
        abort_unless($this->available(), 404);

        /** @var SavedWorkflowView $savedView */
        $savedView = $this->findCustomView($view);
        $payload = $this->payload($request);

        $model = $this->model();

        abort_if(
            $model::currentScopeQuery()
                ->where('bucket', $payload['bucket'])
                ->where('name', $payload['name'])
                ->where($savedView->getKeyName(), '!=', $savedView->getKey())
                ->exists(),
            409,
            'A saved view with this name already exists for the selected bucket.',
        );

        $savedView->update([
            'name' => $payload['name'],
            'bucket' => $payload['bucket'],
            'filters' => $payload['filters'],
            'filter_version' => VisibilityFilters::VERSION,
            'shared' => $payload['shared'],
        ]);

        return response()->json($savedView->fresh()->toWaterlinePayload());
    }

    public function destroy(string $view)
    {
        abort_unless($this->available(), 404);

        $this->findCustomView($view)->delete();

        return response()->noContent();
    }

    /**
     * @return class-string<SavedWorkflowView>
     */
    private function model(): string
    {
        $model = config('waterline.saved_views.model', SavedWorkflowView::class);

        return is_string($model) && is_a($model, SavedWorkflowView::class, true)
            ? $model
            : SavedWorkflowView::class;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'bucket' => ['required', 'string', 'in:'.implode(',', SavedWorkflowView::BUCKETS)],
            'filters' => ['nullable', 'array'],
            'shared' => ['sometimes', 'boolean'],
        ]);

        $name = trim($validated['name']);

        abort_if($name === '', 422, 'A Waterline saved view name is required.');

        return [
            'name' => $name,
            'bucket' => $this->bucket($validated['bucket'], required: true),
            'filters' => VisibilityFilters::normalize($validated['filters'] ?? []),
            'shared' => (bool) ($validated['shared'] ?? false),
        ];
    }

    private function bucket(mixed $bucket, bool $required): ?string
    {
        if (! is_string($bucket) || $bucket === '') {
            abort_if($required, 422, 'A Waterline saved view bucket is required.');

            return null;
        }

        abort_unless(in_array($bucket, SavedWorkflowView::BUCKETS, true), 422, 'Unknown Waterline saved view bucket.');

        return $bucket;
    }

    /**
     * @return array<string, mixed>
     */
    private function findViewPayload(string $id): array
    {
        $system = SavedWorkflowView::systemView($id);

        if ($system !== null) {
            return $system;
        }

        return $this->findCustomView($id)->toWaterlinePayload();
    }

    private function findCustomView(string $id): SavedWorkflowView
    {
        $model = $this->model();

        return $model::currentScopeQuery()->findOrFail($id);
    }

    private function available(): bool
    {
        return config('waterline.engine_source') === 'v2'
            && config('waterline.saved_views.enabled', true);
    }
}
