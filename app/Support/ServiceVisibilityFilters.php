<?php

declare(strict_types=1);

namespace Waterline\Support;

use Illuminate\Validation\ValidationException;

/**
 * Translates Waterline visibility filters to the standalone service contract.
 *
 * Service lists must stay authoritative and paginated by the remote backend, so
 * fields that cannot be expressed by the SDK workflow-list contract are exposed
 * as unavailable instead of being post-filtered or silently ignored.
 */
final class ServiceVisibilityFilters
{
    private const CAPABILITY_VERSION = 1;

    private const FIELD_QUERY_NAMES = [
        'instance_id' => 'WorkflowId',
        'run_id' => 'RunId',
        'compatibility' => 'BuildId',
        'queue' => 'TaskQueue',
        'status' => 'Status',
        'status_bucket' => 'Status',
    ];

    private const DIRECT_FIELDS = [
        'workflow_type',
    ];

    private const SCOPE_FIELDS = [
        'namespace',
    ];

    /**
     * @return list<string>
     */
    public static function supportedFields(): array
    {
        return [
            ...self::DIRECT_FIELDS,
            ...self::SCOPE_FIELDS,
            ...array_keys(self::FIELD_QUERY_NAMES),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function definition(): array
    {
        $definition = ActionabilityVisibilityFilters::definition();
        $fields = is_array($definition['fields'] ?? null) ? $definition['fields'] : [];
        $supported = self::supportedFields();
        $unavailable = [];

        foreach ($fields as $name => $field) {
            if (! is_string($name) || ! is_array($field)) {
                continue;
            }

            $available = in_array($name, $supported, true);
            $fields[$name] = self::withAvailability(
                $field,
                $available,
                $available
                    ? (in_array($name, self::SCOPE_FIELDS, true)
                        ? 'sdk_namespace_scope'
                        : ($name === 'workflow_type' ? 'workflow_type' : 'visibility_query'))
                    : null,
            );

            if (! $available) {
                $unavailable[] = $name;
            }
        }

        $definition['fields'] = $fields;
        $definition['labels'] = self::withAvailability(
            is_array($definition['labels'] ?? null) ? $definition['labels'] : [],
            false,
        );
        $definition['search_attributes'] = self::withAvailability(
            is_array($definition['search_attributes'] ?? null) ? $definition['search_attributes'] : [],
            false,
        );

        $indexedMetadata = is_array($definition['indexed_metadata'] ?? null)
            ? $definition['indexed_metadata']
            : [];
        foreach ($indexedMetadata as $name => $metadata) {
            if (! is_string($name) || ! is_array($metadata)) {
                continue;
            }

            $indexedMetadata[$name] = self::withAvailability(
                $metadata,
                false,
            );
        }
        $definition['indexed_metadata'] = $indexedMetadata;

        if (is_array($definition['actionability'] ?? null)) {
            $definition['actionability']['unavailable_filter_fields'] = array_values(array_filter(
                $definition['actionability']['filter_fields'] ?? [],
                'is_string',
            ));
            $definition['actionability']['filter_fields'] = [];
            $definition['actionability']['service_mode_available'] = false;
        }

        $definition['service_mode'] = [
            'capability_version' => self::CAPABILITY_VERSION,
            'backend_contract' => 'durable-workflow/sdk.workflow-list',
            'authoritative_filtering' => true,
            'supported_fields' => self::supportedFields(),
            'unavailable_fields' => array_values($unavailable),
            'unavailable_maps' => ['labels', 'search_attributes'],
        ];

        return $definition;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{
     *     applied_filters: array<string, mixed>,
     *     unavailable_filters: array<string, mixed>,
     *     workflow_type: string|null,
     *     query: string|null,
     *     warning: string|null,
     *     capability: array<string, mixed>
     * }
     */
    public static function plan(array $filters, string $bucket, ?string $genericQuery = null): array
    {
        $normalized = ActionabilityVisibilityFilters::normalize($filters);
        $applied = [];
        $unavailable = [];
        $predicates = [];

        foreach ($normalized as $field => $value) {
            if (in_array($field, self::DIRECT_FIELDS, true)) {
                $applied[$field] = $value;

                continue;
            }

            if (in_array($field, self::SCOPE_FIELDS, true)) {
                if ($value === BackendConfiguration::namespace()) {
                    $applied[$field] = $value;
                } else {
                    $unavailable[$field] = $value;
                }

                continue;
            }

            if (isset(self::FIELD_QUERY_NAMES[$field])) {
                $applied[$field] = $value;

                if ($field !== 'status_bucket' || $value !== $bucket) {
                    $predicates[] = self::FIELD_QUERY_NAMES[$field].' = '.self::literal((string) $value);
                }

                continue;
            }

            $unavailable[$field] = $value;
        }

        if (isset($unavailable['search_attributes']) && is_array($unavailable['search_attributes'])) {
            ksort($unavailable['search_attributes']);
        }

        $genericQuery = is_string($genericQuery) && trim($genericQuery) !== ''
            ? trim($genericQuery)
            : null;
        $query = $predicates === [] ? $genericQuery : implode(' AND ', $predicates);

        if ($genericQuery !== null && $predicates !== []) {
            $unavailable['query'] = $genericQuery;
        } elseif ($genericQuery !== null) {
            $applied['query'] = $genericQuery;
        }

        $warning = self::warning($unavailable);

        return [
            'applied_filters' => $applied,
            'unavailable_filters' => $unavailable,
            'workflow_type' => is_string($applied['workflow_type'] ?? null)
                ? $applied['workflow_type']
                : null,
            'query' => $query,
            'warning' => $warning,
            'capability' => [
                'version' => self::CAPABILITY_VERSION,
                'backend_contract' => 'durable-workflow/sdk.workflow-list',
                'authoritative_filtering' => true,
                'fully_applied' => $unavailable === [],
                'supported_fields' => self::supportedFields(),
                'unavailable_fields' => self::filterNames($unavailable),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $view
     * @return array<string, mixed>
     */
    public static function annotateSavedView(array $view): array
    {
        $filters = is_array($view['filters'] ?? null) ? $view['filters'] : [];
        $bucket = is_string($view['bucket'] ?? null) ? $view['bucket'] : '';
        $plan = self::plan($filters, $bucket);
        $versionSupported = ($view['filter_version_supported'] ?? true) === true;
        $available = $versionSupported && $plan['unavailable_filters'] === [];
        $warning = ! $versionSupported
            ? ($view['filter_version_message'] ?? 'This saved view uses an unsupported visibility filter version.')
            : $plan['warning'];

        $view['service_mode_available'] = $available;
        $view['service_mode_warning'] = $warning;
        $view['service_mode'] = [
            'available' => $available,
            'applied_filters' => $plan['applied_filters'],
            'unavailable_filters' => $plan['unavailable_filters'],
            'warning' => $warning,
            'capability' => $plan['capability'],
        ];

        return $view;
    }

    /**
     * @param array<string, mixed> $filters
     */
    public static function assertSavable(array $filters, string $bucket): void
    {
        $plan = self::plan($filters, $bucket);

        if ($plan['unavailable_filters'] === []) {
            return;
        }

        throw ValidationException::withMessages([
            'filters' => [$plan['warning']],
        ]);
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private static function withAvailability(
        array $definition,
        bool $available,
        ?string $contract = null,
    ): array {
        $definition['filterable'] = $available;
        $definition['saved_view_compatible'] = $available;
        $definition['service_mode_available'] = $available;
        $definition['service_mode_contract'] = $contract;
        $definition['service_mode_unavailable_reason'] = $available
            ? null
            : 'The standalone workflow-list contract cannot apply this filter authoritatively.';

        return $definition;
    }

    private static function literal(string $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * @param array<string, mixed> $unavailable
     */
    private static function warning(array $unavailable): ?string
    {
        $fields = self::filterNames($unavailable);

        if ($fields === []) {
            return null;
        }

        return sprintf(
            'The connected service cannot apply these workflow-list filters: %s.',
            implode(', ', $fields),
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<string>
     */
    private static function filterNames(array $filters): array
    {
        $names = [];

        foreach ($filters as $field => $value) {
            if (is_array($value)) {
                foreach (array_keys($value) as $key) {
                    $names[] = $field.'.'.$key;
                }

                continue;
            }

            $names[] = (string) $field;
        }

        sort($names);

        return $names;
    }
}
