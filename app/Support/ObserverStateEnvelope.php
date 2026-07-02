<?php

namespace Waterline\Support;

use Carbon\CarbonInterface;

class ObserverStateEnvelope
{
    public const SCHEMA = 'waterline.observer-state';
    public const VERSION = 1;
    public const QUERY_STATE_LIMITATION = 'query_results_not_materialized_in_selected_run_detail';

    /**
     * @param array<string, mixed> $detail
     * @param array<string, string|null> $paths
     *
     * @return array<string, mixed>
     */
    public static function annotateRun(array $detail, array $paths = [], ?CarbonInterface $capturedAt = null): array
    {
        $detail['observer_state'] = self::fromRunDetail($detail, $paths, $capturedAt);

        return $detail;
    }

    /**
     * @param array<string, mixed> $detail
     * @param array<string, string|null> $paths
     *
     * @return array<string, mixed>
     */
    public static function fromRunDetail(array $detail, array $paths = [], ?CarbonInterface $capturedAt = null): array
    {
        $signals = self::listValue($detail['signals'] ?? null);
        $updates = self::listValue($detail['updates'] ?? null);
        $declaredQueries = self::stringList($detail['declared_queries'] ?? null);

        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'captured_at' => ($capturedAt ?? now())->toIso8601String(),
            'paths' => [
                'selected_run_detail' => self::stringOrNull($paths['selected_run_detail'] ?? null),
                'selected_run_history_export' => self::stringOrNull($paths['selected_run_history_export'] ?? null),
                'selected_run_query_template' => self::stringOrNull($paths['selected_run_query_template'] ?? null),
                'selected_run_update_template' => self::stringOrNull($paths['selected_run_update_template'] ?? null),
                'selected_run_update_lookup_template' => self::stringOrNull($paths['selected_run_update_lookup_template'] ?? null),
                'instance_query_template' => self::stringOrNull($paths['instance_query_template'] ?? null),
                'instance_update_template' => self::stringOrNull($paths['instance_update_template'] ?? null),
                'instance_update_lookup_template' => self::stringOrNull($paths['instance_update_lookup_template'] ?? null),
            ],
            'selected_run' => [
                'instance_id' => self::stringOrNull($detail['instance_id'] ?? null),
                'run_id' => self::stringOrNull($detail['run_id'] ?? $detail['selected_run_id'] ?? null),
                'status' => self::stringOrNull($detail['status'] ?? null),
                'status_bucket' => self::stringOrNull($detail['status_bucket'] ?? null),
                'is_terminal' => ($detail['is_terminal'] ?? false) === true,
                'output_available' => array_key_exists('output', $detail),
                'output' => $detail['output'] ?? null,
            ],
            'signals' => [
                'count' => count($signals),
                'accepted_count' => self::acceptedSignalCount($signals),
                'names' => self::signalNames($signals),
                'items' => self::signalItems($signals),
            ],
            'updates' => [
                'count' => count($updates),
                'state_counts' => self::updateStateCounts($updates),
                'names' => self::updateNames($updates),
                'items' => self::updateItems($updates),
                'diagnostics' => self::mapValue($detail['update_diagnostics'] ?? null),
                'update_action_path_template' => self::stringOrNull($paths['selected_run_update_template'] ?? null),
                'update_lookup_path_template' => self::stringOrNull($paths['selected_run_update_lookup_template'] ?? null),
                'history_export_path' => self::stringOrNull($paths['selected_run_history_export'] ?? null),
            ],
            'queries' => [
                'declared' => $declaredQueries,
                'targets' => self::queryTargets($detail['declared_query_targets'] ?? null, $declaredQueries),
                'live_results_materialized' => false,
                'limitation' => [
                    'type' => 'query_state_not_materialized',
                    'reason' => self::QUERY_STATE_LIMITATION,
                    'message' => 'Selected-run detail is a durable observer snapshot. Live workflow query results are available through the Waterline query action endpoint and are not stored in the detail envelope.',
                    'query_action_path_template' => self::stringOrNull($paths['selected_run_query_template'] ?? null),
                ],
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $signals
     */
    private static function acceptedSignalCount(array $signals): int
    {
        return count(array_filter($signals, static function (array $signal): bool {
            $status = self::stringOrNull($signal['status'] ?? null);
            $outcome = self::stringOrNull($signal['outcome'] ?? null);

            return in_array($status, ['received', 'applied', 'completed'], true)
                || $outcome === 'signal_received';
        }));
    }

    /**
     * @param list<array<string, mixed>> $signals
     *
     * @return list<string>
     */
    private static function signalNames(array $signals): array
    {
        $names = [];

        foreach ($signals as $signal) {
            $name = self::stringOrNull($signal['name'] ?? null);

            if ($name !== null) {
                $names[$name] = true;
            }
        }

        $names = array_keys($names);
        sort($names);

        return $names;
    }

    /**
     * @param list<array<string, mixed>> $signals
     *
     * @return list<array<string, mixed>>
     */
    private static function signalItems(array $signals): array
    {
        return array_values(array_map(static function (array $signal): array {
            return [
                'id' => self::stringOrNull($signal['id'] ?? null),
                'command_id' => self::stringOrNull($signal['command_id'] ?? null),
                'command_sequence' => self::intOrNull($signal['command_sequence'] ?? null),
                'workflow_sequence' => self::intOrNull($signal['workflow_sequence'] ?? null),
                'name' => self::stringOrNull($signal['name'] ?? null),
                'status' => self::stringOrNull($signal['status'] ?? null),
                'outcome' => self::stringOrNull($signal['outcome'] ?? null),
                'arguments_available' => ($signal['arguments_available'] ?? false) === true,
                'arguments' => $signal['arguments'] ?? null,
                'received_at' => self::stringOrNull($signal['received_at'] ?? null),
                'applied_at' => self::stringOrNull($signal['applied_at'] ?? null),
            ];
        }, $signals));
    }

    /**
     * @param list<array<string, mixed>> $updates
     *
     * @return array<string, int>
     */
    private static function updateStateCounts(array $updates): array
    {
        $counts = [
            'accepted' => 0,
            'completed' => 0,
            'failed' => 0,
            'refused' => 0,
        ];

        foreach ($updates as $update) {
            $status = self::stringOrNull($update['status'] ?? null);
            $key = $status === 'rejected' ? 'refused' : $status;

            if (is_string($key) && array_key_exists($key, $counts)) {
                $counts[$key]++;
            }
        }

        return $counts;
    }

    /**
     * @param list<array<string, mixed>> $updates
     *
     * @return list<string>
     */
    private static function updateNames(array $updates): array
    {
        $names = [];

        foreach ($updates as $update) {
            $name = self::stringOrNull($update['name'] ?? null);

            if ($name !== null) {
                $names[$name] = true;
            }
        }

        $names = array_keys($names);
        sort($names);

        return $names;
    }

    /**
     * @param list<array<string, mixed>> $updates
     *
     * @return list<array<string, mixed>>
     */
    private static function updateItems(array $updates): array
    {
        return array_values(array_map(static function (array $update): array {
            $status = self::stringOrNull($update['status'] ?? null);

            return [
                'id' => self::stringOrNull($update['id'] ?? null),
                'command_id' => self::stringOrNull($update['command_id'] ?? null),
                'request_id' => self::stringOrNull($update['request_id'] ?? null),
                'correlation_id' => self::stringOrNull($update['correlation_id'] ?? null),
                'request_fingerprint' => self::stringOrNull($update['request_fingerprint'] ?? null),
                'command_sequence' => self::intOrNull($update['command_sequence'] ?? null),
                'workflow_sequence' => self::intOrNull($update['workflow_sequence'] ?? null),
                'name' => self::stringOrNull($update['name'] ?? null),
                'status' => $status,
                'state_label' => self::stringOrNull($update['state_label'] ?? null)
                    ?? ($status === 'rejected' ? 'refused' : $status),
                'outcome' => self::stringOrNull($update['outcome'] ?? null),
                'reason' => self::stringOrNull($update['reason'] ?? null),
                'rejection_reason' => self::stringOrNull($update['rejection_reason'] ?? null),
                'arguments_available' => ($update['arguments_available'] ?? false) === true,
                'arguments' => $update['arguments'] ?? null,
                'payload_available' => ($update['payload_available'] ?? false) === true,
                'payload' => $update['payload'] ?? null,
                'result_available' => ($update['result_available'] ?? false) === true,
                'result' => $update['result'] ?? null,
                'error_available' => ($update['error_available'] ?? false) === true,
                'error' => $update['error'] ?? null,
                'history_event_ids' => self::stringList($update['history_event_ids'] ?? null),
                'history_event_sequences' => self::intList($update['history_event_sequences'] ?? null),
                'history_event_types' => self::stringList($update['history_event_types'] ?? null),
            ];
        }, $updates));
    }

    /**
     * @param mixed $targets
     * @param list<string> $declaredQueries
     *
     * @return list<array<string, mixed>>
     */
    private static function queryTargets(mixed $targets, array $declaredQueries): array
    {
        $normalized = [];

        foreach (self::listValue($targets) as $target) {
            $name = self::stringOrNull($target['name'] ?? null);

            if ($name === null) {
                continue;
            }

            $normalized[$name] = [
                'name' => $name,
                'has_contract' => ($target['has_contract'] ?? false) === true,
                'parameters' => self::listValue($target['parameters'] ?? null),
            ];
        }

        foreach ($declaredQueries as $query) {
            $normalized[$query] ??= [
                'name' => $query,
                'has_contract' => false,
                'parameters' => [],
            ];
        }

        ksort($normalized);

        return array_values($normalized);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function listValue(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter(
            $value,
            static fn (mixed $item): bool => is_array($item),
        )) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function mapValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item) && $item !== ''));
    }

    /**
     * @return list<int>
     */
    private static function intList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $item): int => (int) $item,
            array_filter($value, static fn (mixed $item): bool => is_int($item) || is_numeric($item)),
        ));
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_int($value) || is_numeric($value) ? (int) $value : null;
    }
}
