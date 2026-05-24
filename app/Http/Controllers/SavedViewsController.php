<?php

declare(strict_types=1);

namespace Waterline\Http\Controllers;

use Illuminate\Http\Request;
use Waterline\Models\SavedWorkflowView;
use Waterline\Support\ActionabilityVisibilityFilters;
use Waterline\Support\EngineSourceReadiness;
use Waterline\Support\OperatorScope;
use Waterline\Support\WorkflowEngineSourceResolver;

class SavedViewsController extends Controller
{
    public function index(Request $request)
    {
        $engineSource = WorkflowEngineSourceResolver::status();

        if (EngineSourceReadiness::pinnedV2Unavailable($engineSource)) {
            return EngineSourceReadiness::unavailableResponse($engineSource);
        }

        if (! $this->available($engineSource)) {
            return response()->json([
                'data' => [],
                'filter_version' => ActionabilityVisibilityFilters::VERSION,
                'supported_filter_versions' => ActionabilityVisibilityFilters::supportedVersions(),
                'filter_definition' => ActionabilityVisibilityFilters::definition(),
                'saved_view_policy' => $this->savedViewPolicy(),
                'operator_scope' => OperatorScope::payload(),
            ]);
        }

        $bucket = $this->bucket($request->query('bucket'), required: false);
        $model = $this->model();

        $saved = $model::visibleTo($request)
            ->when($bucket !== null, static fn ($query) => $query->where('bucket', $bucket))
            ->orderBy('name')
            ->get()
            ->map(static fn (SavedWorkflowView $view): array => $view->toWaterlinePayload($request))
            ->all();

        return response()->json([
            'data' => [
                ...SavedWorkflowView::systemViews($bucket),
                ...$saved,
            ],
            'filter_version' => ActionabilityVisibilityFilters::VERSION,
            'supported_filter_versions' => ActionabilityVisibilityFilters::supportedVersions(),
            'filter_definition' => ActionabilityVisibilityFilters::definition(),
            'saved_view_policy' => $this->savedViewPolicy(),
            'operator_scope' => OperatorScope::payload(),
        ]);
    }

    public function store(Request $request)
    {
        EngineSourceReadiness::throwIfPinnedV2Unavailable();
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
        $owner = $model::ownerIdentity($request);
        $view = $model::query()->create([
            'name' => $payload['name'],
            'scope' => $model::configuredScope(),
            'bucket' => $payload['bucket'],
            'filters' => $payload['filters'],
            'filter_version' => ActionabilityVisibilityFilters::VERSION,
            'shared' => $payload['shared'],
            'owner_type' => $owner['type'],
            'owner_id' => $owner['id'],
        ]);

        return response()->json($this->withOperatorScope($view->toWaterlinePayload($request)), 201);
    }

    public function show(string $view, Request $request)
    {
        EngineSourceReadiness::throwIfPinnedV2Unavailable();
        abort_unless($this->available(), 404);

        return response()->json($this->withOperatorScope($this->findViewPayload($view, $request)));
    }

    public function update(string $view, Request $request)
    {
        EngineSourceReadiness::throwIfPinnedV2Unavailable();
        abort_unless($this->available(), 404);

        /** @var SavedWorkflowView $savedView */
        $savedView = $this->findMutableCustomView($view, $request);
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
            'filter_version' => ActionabilityVisibilityFilters::VERSION,
            'shared' => $payload['shared'],
        ]);

        return response()->json($this->withOperatorScope($savedView->fresh()->toWaterlinePayload($request)));
    }

    public function destroy(string $view, Request $request)
    {
        EngineSourceReadiness::throwIfPinnedV2Unavailable();
        abort_unless($this->available(), 404);

        $this->findMutableCustomView($view, $request)->delete();

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
            'filters' => ActionabilityVisibilityFilters::normalize($validated['filters'] ?? []),
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
    private function findViewPayload(string $id, Request $request): array
    {
        $system = SavedWorkflowView::systemView($id);

        if ($system !== null) {
            return $system;
        }

        return $this->findVisibleCustomView($id, $request)->toWaterlinePayload($request);
    }

    private function findVisibleCustomView(string $id, Request $request): SavedWorkflowView
    {
        $model = $this->model();

        return $model::visibleTo($request)->findOrFail($id);
    }

    private function findMutableCustomView(string $id, Request $request): SavedWorkflowView
    {
        $model = $this->model();

        return $model::mutableBy($request)->findOrFail($id);
    }

    /**
     * @param array<string, mixed>|null $engineSource
     */
    private function available(?array $engineSource = null): bool
    {
        $engineSource ??= WorkflowEngineSourceResolver::status();

        return ($engineSource['uses_v2'] ?? false) === true
            && config('waterline.saved_views.enabled', true);
    }

    /**
     * @return array<string, mixed>
     */
    private function savedViewPolicy(): array
    {
        return [
            'visibility' => [
                'private' => 'Readable only by the owner within the configured Waterline saved-view scope.',
                'shared' => 'Readable by any Waterline operator within the configured saved-view scope.',
            ],
            'operator_scope' => OperatorScope::payload(),
            'mutation' => 'Only the saved-view owner can update or delete a custom view, including shared views.',
            'name_uniqueness' => 'Custom saved-view names are unique per configured scope and bucket.',
            'reserved_id_prefix' => 'system:',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withOperatorScope(array $payload): array
    {
        $payload['operator_scope'] = OperatorScope::payload();

        return $payload;
    }
}
