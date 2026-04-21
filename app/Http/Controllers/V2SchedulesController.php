<?php

namespace Waterline\Http\Controllers;

use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workflow\V2\CommandContext;
use Workflow\V2\Enums\ScheduleOverlapPolicy;
use Workflow\V2\Enums\ScheduleStatus;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Support\ScheduleManager;
use Waterline\Waterline;

class V2SchedulesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = WorkflowSchedule::query()
            ->orderByDesc('created_at');

        $namespace = config('waterline.namespace');
        if (is_string($namespace) && $namespace !== '') {
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

        $schedules = $query->paginate(50);

        return response()->json($schedules);
    }

    public function show(string $scheduleId): JsonResponse
    {
        $schedule = $this->findSchedule($scheduleId);

        if ($schedule === null) {
            return response()->json(['error' => 'Schedule not found.'], 404);
        }

        $description = ScheduleManager::describe($schedule);

        return response()->json($description->toArray());
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

        return response()->json(ScheduleManager::describe($schedule)->toArray());
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

        return response()->json(ScheduleManager::describe($schedule)->toArray());
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
        ]);
    }

    public function destroy(Request $request, string $scheduleId): JsonResponse
    {
        $schedule = $this->findSchedule($scheduleId);

        if ($schedule === null) {
            return response()->json(['error' => 'Schedule not found.'], 404);
        }

        ScheduleManager::delete($schedule, $this->commandContext($request));

        return response()->json(ScheduleManager::describe($schedule)->toArray());
    }

    private function findSchedule(string $scheduleId): ?WorkflowSchedule
    {
        $namespace = config('waterline.namespace');

        return ScheduleManager::findByScheduleId(
            $scheduleId,
            namespace: is_string($namespace) && $namespace !== '' ? $namespace : null,
        );
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
