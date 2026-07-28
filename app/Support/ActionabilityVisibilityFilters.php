<?php

declare(strict_types=1);

namespace Waterline\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use LogicException;

final class ActionabilityVisibilityFilters
{
    public const VERSION = VisibilityFilterContract::VERSION;

    private const REPAIR_STATE_FIELD = 'repair_state';

    /**
     * @return list<int>
     */
    public static function supportedVersions(): array
    {
        return VisibilityFilterContract::supportedVersions();
    }

    /**
     * @return array<string, mixed>
     */
    public static function versionMetadata(mixed $version): array
    {
        return VisibilityFilterContract::versionMetadata($version);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public static function normalize(array $filters): array
    {
        $normalized = VisibilityFilterContract::normalize($filters);
        $repairState = self::repairState($filters[self::REPAIR_STATE_FIELD] ?? null);

        if ($repairState !== null) {
            $normalized[self::REPAIR_STATE_FIELD] = $repairState;
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromRequest(Request $request): array
    {
        return self::normalize([
            ...VisibilityFilterContract::fromRequest($request),
            self::REPAIR_STATE_FIELD => $request->query(self::REPAIR_STATE_FIELD),
        ]);
    }

    /**
     * @param array<string, mixed> ...$filters
     * @return array<string, mixed>
     */
    public static function merge(array ...$filters): array
    {
        $merged = [];

        foreach ($filters as $filter) {
            $normalized = self::normalize($filter);
            $repairState = $normalized[self::REPAIR_STATE_FIELD]
                ?? $merged[self::REPAIR_STATE_FIELD]
                ?? null;
            $merged = VisibilityFilterContract::merge($merged, $normalized);

            if ($repairState !== null) {
                $merged[self::REPAIR_STATE_FIELD] = $repairState;
            }
        }

        return self::normalize($merged);
    }

    /**
     * @param Builder<\Illuminate\Database\Eloquent\Model> $query
     * @param array<string, mixed> $filters
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public static function apply(Builder $query, array $filters): Builder
    {
        $normalized = self::normalize($filters);
        $embeddedFilters = \Workflow\V2\Support\VisibilityFilters::class;

        if (! class_exists($embeddedFilters)) {
            throw new LogicException('The embedded Waterline backend requires durable-workflow/workflow visibility filters.');
        }

        $embeddedFilters::apply($query, $normalized);

        return self::applyRepairState($query, $normalized[self::REPAIR_STATE_FIELD] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public static function definition(): array
    {
        $definition = VisibilityFilterContract::definition();
        $fields = is_array($definition['fields'] ?? null) ? $definition['fields'] : [];
        $maxOrder = -1;

        foreach ($fields as $field) {
            if (is_array($field) && is_int($field['order'] ?? null)) {
                $maxOrder = max($maxOrder, $field['order']);
            }
        }

        $fields[self::REPAIR_STATE_FIELD] = [
            'label' => 'Repair State',
            'type' => 'string',
            'input' => 'select',
            'operator' => 'exact',
            'filterable' => true,
            'saved_view_compatible' => true,
            'order' => $maxOrder + 1,
            'query_parameter' => self::REPAIR_STATE_FIELD,
            'derived_from' => ActionabilityContract::SCHEMA,
            'help' => 'Run-level repair taxonomy from waterline.actionability. It distinguishes repairable, blocked, not-needed, and unknown rows without treating diagnostic-only evidence as a resume source.',
            'options' => array_map(
                static fn (string $state): array => [
                    'label' => self::repairStateLabel($state),
                    'value' => $state,
                    'description' => self::repairStateDescription($state),
                    'derived_from' => ActionabilityContract::SCHEMA,
                ],
                self::repairStates(),
            ),
        ];

        foreach (['repair_attention', 'repair_blocked_reason', 'task_problem'] as $legacyField) {
            if (is_array($fields[$legacyField] ?? null)) {
                $fields[$legacyField]['derived_from'] = ActionabilityContract::SCHEMA;
            }
        }

        $definition['fields'] = $fields;
        $definition['actionability'] = [
            'schema' => ActionabilityContract::SCHEMA,
            'version' => ActionabilityContract::VERSION,
            'filter_fields' => [self::REPAIR_STATE_FIELD],
            'legacy_projection_fields' => [
                'repair_attention',
                'repair_blocked_reason',
                'task_problem',
            ],
        ];

        return $definition;
    }

    /**
     * @param Builder<\Illuminate\Database\Eloquent\Model> $query
     */
    private static function applyRepairState(Builder $query, mixed $repairState): Builder
    {
        return match (self::repairState($repairState)) {
            'repairable' => $query
                ->where('status_bucket', 'running')
                ->where('repair_attention', true)
                ->whereNull('repair_blocked_reason'),
            'blocked' => $query
                ->whereNotNull('repair_blocked_reason')
                ->where('repair_blocked_reason', '!=', 'repair_not_needed'),
            'not_needed' => $query
                ->where(static function (Builder $stateQuery): void {
                    $stateQuery->whereNull('status_bucket')
                        ->orWhere('status_bucket', '!=', 'running')
                        ->orWhere('repair_blocked_reason', 'repair_not_needed');
                })
                ->where(static function (Builder $stateQuery): void {
                    $stateQuery->whereNull('repair_blocked_reason')
                        ->orWhere('repair_blocked_reason', 'repair_not_needed');
                }),
            'unknown' => $query
                ->where('status_bucket', 'running')
                ->where('repair_attention', false)
                ->whereNull('repair_blocked_reason'),
            default => $query,
        };
    }

    private static function repairState(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return in_array($value, self::repairStates(), true) ? $value : null;
    }

    /**
     * @return list<string>
     */
    private static function repairStates(): array
    {
        $definition = ActionabilityContract::definition();
        $states = $definition['repair_states'] ?? [];

        return array_values(array_filter($states, static fn (mixed $state): bool => is_string($state)));
    }

    private static function repairStateLabel(string $state): string
    {
        return match ($state) {
            'repairable' => 'Repairable',
            'blocked' => 'Blocked',
            'not_needed' => 'Not Needed',
            'unknown' => 'Unknown',
            default => $state,
        };
    }

    private static function repairStateDescription(string $state): string
    {
        return match ($state) {
            'repairable' => 'A running row whose durable projection says repair can be attempted.',
            'blocked' => 'A row with an explicit actionability blocked reason.',
            'not_needed' => 'A row where repair is not applicable, usually because the run is not active.',
            'unknown' => 'A running row without enough durable projection evidence to offer repair.',
            default => 'Run-level actionability state.',
        };
    }
}
