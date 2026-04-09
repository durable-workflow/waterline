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
        return collect(self::BUCKETS)
            ->when($bucket !== null, static fn ($buckets) => $buckets->filter(static fn (string $candidate): bool => $candidate === $bucket))
            ->map(static fn (string $candidate): array => [
                'id' => 'system:'.$candidate,
                'name' => ucfirst($candidate),
                'bucket' => $candidate,
                'scope' => 'system',
                'shared' => true,
                'system' => true,
                'filters' => [],
                'filter_version' => VisibilityFilters::VERSION,
                'created_at' => null,
                'updated_at' => null,
            ])
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
        return [
            'id' => $this->id,
            'name' => $this->name,
            'bucket' => $this->bucket,
            'scope' => $this->scope,
            'shared' => (bool) $this->shared,
            'system' => false,
            'filters' => VisibilityFilters::normalize($this->filters ?? []),
            'filter_version' => (int) ($this->filter_version ?? VisibilityFilters::VERSION),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
