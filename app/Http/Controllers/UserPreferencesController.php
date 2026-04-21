<?php

declare(strict_types=1);

namespace Waterline\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Waterline\Models\UserPreference;

class UserPreferencesController extends Controller
{
    public function show(string $surface, Request $request)
    {
        $surface = $this->surface($surface);
        $preference = $this->preference($surface, $request);
        $model = $this->model();
        $stored = $model::normalizePreferences($preference->preferences ?? []);
        $overrides = $this->overrides($request);

        return response()->json($preference->toWaterlinePayload(
            effectivePreferences: array_replace($stored, $overrides),
            overrides: $overrides,
        ));
    }

    public function update(string $surface, Request $request)
    {
        $surface = $this->surface($surface);
        $model = $this->model();
        $payload = $this->payload($request);

        $preference = $model::currentSubjectQuery($request)->updateOrCreate(
            ['surface' => $surface],
            [
                'scope' => $model::configuredScope(),
                'subject_key' => $model::subjectKey($request),
                'preferences' => $payload,
            ],
        );

        $stored = $model::normalizePreferences($preference->preferences ?? []);
        $overrides = $this->overrides($request);

        return response()->json($preference->fresh()->toWaterlinePayload(
            effectivePreferences: array_replace($stored, $overrides),
            overrides: $overrides,
        ));
    }

    private function surface(string $surface): string
    {
        abort_unless(in_array($surface, UserPreference::SURFACES, true), 404);

        return $surface;
    }

    /**
     * @return class-string<UserPreference>
     */
    private function model(): string
    {
        $model = config('waterline.preferences.model', UserPreference::class);

        return is_string($model) && is_a($model, UserPreference::class, true)
            ? $model
            : UserPreference::class;
    }

    private function preference(string $surface, Request $request): UserPreference
    {
        $model = $this->model();

        /** @var UserPreference|null $preference */
        $preference = $model::currentSubjectQuery($request)
            ->where('surface', $surface)
            ->first();

        if ($preference instanceof UserPreference) {
            return $preference;
        }

        return new $model([
            'surface' => $surface,
            'scope' => $model::configuredScope(),
            'subject_key' => $model::subjectKey($request),
            'preferences' => [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $validated = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.tab' => ['sometimes', 'string', 'max:80'],
            'preferences.sort_direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
            'preferences.row_density' => ['sometimes', 'string', Rule::in(['comfortable', 'dense'])],
            'preferences.saved_view_id' => ['sometimes', 'nullable', 'string', 'max:120'],
            'preferences.columns' => ['sometimes', 'array', 'max:32'],
            'preferences.columns.*' => ['string', 'max:80'],
        ]);

        $model = $this->model();

        return $model::normalizePreferences($validated['preferences']);
    }

    /**
     * @return array<string, mixed>
     */
    private function overrides(Request $request): array
    {
        $aliases = [
            'tab' => 'tab',
            'sort' => 'sort_direction',
            'sort_direction' => 'sort_direction',
            'density' => 'row_density',
            'row_density' => 'row_density',
            'view' => 'saved_view_id',
            'saved_view' => 'saved_view_id',
            'saved_view_id' => 'saved_view_id',
            'columns' => 'columns',
        ];

        $raw = [];
        foreach ($aliases as $queryKey => $preferenceKey) {
            if ($request->query->has($queryKey)) {
                $raw[$preferenceKey] = $request->query($queryKey);
            }
        }

        if (isset($raw['columns']) && is_string($raw['columns'])) {
            $raw['columns'] = array_map('trim', explode(',', $raw['columns']));
        }

        $model = $this->model();

        return $model::normalizePreferences($raw);
    }
}
