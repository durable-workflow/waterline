<?php

namespace Waterline\Support;

class ActionabilityContract
{
    public const SCHEMA = 'waterline.actionability';
    public const VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function definition(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'repair_states' => [
                'repairable',
                'blocked',
                'not_needed',
                'unknown',
            ],
            'evidence_states' => [
                'actionable',
                'diagnostic_only',
            ],
            'repair_source_authorities' => [
                'typed_history',
                'mutable_open_fallback',
            ],
            'diagnostic_source_authorities' => [
                'failure_row_fallback',
                'unsupported_terminal_without_history',
            ],
            'rules' => [
                'diagnostic_only_true_is_never_a_resume_source',
                'unsupported_terminal_without_history_is_never_repairable',
                'closed_or_non_current_runs_require_an_explicit_blocked_reason',
                'badges_and_exports_must_preserve_actionability_state',
                'detail_action_affordances_must_derive_from_actionability_actions',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function annotateRun(array $payload): array
    {
        foreach (['activities', 'waits', 'timers', 'exceptions', 'logs', 'chartData'] as $key) {
            if (! is_array($payload[$key] ?? null)) {
                continue;
            }

            $payload[$key] = array_map(
                static fn (mixed $row): mixed => is_array($row) ? self::annotateEvidence($row) : $row,
                $payload[$key],
            );
        }

        $payload['actionability'] = self::runActionability($payload);
        $payload['actionability_contract'] = self::definition();

        return $payload;
    }

    /**
     * @param array<string, mixed> $export
     * @return array<string, mixed>
     */
    public static function annotateExport(array $export): array
    {
        foreach (['activities', 'waits', 'timers', 'exceptions', 'logs', 'timeline'] as $key) {
            if (! is_array($export[$key] ?? null)) {
                continue;
            }

            $export[$key] = array_map(
                static fn (mixed $row): mixed => is_array($row) ? self::annotateEvidence($row) : $row,
                $export[$key],
            );
        }

        $export['actionability_contract'] = self::definition();

        return $export;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function annotateEvidence(array $row): array
    {
        $diagnosticOnly = ($row['diagnostic_only'] ?? false) === true;
        $authority = self::stringValue($row['history_authority'] ?? null);
        $unsupportedReason = self::stringValue($row['history_unsupported_reason'] ?? null);
        $repairSource = ! $diagnosticOnly
            && in_array($authority, ['typed_history', 'mutable_open_fallback'], true);

        $row['actionability'] = [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'state' => $repairSource ? 'actionable' : 'diagnostic_only',
            'repair_source' => $repairSource,
            'diagnostic_only' => ! $repairSource,
            'history_authority' => $authority,
            'history_unsupported_reason' => $unsupportedReason,
        ];

        return $row;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function runActionability(array $payload): array
    {
        $canRepair = ($payload['can_repair'] ?? null) === true;
        $blockedReason = self::stringValue($payload['repair_blocked_reason'] ?? null);
        $statusBucket = self::stringValue($payload['status_bucket'] ?? null);
        $closedReason = self::stringValue($payload['closed_reason'] ?? null);

        $repairState = match (true) {
            $canRepair => 'repairable',
            $blockedReason !== null => 'blocked',
            $statusBucket === 'running' => 'unknown',
            default => 'not_needed',
        };

        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'repair_state' => $repairState,
            'repairable' => $repairState === 'repairable',
            'blocked_reason' => $blockedReason,
            'status_bucket' => $statusBucket,
            'closed_reason' => $closedReason,
            'task_problem' => ($payload['task_problem'] ?? false) === true,
            'diagnostic_only_evidence' => self::hasDiagnosticOnlyEvidence($payload),
            'actions' => self::runActions($payload, $repairState, $blockedReason),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, array{allowed: bool, reason: ?string, derived_from: string}>
     */
    private static function runActions(array $payload, string $repairState, ?string $blockedReason): array
    {
        $actions = [];

        foreach (['query', 'signal', 'update', 'cancel', 'terminate', 'archive'] as $action) {
            $allowedKey = 'can_'.$action;
            $reasonKey = $action.'_blocked_reason';

            if (! array_key_exists($allowedKey, $payload) && ! array_key_exists($reasonKey, $payload)) {
                continue;
            }

            $allowed = ($payload[$allowedKey] ?? false) === true;
            $actions[$action] = [
                'allowed' => $allowed,
                'reason' => $allowed ? null : self::stringValue($payload[$reasonKey] ?? null),
                'derived_from' => 'command_contract',
            ];
        }

        if (array_key_exists('can_repair', $payload) || array_key_exists('repair_blocked_reason', $payload)) {
            $allowed = $repairState === 'repairable';
            $actions['repair'] = [
                'allowed' => $allowed,
                'reason' => $allowed ? null : self::repairBlockedReason($repairState, $blockedReason),
                'derived_from' => 'repair_state',
            ];
        }

        return $actions;
    }

    private static function repairBlockedReason(string $repairState, ?string $blockedReason): ?string
    {
        return match ($repairState) {
            'blocked' => $blockedReason,
            'not_needed' => 'repair_not_needed',
            'unknown' => 'repair_state_unknown',
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function hasDiagnosticOnlyEvidence(array $payload): bool
    {
        foreach (['activities', 'waits', 'timers', 'exceptions', 'logs', 'chartData'] as $key) {
            foreach (is_array($payload[$key] ?? null) ? $payload[$key] : [] as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $actionability = $row['actionability'] ?? null;
                if (is_array($actionability) && ($actionability['diagnostic_only'] ?? false) === true) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }
}
