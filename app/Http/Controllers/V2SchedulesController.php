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
use Waterline\Waterline;

class V2SchedulesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = WorkflowSchedule::query()
            ->orderByDesc('created_at');

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

        $payload = $query->paginate(50)->toArray();
        $payload['operator_scope'] = OperatorScope::payload();

        return response()->json($payload);
    }

    public function show(string $scheduleId): JsonResponse
    {
        $schedule = $this->findSchedule($scheduleId);

        if ($schedule === null) {
            return response()->json(['error' => 'Schedule not found.'], 404);
        }

        $description = ScheduleManager::describe($schedule);

        return response()->json($this->withOperatorScope($description->toArray()));
    }

    public function pause(Request $request, string $scheduleId): JsonResponse
    {
        $schedule = $this->findSchedule($scheduleId);

        if ($schedule === null) {
            return response()->json(['error' => 'Schedule not found.'], 404);
        }

        try {
            ScheduleManager::pause($schedule, context: $this->commandContext($request));
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($this->withOperatorScope(ScheduleManager::describe($schedule)->toArray()));
    }

    public function resume(Request $request, string $scheduleId): JsonResponse
    {
        $schedule = $this->findSchedule($scheduleId);

        if ($schedule === null) {
            return response()->json(['error' => 'Schedule not found.'], 404);
        }

        try {
            ScheduleManager::resume($schedule, $this->commandContext($request));
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($this->withOperatorScope(ScheduleManager::describe($schedule)->toArray()));
    }

    public function trigger(Request $request, string $scheduleId): JsonResponse
    {
        $schedule = $this->findSchedule($scheduleId);

        if ($schedule === null) {
            return response()->json(['error' => 'Schedule not found.'], 404);
        }

        $instanceId = ScheduleManager::trigger(
            $schedule,
            context: $this->commandContext($request),
        );

        return response()->json([
            'schedule_id' => $scheduleId,
            'instance_id' => $instanceId,
            'triggered' => $instanceId !== null,
            'operator_scope' => OperatorScope::payload(),
        ]);
    }

    public function backfill(Request $request, string $scheduleId): JsonResponse
    {
        $schedule = $this->findSchedule($scheduleId);

        if ($schedule === null) {
            return response()->json(['error' => 'Schedule not found.'], 404);
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

        $overlapOverride = null;
        $policyInput = $request->input('overlap_policy');
        if (is_string($policyInput) && $policyInput !== '') {
            $overlapOverride = ScheduleOverlapPolicy::tryFrom($policyInput);
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

        return response()->json([
            'schedule_id' => $scheduleId,
            'results' => $results,
            'operator_scope' => OperatorScope::payload(),
        ]);
    }

    public function history(Request $request, string $scheduleId): JsonResponse
    {
        $schedule = $this->findSchedule($scheduleId);

        if ($schedule === null) {
            return response()->json(['error' => 'Schedule not found.'], 404);
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
        $schedule = $this->findSchedule($scheduleId);

        if ($schedule === null) {
            return response()->json(['error' => 'Schedule not found.'], 404);
        }

        ScheduleManager::delete($schedule, $this->commandContext($request));

        return response()->json($this->withOperatorScope(ScheduleManager::describe($schedule)->toArray()));
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

    private function findSchedule(string $scheduleId): ?WorkflowSchedule
    {
        return ScheduleManager::findByScheduleId(
            $scheduleId,
            namespace: OperatorScope::namespace(),
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withOperatorScope(array $payload): array
    {
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
