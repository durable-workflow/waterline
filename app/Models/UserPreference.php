<?php

declare(strict_types=1);

namespace Waterline\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UserPreference extends Model
{
    public const SURFACES = [
        'workflow-list',
        'run-detail',
        'schedules-list',
        'workers-list',
    ];

    public const KEYS = [
        'tab',
        'sort_direction',
        'row_density',
        'saved_view_id',
        'columns',
    ];

    public $incrementing = false;

    protected $table = 'waterline_user_preferences';

    protected $guarded = [];

    protected $keyType = 'string';

    protected $casts = [
        'preferences' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $preference): void {
            if (! is_string($preference->id) || $preference->id === '') {
                $preference->id = (string) Str::ulid();
            }

            $preference->scope ??= static::configuredScope();
        });

        static::saving(static function (self $preference): void {
            $preference->preferences = static::normalizePreferences($preference->preferences ?? []);
        });
    }

    /**
     * @return Builder<self>
     */
    public static function currentSubjectQuery(Request $request): Builder
    {
        return static::query()
            ->where('scope', static::configuredScope())
            ->where('subject_key', static::subjectKey($request));
    }

    public static function configuredScope(): string
    {
        $scope = config('waterline.preferences.scope', config('waterline.saved_views.scope', 'default'));
        $scope = is_string($scope) ? trim($scope) : 'default';

        return $scope === '' ? 'default' : $scope;
    }

    public static function subjectKey(Request $request): string
    {
        $user = $request->user();

        if (is_object($user) && method_exists($user, 'getAuthIdentifier')) {
            $identifier = $user->getAuthIdentifier();

            if (is_scalar($identifier) && trim((string) $identifier) !== '') {
                return get_class($user).':'.trim((string) $identifier);
            }
        }

        return 'scope:'.static::configuredScope();
    }

    /**
     * @param array<string, mixed> $preferences
     * @return array<string, mixed>
     */
    public static function normalizePreferences(array $preferences): array
    {
        $normalized = [];

        if (isset($preferences['tab']) && is_string($preferences['tab'])) {
            $tab = trim($preferences['tab']);
            if ($tab !== '') {
                $normalized['tab'] = Str::limit($tab, 80, '');
            }
        }

        if (isset($preferences['sort_direction']) && is_string($preferences['sort_direction'])) {
            $sort = strtolower(trim($preferences['sort_direction']));
            if (in_array($sort, ['asc', 'desc'], true)) {
                $normalized['sort_direction'] = $sort;
            }
        }

        if (isset($preferences['row_density']) && is_string($preferences['row_density'])) {
            $density = strtolower(trim($preferences['row_density']));
            if (in_array($density, ['comfortable', 'dense'], true)) {
                $normalized['row_density'] = $density;
            }
        }

        if (array_key_exists('saved_view_id', $preferences)) {
            $savedView = $preferences['saved_view_id'];
            if ($savedView === null) {
                $normalized['saved_view_id'] = null;
            } elseif (is_string($savedView) && trim($savedView) !== '') {
                $normalized['saved_view_id'] = Str::limit(trim($savedView), 120, '');
            }
        }

        if (isset($preferences['columns']) && is_array($preferences['columns'])) {
            $columns = [];
            foreach ($preferences['columns'] as $column) {
                if (! is_string($column)) {
                    continue;
                }

                $column = trim($column);
                if ($column === '' || count($columns) >= 32) {
                    continue;
                }

                $columns[] = Str::limit($column, 80, '');
            }

            $normalized['columns'] = array_values(array_unique($columns));
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    public function toWaterlinePayload(array $effectivePreferences = [], array $overrides = []): array
    {
        return [
            'surface' => $this->surface,
            'scope' => $this->scope,
            'preferences' => static::normalizePreferences($this->preferences ?? []),
            'effective_preferences' => $effectivePreferences,
            'overrides' => $overrides,
            'updated_at' => $this->updated_at,
        ];
    }
}
