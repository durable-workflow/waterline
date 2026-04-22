<?php

declare(strict_types=1);

namespace Waterline\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Waterline\Support\ActionabilityVisibilityFilters;

class SavedWorkflowView extends Model
{
    public const BUCKETS = [
        'running',
        'completed',
        'failed',
        'cancelled',
        'terminated',
    ];

    public $incrementing = false;

    protected $table = 'waterline_saved_views';

    protected $guarded = [];

    protected $keyType = 'string';

    protected $casts = [
        'filters' => 'array',
        'filter_version' => 'integer',
        'shared' => 'bool',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $view): void {
            if (! is_string($view->id) || $view->id === '') {
                $view->id = (string) Str::ulid();
            }

            $view->scope ??= static::configuredScope();
            $owner = static::ownerIdentity();
            $view->owner_type ??= $owner['type'];
            $view->owner_id ??= $owner['id'];
            $view->filter_version ??= ActionabilityVisibilityFilters::VERSION;
            $view->filters = ActionabilityVisibilityFilters::normalize($view->filters ?? []);
        });

        static::saving(static function (self $view): void {
            $view->filters = ActionabilityVisibilityFilters::normalize($view->filters ?? []);
        });
    }

    /**
     * @return Builder<self>
     */
    public static function currentScopeQuery(): Builder
    {
        return static::query()->where('scope', static::configuredScope());
    }

    /**
     * @return Builder<self>
     */
    public static function visibleTo(Request $request): Builder
    {
        $owner = static::ownerIdentity($request);

        return static::currentScopeQuery()
            ->where(static function (Builder $query) use ($owner): void {
                $query->where('shared', true)
                    ->orWhere(static function (Builder $query) use ($owner): void {
                        $query->where('owner_type', $owner['type'])
                            ->where('owner_id', $owner['id']);
                    });
            });
    }

    /**
     * @return Builder<self>
     */
    public static function mutableBy(Request $request): Builder
    {
        $owner = static::ownerIdentity($request);

        return static::currentScopeQuery()
            ->where('owner_type', $owner['type'])
            ->where('owner_id', $owner['id']);
    }

    public static function configuredScope(): string
    {
        $scope = config('waterline.saved_views.scope', 'default');
        $scope = is_string($scope) ? trim($scope) : 'default';

        return $scope === '' ? 'default' : $scope;
    }

    /**
     * @return array{type: string, id: string}
     */
    public static function ownerIdentity(?Request $request = null): array
    {
        $user = $request?->user();

        if (is_object($user) && method_exists($user, 'getAuthIdentifier')) {
            $identifier = $user->getAuthIdentifier();

            if (is_scalar($identifier) && trim((string) $identifier) !== '') {
                return [
                    'type' => Str::limit(get_class($user), 120, ''),
                    'id' => Str::limit(trim((string) $identifier), 120, ''),
                ];
            }
        }

        return [
            'type' => 'scope',
            'id' => Str::limit(static::configuredScope(), 120, ''),
        ];
    }

    public function isOwnedBy(Request $request): bool
    {
        $owner = static::ownerIdentity($request);

        return $this->owner_type === $owner['type']
            && $this->owner_id === $owner['id'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function systemViews(?string $bucket = null): array
    {
        return collect(self::systemViewDefinitions())
            ->when($bucket !== null, static fn ($views) => $views->filter(static fn (array $candidate): bool => $candidate['bucket'] === $bucket))
            ->map(static fn (array $view): array => self::systemViewPayload($view))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function systemView(string $id): ?array
    {
        foreach (self::systemViews() as $view) {
            if ($view['id'] === $id) {
                return $view;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toWaterlinePayload(?Request $request = null): array
    {
        $versionMetadata = ActionabilityVisibilityFilters::versionMetadata($this->getRawOriginal('filter_version') ?? $this->filter_version);
        $ownedByCurrentOperator = $request === null ? null : $this->isOwnedBy($request);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'bucket' => $this->bucket,
            'scope' => $this->scope,
            'shared' => (bool) $this->shared,
            'owner_type' => $this->owner_type,
            'owner_id' => $this->owner_id,
            'owned_by_current_operator' => $ownedByCurrentOperator,
            'mutable_by_current_operator' => $ownedByCurrentOperator,
            'system' => false,
            'filters' => ActionabilityVisibilityFilters::normalize($this->filters ?? []),
            'filter_version' => $versionMetadata['version'],
            'filter_version_supported' => $versionMetadata['supported'],
            'filter_version_status' => $versionMetadata['status'],
            'filter_version_message' => $versionMetadata['message'],
            'current_filter_version' => $versionMetadata['current_version'],
            'supported_filter_versions' => $versionMetadata['supported_versions'],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * @return list<array{
     *     id: string,
     *     name: string,
     *     bucket: string,
     *     filters: array<string, mixed>
     * }>
     */
    private static function systemViewDefinitions(): array
    {
        $definitions = [];

        foreach (self::BUCKETS as $bucket) {
            $definitions[] = [
                'id' => 'system:'.$bucket,
                'name' => ucfirst($bucket),
                'bucket' => $bucket,
                'filters' => [],
            ];
        }

        $definitions[] = [
            'id' => 'system:running-task-problems',
            'name' => 'Task Problems',
            'bucket' => 'running',
            'filters' => [
                'task_problem' => true,
            ],
        ];

        $definitions[] = [
            'id' => 'system:running-repair-blocked',
            'name' => 'Repair Blocked',
            'bucket' => 'running',
            'filters' => [
                'repair_state' => 'blocked',
            ],
        ];

        return $definitions;
    }

    /**
     * @param array{
     *     id: string,
     *     name: string,
     *     bucket: string,
     *     filters: array<string, mixed>
     * } $view
     * @return array<string, mixed>
     */
    private static function systemViewPayload(array $view): array
    {
        $versionMetadata = ActionabilityVisibilityFilters::versionMetadata(ActionabilityVisibilityFilters::VERSION);

        return [
            'id' => $view['id'],
            'name' => $view['name'],
            'bucket' => $view['bucket'],
            'scope' => 'system',
            'shared' => true,
            'owner_type' => 'system',
            'owner_id' => 'waterline',
            'owned_by_current_operator' => false,
            'mutable_by_current_operator' => false,
            'system' => true,
            'filters' => $view['filters'],
            'filter_version' => ActionabilityVisibilityFilters::VERSION,
            'filter_version_supported' => $versionMetadata['supported'],
            'filter_version_status' => $versionMetadata['status'],
            'filter_version_message' => $versionMetadata['message'],
            'current_filter_version' => $versionMetadata['current_version'],
            'supported_filter_versions' => $versionMetadata['supported_versions'],
            'created_at' => null,
            'updated_at' => null,
        ];
    }
}
