<?php

namespace Waterline\Support;

final class ContinueAsNewObservation
{
    private const TRANSITIONS = 'continue_as_new_build_transitions';

    private const REFUSAL = 'continue_as_new_refusal';

    public static function annotate(array $detail, array $observation = []): array
    {
        if ($observation !== [] && ($observation['instance_id'] ?? null) === $detail['instance_id']
            && ($observation['run_id'] ?? null) === $detail['run_id']
            && ($observation['namespace'] ?? null) === $detail['namespace']
            && ($observation['status'] ?? null) === $detail['status']) {
            foreach ([self::TRANSITIONS, self::REFUSAL] as $field) {
                if (array_key_exists($field, $observation)) {
                    $detail[$field] = $observation[$field];
                }
            }
        }

        if (array_key_exists(self::TRANSITIONS, $detail)) {
            $transitions = is_array($detail[self::TRANSITIONS]) ? $detail[self::TRANSITIONS] : [];
            $detail[self::TRANSITIONS] = [
                'from_predecessor' => self::transition($transitions['from_predecessor'] ?? null, $detail['run_id'] ?? null),
                'to_successor' => self::transition($transitions['to_successor'] ?? null, $detail['run_id'] ?? null),
            ];
        }

        if (array_key_exists(self::REFUSAL, $detail)) {
            $detail[self::REFUSAL] = self::refusal($detail[self::REFUSAL], $detail['is_terminal'] ?? null);
        }

        return $detail;
    }

    private static function transition(mixed $recorded, mixed $selectedRun): ?array
    {
        if (! is_array($recorded) || ! is_string($recorded['run_id'] ?? null)
            || $recorded['run_id'] === '' || $recorded['run_id'] === $selectedRun
            || ! in_array($recorded['resolution'] ?? null, ['inherited', 'promoted'], true)) {
            return null;
        }
        foreach (['from_compatibility', 'to_compatibility'] as $marker) {
            if (! array_key_exists($marker, $recorded) || ($recorded[$marker] !== null && ! is_string($recorded[$marker]))) {
                return null;
            }
        }

        return [
            'run_id' => $recorded['run_id'],
            'from_compatibility' => $recorded['from_compatibility'],
            'to_compatibility' => $recorded['to_compatibility'],
            'resolution' => $recorded['resolution'],
        ];
    }

    private static function refusal(mixed $recorded, mixed $terminal): ?array
    {
        if (! is_array($recorded) || ($recorded['reason'] ?? null) !== 'promoted_build_unavailable'
            || ! is_string($recorded['task_id'] ?? null) || $recorded['task_id'] === ''
            || ! is_int($recorded['workflow_task_attempt'] ?? null) || $recorded['workflow_task_attempt'] < 1
            || ! in_array($recorded['status'] ?? null, ['ready', 'leased', 'failed', 'cancelled', 'completed'], true)) {
            return null;
        }
        $recordedAt = self::timestamp($recorded['recorded_at'] ?? null);
        if ($recordedAt === null) {
            return null;
        }

        return [
            'reason' => $recorded['reason'],
            'task_id' => $recorded['task_id'],
            'status' => $recorded['status'],
            'workflow_task_attempt' => $recorded['workflow_task_attempt'],
            'recorded_at' => $recordedAt,
            'available_at' => $recorded['status'] === 'ready' && $terminal === false
                ? self::timestamp($recorded['available_at'] ?? null) : null,
        ];
    }

    private static function timestamp(mixed $value): ?string
    {
        return is_string($value)
            && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/', $value)
            && strtotime($value) !== false ? $value : null;
    }
}
