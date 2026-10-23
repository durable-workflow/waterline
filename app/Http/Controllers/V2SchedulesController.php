<?php

namespace Waterline\Http\Controllers;

use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workflow\V2\CommandContext;
use Workflow\V2\Enums\ScheduleOverlapPolicy;
use Workflow\V2\Enums\ScheduleStatus;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Models\WorkflowScheduleHistoryEvent;
use Workflow\V2\Support\ScheduleManager;
use Waterline\Support\OperatorScope;
use Waterline\Support\BackendConfiguration;
use Waterline\Support\ScheduleActionResult;
use Waterline\Support\ScheduleRow;
use Waterline\Waterline;

class V2SchedulesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = WorkflowSchedule::query()
            ->orderByDesc('created_at')
            ->orderBy('namespace')
            ->orderBy('schedule_id');

        $namespace = OperatorScope::namespace();
        if ($namespace !== null) {
            $query->where('namespace', $namespace);
        }

        $status = $request->query('status');

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        } else {
            $query->whereIn('status', [
                ScheduleStatus::Active->value,
                ScheduleStatus::Paused->value,
            ]);
        }

        $payload = $query->paginate(50)
            ->through(fn (WorkflowSchedule $schedule): array => ScheduleRow::detail($this->scheduleSource($schedule)))
            ->toArray();
        $payload['operator_scope'] = OperatorScope::payload();
        $payload['backend'] = BackendConfiguration::payload();

        return response()->json($payload);
    }

    public function show(Request $request, string $scheduleId): JsonResponse
    {
        $schedule = $this->findSchedule($request, $scheduleId);

        if ($schedule instanceof JsonResponse) {
            return $schedule;
        }

        return response()->json($this->schedulePayload($schedule));
    }

    public function pause(Request $request, string $scheduleId): JsonResponse
    {
        $schedule = $this->findSchedule($request, $scheduleId);

        if ($schedule instanceof JsonResponse) {
            return $schedule;
        }

        try {
            ScheduleManager::pause($schedule, $this->note($request), $this->commandContext($request));
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($this->schedulePayload($schedule));
    }

    public function resume(Request $request, string $scheduleId): JsonResponse
    {
        $schedule = $this->findSchedule($request, $scheduleId);

        if ($schedule instanceof JsonResponse) {
            return $schedule;
        }

        try {
            ScheduleManager::resume($schedule, $this->commandContext($request));
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($this->schedulePayload($schedule));
    }

    public function trigger(Request $request, string $scheduleId): JsonResponse
    {
        $schedule = $this->findSchedule($request, $scheduleId);

        if ($schedule instanceof JsonResponse) {
            return $schedule;
        }

        $overlapPolicy = $this->overlapPolicy($request);

        if ($overlapPolicy instanceof JsonResponse) {
            return $overlapPolicy;
        }

        $result = ScheduleManager::triggerDetailed(
            $schedule,
            $overlapPolicy,
            $this->commandContext($request),
        );

        $payload = ScheduleActionResult::trigger($schedule->schedule_id, $schedule->namespace, [
            'outcome' => $result->outcome,
            'workflow_id' => $result->instanceId,
            'run_id' => $result->runId,
            'reason' => $result->reason,
        ]);
        $payload['operator_scope'] = OperatorScope::payload();

        return response()->json($payload);
    }

    public function backfill(Request $request, string $scheduleId): JsonResponse
    {
        $schedule = $this->findSchedule($request, $scheduleId);

        if ($schedule instanceof JsonResponse) {
            return $schedule;
        }

        $from = $request->input('from');
        $to = $request->input('to');

        if (! is_string($from) || ! is_string($to)) {
            return response()->json(['error' => 'Both "from" and "to" ISO 8601 timestamps are required.'], 422);
        }

        try {
            $fromDate = new DateTimeImmutable($from);
            $toDate = new DateTimeImmutable($to);
        } catch (\Exception) {
            return response()->json(['error' => 'Invalid date format. Use ISO 8601 timestamps.'], 422);
        }

        if ($toDate <= $fromDate) {
            return response()->json(['error' => 'The backfill end must be after its start.', 'reason' => 'invalid_time_range'], 422);
        }

        $overlapOverride = $this->overlapPolicy($request);

        if ($overlapOverride instanceof JsonResponse) {
            return $overlapOverride;
        }

        try {
            $results = ScheduleManager::backfill(
                $schedule,
                $fromDate,
                $toDate,
                $overlapOverride,
                $this->commandContext($request),
            );
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $payload = ScheduleActionResult::backfill($schedule->schedule_id, $schedule->namespace, $results);
        $payload['operator_scope'] = OperatorScope::payload();

        return response()->json($payload);
    }

    public function history(Request $request, string $scheduleId): JsonResponse
    {
        $schedule = $this->findSchedule($request, $scheduleId);

        if ($schedule instanceof JsonResponse) {
            return $schedule;
        }

        $limit = $this->parseLimit($request->query('limit'));
        $afterSequence = $this->parseAfterSequence($request->query('after_sequence'));

        $query = $schedule->historyEvents();

        if ($afterSequence !== null) {
            $query->where('sequence', '>', $afterSequence);
        }

        $events = $query->limit($limit + 1)->get();
        $hasMore = $events->count() > $limit;
        $events = $events->take($limit);

        $nextCursor = $hasMore && $events->isNotEmpty()
            ? (int) $events->last()->sequence
            : null;

        return response()->json([
            'schedule_id' => $schedule->schedule_id,
            'namespace' => $schedule->namespace,
            'events' => $events->map(fn (WorkflowScheduleHistoryEvent $event): array => [
                'id' => $event->id,
                'sequence' => (int) $event->sequence,
                'event_type' => $event->event_type?->value,
                'payload' => is_array($event->payload) ? $event->payload : [],
                'workflow_instance_id' => $event->workflow_instance_id,
                'workflow_run_id' => $event->workflow_run_id,
                'recorded_at' => $event->recorded_at?->toIso8601String(),
            ])->values(),
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
            'operator_scope' => OperatorScope::payload(),
        ]);
    }

    public function destroy(Request $request, string $scheduleId): JsonResponse
    {
        $schedule = $this->findSchedule($request, $scheduleId);

        if ($schedule instanceof JsonResponse) {
            return $schedule;
        }

        ScheduleManager::delete($schedule, $this->commandContext($request));

        return response()->json($this->schedulePayload($schedule));
    }

    private function parseLimit(mixed $raw): int
    {
        $default = 100;
        $max = 500;

        if (! is_string($raw) && ! is_int($raw)) {
            return $default;
        }

        $value = (int) $raw;

        if ($value <= 0) {
            return $default;
        }

        return min($value, $max);
    }

    private function parseAfterSequence(mixed $raw): ?int
    {
        if (! is_string($raw) && ! is_int($raw)) {
            return null;
        }

        $value = (int) $raw;

        return $value > 0 ? $value : null;
    }

    /**
     * Resolve a schedule by (namespace, schedule_id).
     *
     * Schedule IDs are unique only within a namespace. A namespace-scoped
     * Waterline answers for its own namespace and treats any other requested
     * namespace as not found. A cluster-wide Waterline uses the requested
     * namespace and refuses to guess when the same ID exists in several.
     */
    private function findSchedule(Request $request, string $scheduleId): WorkflowSchedule|JsonResponse
    {
        $scope = OperatorScope::namespace();
        $requested = $request->input('namespace');
        $requested = is_string($requested) && trim($requested) !== '' ? trim($requested) : null;

        if ($scope !== null && $requested !== null && strcasecmp($requested, $scope) !== 0) {
            return $this->scheduleNotFound();
        }

        $namespace = $scope ?? $requested;

        if ($namespace !== null) {
            return ScheduleManager::findByScheduleId($scheduleId, namespace: $namespace)
                ?? $this->scheduleNotFound();
        }

        return $this->findClusterSchedule($scheduleId);
    }

    private function findClusterSchedule(string $scheduleId): WorkflowSchedule|JsonResponse
    {
        $matches = WorkflowSchedule::query()
            ->where('schedule_id', $scheduleId)
            ->orderBy('namespace')
            ->limit(2)
            ->get();

        if ($matches->count() > 1) {
            return response()->json([
                'error' => 'This schedule ID exists in more than one namespace. Pass the namespace parameter.',
                'reason' => 'schedule_namespace_required',
                'schedule_id' => $scheduleId,
                'operator_scope' => OperatorScope::payload(),
            ], 409);
        }

        return $matches->first() ?? $this->scheduleNotFound();
    }

    private function overlapPolicy(Request $request): ScheduleOverlapPolicy|JsonResponse|null
    {
        $input = $request->input('overlap_policy');

        if (! is_string($input) || trim($input) === '') {
            return null;
        }

        return ScheduleOverlapPolicy::tryFrom(trim($input)) ?? response()->json([
            'error' => sprintf(
                'Unknown overlap policy [%s]. Use one of: %s.',
                trim($input),
                implode(', ', ScheduleActionResult::OVERLAP_POLICIES),
            ),
            'reason' => 'invalid_overlap_policy',
        ], 422);
    }

    private function note(Request $request): ?string
    {
        $note = $request->input('note');

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }

    private function scheduleNotFound(): JsonResponse
    {
        return response()->json(['error' => 'Schedule not found.', 'reason' => 'schedule_not_found'], 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduleSource(WorkflowSchedule $schedule): array
    {
        return [
            'schedule_id' => $schedule->schedule_id,
            'namespace' => $schedule->namespace ?? 'default',
            'spec' => $schedule->spec,
            'action' => $schedule->action,
            'status' => $schedule->status,
            'note' => $schedule->note,
            'overlap_policy' => $schedule->overlap_policy ?? 'skip',
            'next_fire_at' => $schedule->next_fire_at,
            'last_fired_at' => $schedule->last_fired_at,
            'recent_actions' => $schedule->recent_actions,
            'buffered_actions' => $schedule->buffered_actions,
            'fires_count' => (int) $schedule->fires_count,
            'failures_count' => (int) $schedule->failures_count,
            'skipped_trigger_count' => (int) ($schedule->skipped_trigger_count ?? 0),
            'last_skip_reason' => $schedule->last_skip_reason,
            'last_skipped_at' => $schedule->last_skipped_at,
            'remaining_actions' => $schedule->remaining_actions,
            'max_runs' => $schedule->max_runs,
            'jitter_seconds' => (int) $schedule->jitter_seconds,
            'latest_workflow_instance_id' => $schedule->latest_workflow_instance_id,
            'memo' => $schedule->memo,
            'search_attributes' => $schedule->search_attributes,
            'paused_at' => $schedule->paused_at,
            'created_at' => $schedule->created_at,
            'updated_at' => $schedule->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schedulePayload(WorkflowSchedule $schedule): array
    {
        $payload = ScheduleRow::detail($this->scheduleSource($schedule));
        $payload['operator_scope'] = OperatorScope::payload();

        return $payload;
    }

    private function commandContext(Request $request): CommandContext
    {
        $context = CommandContext::waterline($request);
        $principal = Waterline::principalFor($request);

        return $principal === null
            ? $context
            : $context->withPrincipal($principal['type'], $principal['id'], $principal['label'] ?? null);
    }
}
