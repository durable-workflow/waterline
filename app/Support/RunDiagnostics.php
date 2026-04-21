<?php

namespace Waterline\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

class RunDiagnostics
{
    private const ACTIVITY_FAILURE_REPEAT_THRESHOLD = 3;

    private const WORKFLOW_TASK_FAILURE_ATTEMPT_THRESHOLD = 3;

    private const HISTORY_BUDGET_WARNING_RATIO = 0.8;

    /**
     * @param array<string, mixed> $detail
     * @return list<array<string, mixed>>
     */
    public function forRun(WorkflowRun $run, array $detail, ?CarbonInterface $now = null): array
    {
        $run->loadMissing([
            'activityExecutions.attempts',
            'failures',
            'historyEvents',
            'summary',
            'tasks',
        ]);

        $now ??= now();
        $diagnostics = [];

        $diagnostics = array_merge($diagnostics, $this->activityRepeatedFailures($run, $detail));
        $diagnostics = array_merge($diagnostics, $this->activityTimeoutMisconfigurations($detail));
        $diagnostics = array_merge($diagnostics, $this->unboundedActivityRetryPolicies($detail));
        $diagnostics = array_merge($diagnostics, $this->workflowTaskFailures($detail));
        $diagnostics = array_merge($diagnostics, $this->historyBudgetWarnings($detail));
        $diagnostics = array_merge($diagnostics, $this->stuckConditionWaits($detail, $now));

        return $this->sortDiagnostics($diagnostics);
    }

    /**
     * @param array<string, mixed> $detail
     * @return list<array<string, mixed>>
     */
    private function activityRepeatedFailures(WorkflowRun $run, array $detail): array
    {
        $diagnostics = [];
        $activities = $this->activitiesById($detail);

        foreach ($this->activityFailureGroups($run) as $group) {
            if ($group['failure_count'] < $this->activityFailureRepeatThreshold()) {
                continue;
            }

            $activity = $activities[$group['activity_execution_id']] ?? [];
            $activityLabel = $this->activityLabel($activity, $group['activity_execution_id']);
            $failureClass = $group['failure_class'];

            $diagnostics[] = $this->diagnostic(
                'activity_repeated_failure',
                'warning',
                'Activity retried with the same error',
                sprintf(
                    '%s has recorded %d failures with %s.',
                    $activityLabel,
                    $group['failure_count'],
                    $failureClass,
                ),
                '/docs/2.0/failures-and-recovery',
                [
                    'activity_execution_id' => $group['activity_execution_id'],
                    'activity_type' => $this->stringValue($activity['type'] ?? null),
                    'activity_class' => $this->stringValue($activity['class'] ?? null),
                    'failure_class' => $failureClass,
                    'failure_count' => $group['failure_count'],
                    'latest_message' => $group['latest_message'],
                ],
                [
                    'activity / ' . $activityLabel,
                    'failures / ' . $group['failure_count'],
                    'exception / ' . $failureClass,
                ],
            );
        }

        return $diagnostics;
    }

    /**
     * @param array<string, mixed> $detail
     * @return list<array<string, mixed>>
     */
    private function activityTimeoutMisconfigurations(array $detail): array
    {
        $diagnostics = [];

        foreach ($this->activityRows($detail) as $activity) {
            $policy = $this->retryPolicy($activity);

            if ($policy === null) {
                continue;
            }

            $heartbeatTimeout = $this->positiveInt($policy['heartbeat_timeout'] ?? null);
            $startToCloseTimeout = $this->positiveInt($policy['start_to_close_timeout'] ?? null);

            if ($heartbeatTimeout === null || $startToCloseTimeout === null) {
                continue;
            }

            if ($heartbeatTimeout < $startToCloseTimeout) {
                continue;
            }

            $activityLabel = $this->activityLabel($activity);
            $diagnostics[] = $this->diagnostic(
                'activity_heartbeat_timeout_not_effective',
                'warning',
                'Activity heartbeat timeout cannot fire first',
                sprintf(
                    '%s has heartbeat_timeout %ds and start_to_close_timeout %ds.',
                    $activityLabel,
                    $heartbeatTimeout,
                    $startToCloseTimeout,
                ),
                '/docs/2.0/features/timeouts',
                [
                    'activity_execution_id' => $this->stringValue($activity['id'] ?? null),
                    'activity_type' => $this->stringValue($activity['type'] ?? null),
                    'heartbeat_timeout' => $heartbeatTimeout,
                    'start_to_close_timeout' => $startToCloseTimeout,
                ],
                [
                    'activity / ' . $activityLabel,
                    'heartbeat / ' . $heartbeatTimeout . 's',
                    'start-to-close / ' . $startToCloseTimeout . 's',
                ],
            );
        }

        return $diagnostics;
    }

    /**
     * @param array<string, mixed> $detail
     * @return list<array<string, mixed>>
     */
    private function unboundedActivityRetryPolicies(array $detail): array
    {
        $diagnostics = [];

        foreach ($this->activityRows($detail) as $activity) {
            $policy = $this->retryPolicy($activity);

            if ($policy === null || $policy === []) {
                continue;
            }

            $hasMaxAttempts = array_key_exists('max_attempts', $policy);
            $maxAttempts = $hasMaxAttempts ? $this->intValue($policy['max_attempts']) : null;

            if ($hasMaxAttempts && $maxAttempts !== null && $maxAttempts > 0) {
                continue;
            }

            $activityLabel = $this->activityLabel($activity);
            $diagnostics[] = $this->diagnostic(
                'activity_unbounded_retry_policy',
                'warning',
                'Activity retry policy is unbounded',
                sprintf('%s can retry indefinitely unless a timeout or non-retryable error stops it.', $activityLabel),
                '/docs/2.0/failures-and-recovery',
                [
                    'activity_execution_id' => $this->stringValue($activity['id'] ?? null),
                    'activity_type' => $this->stringValue($activity['type'] ?? null),
                    'max_attempts' => $policy['max_attempts'] ?? null,
                ],
                [
                    'activity / ' . $activityLabel,
                    'max attempts / unlimited',
                ],
            );
        }

        return $diagnostics;
    }

    /**
     * @param array<string, mixed> $detail
     * @return list<array<string, mixed>>
     */
    private function workflowTaskFailures(array $detail): array
    {
        $workflowTasks = array_values(array_filter(
            $this->taskRows($detail),
            fn (array $task): bool => $this->stringValue($task['type'] ?? null) === 'workflow'
        ));
        $problemTasks = array_values(array_filter(
            $workflowTasks,
            fn (array $task): bool => $this->workflowTaskIsRepeatedFailure($task)
        ));

        if ($problemTasks === []) {
            return [];
        }

        $highestAttemptCount = max(array_map(
            fn (array $task): int => $this->intValue($task['attempt_count'] ?? null) ?? 0,
            $problemTasks
        ));
        $task = $problemTasks[0];

        return [
            $this->diagnostic(
                'workflow_task_repeated_failure',
                $highestAttemptCount >= 5 ? 'critical' : 'warning',
                'Workflow task is failing repeatedly',
                sprintf(
                    'The selected run has %d workflow task%s with repeated failures; the server metric dw_workflow_task_consecutive_failures should be checked for the queue.',
                    count($problemTasks),
                    count($problemTasks) === 1 ? '' : 's',
                ),
                '/docs/2.0/polyglot/server#system-metrics',
                [
                    'task_id' => $this->stringValue($task['id'] ?? null),
                    'attempt_count' => $highestAttemptCount,
                    'transport_state' => $this->stringValue($task['transport_state'] ?? null),
                    'queue' => $this->stringValue($task['queue'] ?? null),
                    'last_error' => $this->stringValue($task['last_error'] ?? null),
                ],
                array_values(array_filter([
                    'workflow tasks / ' . count($problemTasks),
                    'max attempts / ' . $highestAttemptCount,
                    $this->stringValue($task['transport_state'] ?? null) !== null
                        ? 'transport / ' . $this->stringValue($task['transport_state'] ?? null)
                        : null,
                ])),
            ),
        ];
    }

    /**
     * @param array<string, mixed> $detail
     * @return list<array<string, mixed>>
     */
    private function historyBudgetWarnings(array $detail): array
    {
        $eventCount = $this->intValue($detail['history_event_count'] ?? null);
        $eventThreshold = $this->intValue($detail['history_event_threshold'] ?? null);
        $sizeBytes = $this->intValue($detail['history_size_bytes'] ?? null);
        $sizeThreshold = $this->intValue($detail['history_size_bytes_threshold'] ?? null);

        $eventRatio = $eventCount !== null && $eventThreshold !== null && $eventThreshold > 0
            ? $eventCount / $eventThreshold
            : 0;
        $sizeRatio = $sizeBytes !== null && $sizeThreshold !== null && $sizeThreshold > 0
            ? $sizeBytes / $sizeThreshold
            : 0;
        $ratio = max($eventRatio, $sizeRatio);
        $recommended = ($detail['continue_as_new_recommended'] ?? false) === true;

        if (! $recommended && $ratio < $this->historyBudgetWarningRatio()) {
            return [];
        }

        $evidence = [];

        if ($eventCount !== null && $eventThreshold !== null && $eventThreshold > 0) {
            $evidence[] = sprintf('events / %d of %d', $eventCount, $eventThreshold);
        }

        if ($sizeBytes !== null && $sizeThreshold !== null && $sizeThreshold > 0) {
            $evidence[] = sprintf('history size / %d of %d bytes', $sizeBytes, $sizeThreshold);
        }

        return [
            $this->diagnostic(
                'history_budget_near_limit',
                $recommended ? 'critical' : 'warning',
                $recommended ? 'Continue-as-new is recommended' : 'History is approaching the continue-as-new budget',
                $recommended
                    ? 'The selected run has crossed a configured history budget.'
                    : 'The selected run is close to a configured history budget.',
                '/docs/2.0/features/continue-as-new',
                [
                    'history_event_count' => $eventCount,
                    'history_event_threshold' => $eventThreshold,
                    'history_size_bytes' => $sizeBytes,
                    'history_size_bytes_threshold' => $sizeThreshold,
                    'continue_as_new_recommended' => $recommended,
                ],
                $evidence,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $detail
     * @return list<array<string, mixed>>
     */
    private function stuckConditionWaits(array $detail, CarbonInterface $now): array
    {
        $diagnostics = [];
        $slaSeconds = $this->conditionWaitSlaSeconds();
        $seen = [];

        foreach ($this->conditionWaitCandidates($detail) as $wait) {
            $openedAt = $this->timestamp($wait['opened_at'] ?? null);

            if ($openedAt === null) {
                continue;
            }

            $ageSeconds = max(0, $openedAt->diffInSeconds($now, false));

            if ($ageSeconds < $slaSeconds) {
                continue;
            }

            $waitId = $this->stringValue($wait['id'] ?? null) ?? 'summary';

            if (isset($seen[$waitId])) {
                continue;
            }

            $seen[$waitId] = true;
            $target = $this->stringValue($wait['target_name'] ?? null)
                ?? $this->stringValue($wait['condition_key'] ?? null)
                ?? 'condition';

            $diagnostics[] = $this->diagnostic(
                'condition_wait_stuck',
                'warning',
                'Condition wait has been open past its SLA',
                sprintf('The selected run has waited on %s for %d seconds.', $target, $ageSeconds),
                '/docs/2.0/features/condition-waits',
                [
                    'wait_id' => $waitId,
                    'condition_key' => $this->stringValue($wait['condition_key'] ?? null),
                    'age_seconds' => $ageSeconds,
                    'sla_seconds' => $slaSeconds,
                    'opened_at' => $openedAt->toJSON(),
                ],
                [
                    'wait / ' . $target,
                    'age / ' . $ageSeconds . 's',
                    'SLA / ' . $slaSeconds . 's',
                ],
            );
        }

        return $diagnostics;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function activityFailureGroups(WorkflowRun $run): array
    {
        $groups = [];
        $seen = [];

        foreach ($run->historyEvents->sortBy('sequence') as $event) {
            if (! $event instanceof WorkflowHistoryEvent || ! $this->isActivityFailureEvent($event)) {
                continue;
            }

            $payload = is_array($event->payload) ? $event->payload : [];
            $activityId = $this->stringValue($payload['activity_execution_id'] ?? null)
                ?? $this->stringValue($payload['source_id'] ?? null);

            if ($activityId === null) {
                continue;
            }

            $exception = is_array($payload['exception'] ?? null) ? $payload['exception'] : [];
            $failureClass = $this->stringValue($exception['class'] ?? null)
                ?? $this->stringValue($exception['__constructor'] ?? null)
                ?? $this->stringValue($payload['exception_class'] ?? null)
                ?? $this->stringValue($payload['exception_type'] ?? null)
                ?? 'unknown';
            $message = $this->stringValue($exception['message'] ?? null)
                ?? $this->stringValue($payload['message'] ?? null);
            $recordId = $this->stringValue($payload['failure_id'] ?? null) ?? 'history:' . $event->id;

            $this->recordActivityFailureGroup($groups, $seen, $activityId, $failureClass, $recordId, $message);
        }

        foreach ($run->failures as $failure) {
            if (! $failure instanceof WorkflowFailure || $failure->source_kind !== 'activity_execution') {
                continue;
            }

            $this->recordActivityFailureGroup(
                $groups,
                $seen,
                $failure->source_id,
                $failure->exception_class,
                $failure->id,
                $failure->message,
            );
        }

        ksort($groups);

        return array_values($groups);
    }

    /**
     * @param array<string, array<string, mixed>> $groups
     * @param array<string, true> $seen
     */
    private function recordActivityFailureGroup(
        array &$groups,
        array &$seen,
        string $activityId,
        string $failureClass,
        string $recordId,
        ?string $message,
    ): void {
        $seenKey = $activityId . "\n" . $failureClass . "\n" . $recordId;

        if (isset($seen[$seenKey])) {
            return;
        }

        $seen[$seenKey] = true;
        $groupKey = $activityId . "\n" . $failureClass;

        if (! isset($groups[$groupKey])) {
            $groups[$groupKey] = [
                'activity_execution_id' => $activityId,
                'failure_class' => $failureClass,
                'failure_count' => 0,
                'latest_message' => null,
            ];
        }

        $groups[$groupKey]['failure_count']++;
        $groups[$groupKey]['latest_message'] = $message;
    }

    private function isActivityFailureEvent(WorkflowHistoryEvent $event): bool
    {
        return in_array($event->event_type, [
            HistoryEventType::ActivityFailed,
            HistoryEventType::ActivityTimedOut,
        ], true);
    }

    /**
     * @param array<string, mixed> $detail
     * @return array<string, array<string, mixed>>
     */
    private function activitiesById(array $detail): array
    {
        $activities = [];

        foreach ($this->activityRows($detail) as $activity) {
            $id = $this->stringValue($activity['id'] ?? null);

            if ($id !== null) {
                $activities[$id] = $activity;
            }
        }

        return $activities;
    }

    /**
     * @param array<string, mixed> $detail
     * @return list<array<string, mixed>>
     */
    private function activityRows(array $detail): array
    {
        return array_values(array_filter(
            is_array($detail['activities'] ?? null) ? $detail['activities'] : [],
            fn (mixed $activity): bool => is_array($activity),
        ));
    }

    /**
     * @param array<string, mixed> $detail
     * @return list<array<string, mixed>>
     */
    private function taskRows(array $detail): array
    {
        return array_values(array_filter(
            is_array($detail['tasks'] ?? null) ? $detail['tasks'] : [],
            fn (mixed $task): bool => is_array($task),
        ));
    }

    /**
     * @param array<string, mixed> $activity
     * @return array<string, mixed>|null
     */
    private function retryPolicy(array $activity): ?array
    {
        return is_array($activity['retry_policy'] ?? null) ? $activity['retry_policy'] : null;
    }

    /**
     * @param array<string, mixed> $activity
     */
    private function activityLabel(array $activity, ?string $fallback = null): string
    {
        return $this->stringValue($activity['type'] ?? null)
            ?? $this->stringValue($activity['class'] ?? null)
            ?? $fallback
            ?? 'activity';
    }

    /**
     * @param array<string, mixed> $task
     */
    private function workflowTaskIsRepeatedFailure(array $task): bool
    {
        $attemptCount = $this->intValue($task['attempt_count'] ?? null) ?? 0;
        $status = $this->stringValue($task['status'] ?? null);
        $transport = $this->stringValue($task['transport_state'] ?? null);
        $hasError = $this->stringValue($task['last_error'] ?? null) !== null
            || $this->stringValue($task['last_dispatch_error'] ?? null) !== null
            || $this->stringValue($task['last_claim_error'] ?? null) !== null;

        return $attemptCount >= $this->workflowTaskFailureAttemptThreshold()
            && ($status === 'failed' || $hasError || in_array($transport, [
                'dispatch_failed',
                'claim_failed',
                'lease_expired',
                'replay_blocked',
            ], true));
    }

    /**
     * @param array<string, mixed> $detail
     * @return list<array<string, mixed>>
     */
    private function conditionWaitCandidates(array $detail): array
    {
        $candidates = [];

        foreach (is_array($detail['waits'] ?? null) ? $detail['waits'] : [] as $wait) {
            if (! is_array($wait)) {
                continue;
            }

            if (
                $this->stringValue($wait['kind'] ?? null) === 'condition'
                && $this->stringValue($wait['status'] ?? null) === 'open'
            ) {
                $candidates[] = $wait;
            }
        }

        if (
            $this->stringValue($detail['wait_kind'] ?? null) === 'condition'
            && $this->timestamp($detail['wait_started_at'] ?? null) !== null
        ) {
            $candidates[] = [
                'id' => $this->stringValue($detail['open_wait_id'] ?? null) ?? 'summary',
                'kind' => 'condition',
                'status' => 'open',
                'opened_at' => $detail['wait_started_at'],
                'deadline_at' => $detail['wait_deadline_at'] ?? null,
                'condition_key' => $this->stringValue($detail['wait_reason'] ?? null),
            ];
        }

        return $candidates;
    }

    /**
     * @param list<array<string, mixed>> $diagnostics
     * @return list<array<string, mixed>>
     */
    private function sortDiagnostics(array $diagnostics): array
    {
        $severityRank = [
            'critical' => 0,
            'warning' => 1,
            'info' => 2,
        ];

        usort($diagnostics, static function (array $left, array $right) use ($severityRank): int {
            $leftRank = $severityRank[$left['severity'] ?? 'info'] ?? 99;
            $rightRank = $severityRank[$right['severity'] ?? 'info'] ?? 99;

            if ($leftRank !== $rightRank) {
                return $leftRank <=> $rightRank;
            }

            return ($left['code'] ?? '') <=> ($right['code'] ?? '');
        });

        return array_values($diagnostics);
    }

    /**
     * @param array<string, mixed> $evidence
     * @param list<string> $evidenceSummary
     * @return array<string, mixed>
     */
    private function diagnostic(
        string $code,
        string $severity,
        string $title,
        string $summary,
        string $docsUrl,
        array $evidence,
        array $evidenceSummary,
    ): array {
        return [
            'code' => $code,
            'severity' => $severity,
            'title' => $title,
            'summary' => $summary,
            'docs_url' => $docsUrl,
            'evidence' => $evidence,
            'evidence_summary' => $evidenceSummary,
        ];
    }

    private function activityFailureRepeatThreshold(): int
    {
        return max(
            1,
            $this->intValue(config(
                'waterline.run_diagnostics.activity_failure_repeat_threshold',
                self::ACTIVITY_FAILURE_REPEAT_THRESHOLD,
            )) ?? self::ACTIVITY_FAILURE_REPEAT_THRESHOLD,
        );
    }

    private function workflowTaskFailureAttemptThreshold(): int
    {
        return max(
            1,
            $this->intValue(config(
                'waterline.run_diagnostics.workflow_task_failure_attempt_threshold',
                self::WORKFLOW_TASK_FAILURE_ATTEMPT_THRESHOLD,
            )) ?? self::WORKFLOW_TASK_FAILURE_ATTEMPT_THRESHOLD,
        );
    }

    private function historyBudgetWarningRatio(): float
    {
        $configured = config('waterline.run_diagnostics.history_budget_warning_ratio', self::HISTORY_BUDGET_WARNING_RATIO);

        return is_numeric($configured)
            ? max(0.0, min(1.0, (float) $configured))
            : self::HISTORY_BUDGET_WARNING_RATIO;
    }

    private function conditionWaitSlaSeconds(): int
    {
        return max(
            1,
            $this->intValue(config('waterline.run_diagnostics.condition_wait_sla_seconds', 300)) ?? 300,
        );
    }

    private function positiveInt(mixed $value): ?int
    {
        $value = $this->intValue($value);

        return $value !== null && $value > 0 ? $value : null;
    }

    private function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    private function timestamp(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return Carbon::parse($value);
        }

        return null;
    }
}
