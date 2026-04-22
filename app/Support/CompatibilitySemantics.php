<?php

namespace Waterline\Support;

use Workflow\V2\Support\WorkerCompatibility;
use Workflow\V2\Support\WorkerCompatibilityFleet;

class CompatibilitySemantics
{
    private const ACTIVE_HEARTBEAT_POLICY = 'Only active, unexpired worker heartbeats count as fleet support; stale or missing snapshots are not claimability evidence.';

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function annotateRun(array $payload): array
    {
        $payload = self::withCompatibilityFields($payload);
        $payload['compatibility_semantics'] = self::forPayload($payload);

        if (is_array($payload['tasks'] ?? null)) {
            $payload['tasks'] = array_map(
                static fn (mixed $task): mixed => is_array($task)
                    ? self::annotateTask($task, $payload)
                    : $task,
                $payload['tasks'],
            );
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function annotateListItem(array $item): array
    {
        $item = self::withCompatibilityFields($item);
        $item['compatibility_semantics'] = self::forPayload($item);

        return $item;
    }

    /**
     * @param  array<string, mixed>  $task
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>
     */
    private static function annotateTask(array $task, array $run): array
    {
        $task = self::withCompatibilityFields($task, $run);
        $task['compatibility_semantics'] = self::forPayload($task);

        return $task;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $fallback
     * @return array<string, mixed>
     */
    private static function withCompatibilityFields(array $payload, ?array $fallback = null): array
    {
        $compatibility = self::stringValue($payload['compatibility'] ?? null)
            ?? self::stringValue($fallback['compatibility'] ?? null);
        $connection = self::stringValue($payload['connection'] ?? null)
            ?? self::stringValue($fallback['connection'] ?? null);
        $queue = self::stringValue($payload['queue'] ?? null)
            ?? self::stringValue($fallback['queue'] ?? null);

        $payload['compatibility'] = $compatibility;
        $payload['compatibility_supported'] = self::boolOr(
            $payload['compatibility_supported'] ?? null,
            static fn (): bool => WorkerCompatibility::supports($compatibility),
        );
        $payload['compatibility_reason'] = self::stringValue($payload['compatibility_reason'] ?? null)
            ?? WorkerCompatibility::mismatchReason($compatibility);
        $payload['compatibility_supported_in_fleet'] = self::boolOr(
            $payload['compatibility_supported_in_fleet'] ?? null,
            static fn (): bool => WorkerCompatibilityFleet::supports($compatibility, $connection, $queue),
        );
        $payload['compatibility_fleet_reason'] = self::stringValue($payload['compatibility_fleet_reason'] ?? null)
            ?? WorkerCompatibilityFleet::mismatchReason($compatibility, $connection, $queue);

        if (! array_key_exists('compatibility_namespace', $payload)) {
            $payload['compatibility_namespace'] = self::stringValue($fallback['compatibility_namespace'] ?? null)
                ?? WorkerCompatibilityFleet::scopeNamespace();
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function forPayload(array $payload): array
    {
        $compatibility = self::stringValue($payload['compatibility'] ?? null);
        $claimable = self::boolValue($payload['compatibility_supported'] ?? null);
        $fleetSupported = self::boolValue($payload['compatibility_supported_in_fleet'] ?? null);
        $state = self::state($compatibility, $claimable, $fleetSupported);

        return [
            'state' => $state,
            'required_marker' => $compatibility,
            'claimable_by_this_build' => $claimable,
            'supported_in_active_fleet' => $fleetSupported,
            'compatibility_namespace' => self::stringValue($payload['compatibility_namespace'] ?? null),
            'current_build_reason' => self::stringValue($payload['compatibility_reason'] ?? null),
            'fleet_reason' => self::stringValue($payload['compatibility_fleet_reason'] ?? null),
            'active_heartbeat_policy' => self::ACTIVE_HEARTBEAT_POLICY,
            'operator_summary' => self::summary($state),
        ];
    }

    private static function state(?string $compatibility, ?bool $claimable, ?bool $fleetSupported): string
    {
        if ($compatibility === null) {
            return 'no_required_marker';
        }

        if ($claimable === true) {
            return 'claimable_by_this_build';
        }

        if ($fleetSupported === true) {
            return 'supported_elsewhere_in_active_fleet';
        }

        return 'waiting_for_active_compatible_worker';
    }

    private static function summary(string $state): string
    {
        return match ($state) {
            'claimable_by_this_build' => 'The current build can claim this required compatibility marker.',
            'supported_elsewhere_in_active_fleet' => 'Another active worker heartbeat can claim this marker, but this build cannot.',
            'waiting_for_active_compatible_worker' => 'No active worker heartbeat currently advertises this required compatibility marker.',
            default => 'No compatibility marker is required for this row.',
        };
    }

    private static function boolValue(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    private static function boolOr(mixed $value, callable $fallback): bool
    {
        return is_bool($value) ? $value : (bool) $fallback();
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }
}
