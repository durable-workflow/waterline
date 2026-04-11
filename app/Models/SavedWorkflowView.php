<?php

declare(strict_types=1);

namespace Waterline\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Workflow\V2\Support\VisibilityFilters;

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
            $view->filter_version ??= VisibilityFilters::VERSION;
            $view->filters = VisibilityFilters::normalize($view->filters ?? []);
        });

        static::saving(static function (self $view): void {
            $view->filters = VisibilityFilters::normalize($view->filters ?? []);
        });
    }

    /**
     * @return Builder<self>
     */
    public static function currentScopeQuery(): Builder
    {
        return static::query()->where('scope', static::configuredScope());
    }

    public static function configuredScope(): string
    {
        $scope = config('waterline.saved_views.scope', 'default');
        $scope = is_string($scope) ? trim($scope) : 'default';

        return $scope === '' ? 'default' : $scope;
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
    public function toWaterlinePayload(): array
    {
        $versionMetadata = VisibilityFilters::versionMetadata($this->getRawOriginal('filter_version') ?? $this->filter_version);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'bucket' => $this->bucket,
            'scope' => $this->scope,
            'shared' => (bool) $this->shared,
            'system' => false,
            'filters' => VisibilityFilters::normalize($this->filters ?? []),
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
        $versionMetadata = VisibilityFilters::versionMetadata(VisibilityFilters::VERSION);

        return [
            'id' => $view['id'],
            'name' => $view['name'],
            'bucket' => $view['bucket'],
            'scope' => 'system',
            'shared' => true,
            'system' => true,
            'filters' => $view['filters'],
            'filter_version' => VisibilityFilters::VERSION,
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
