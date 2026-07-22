<?php

declare(strict_types=1);

namespace Waterline\Http\Controllers\Remote;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Waterline\Support\Remote\RemoteBackend;

final class RemoteSchedulesController extends RemoteController
{
    private const PAGE_SIZE = 50;

    public function __construct(RemoteBackend $backend)
    {
        parent::__construct($backend);
    }

    public function index(Request $request): JsonResponse
    {
        $pageNumber = max(1, (int) $request->query('page', 1));
        $token = null;
        $page = null;

        for ($current = 1; $current <= $pageNumber; $current++) {
            $page = $this->backend->client()->listSchedules(
                status: $this->stringQuery($request, 'status'),
                workflowType: $this->stringQuery($request, 'workflow_type'),
                query: $this->stringQuery($request, 'query'),
                pageSize: self::PAGE_SIZE,
                nextPageToken: $token,
            );
            $token = $page->nextPageToken;

            if ($current < $pageNumber && $token === null) {
                break;
            }
        }

        $schedules = array_map(
            static fn ($schedule): array => self::schedulePayload($schedule->raw),
            $page?->schedules ?? [],
        );

        return response()->json($this->scoped([
            'data' => $schedules,
            'current_page' => $pageNumber,
            'last_page' => $token === null ? $pageNumber : $pageNumber + 1,
            'per_page' => self::PAGE_SIZE,
            'total' => (($pageNumber - 1) * self::PAGE_SIZE) + count($schedules) + ($token === null ? 0 : 1),
            'next_page_token' => $token,
        ]));
    }

    public function show(string $scheduleId): JsonResponse
    {
        $schedule = $this->backend->client()->describeSchedule($scheduleId);

        return response()->json($this->scoped(self::schedulePayload($schedule->raw)));
    }

    public function pause(Request $request, string $scheduleId): JsonResponse
    {
        if ($response = $this->requireWriteAccess('schedule.pause')) {
            return $response;
        }

        $this->backend->client()->pauseSchedule($scheduleId, $this->stringInput($request, 'note'));

        return $this->show($scheduleId);
    }

    public function resume(Request $request, string $scheduleId): JsonResponse
    {
        if ($response = $this->requireWriteAccess('schedule.resume')) {
            return $response;
        }

        $this->backend->client()->resumeSchedule($scheduleId, $this->stringInput($request, 'note'));

        return $this->show($scheduleId);
    }

    public function trigger(Request $request, string $scheduleId): JsonResponse
    {
        if ($response = $this->requireWriteAccess('schedule.trigger')) {
            return $response;
        }

        return response()->json($this->scoped($this->backend->client()->triggerSchedule(
            $scheduleId,
            $this->stringInput($request, 'overlap_policy'),
        )));
    }

    public function backfill(Request $request, string $scheduleId): JsonResponse
    {
        if ($response = $this->requireWriteAccess('schedule.backfill')) {
            return $response;
        }

        $from = $this->stringInput($request, 'from');
        $to = $this->stringInput($request, 'to');
        if ($from === null || $to === null) {
            return response()->json([
                'message' => 'Both "from" and "to" ISO 8601 timestamps are required.',
                'reason' => 'invalid_backfill_window',
            ], 422);
        }

        return response()->json($this->scoped($this->backend->client()->backfillSchedule(
            $scheduleId,
            $from,
            $to,
            $this->stringInput($request, 'overlap_policy'),
        )));
    }

    public function history(Request $request, string $scheduleId): JsonResponse
    {
        return response()->json($this->scoped($this->backend->client()->scheduleHistory(
            $scheduleId,
            max(1, min(500, (int) $request->query('limit', 100))),
            $request->integer('after_sequence') ?: null,
        )));
    }

    public function destroy(string $scheduleId): JsonResponse
    {
        if ($response = $this->requireWriteAccess('schedule.delete')) {
            return $response;
        }

        $this->backend->client()->deleteSchedule($scheduleId);

        return response()->json($this->scoped([
            'schedule_id' => $scheduleId,
            'status' => 'deleted',
        ]));
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function schedulePayload(array $payload): array
    {
        $action = is_array($payload['action'] ?? null) ? $payload['action'] : [];

        return [
            ...$payload,
            'id' => (string) ($payload['schedule_id'] ?? $payload['id'] ?? ''),
            'workflow_type' => $payload['workflow_type'] ?? $action['workflow_type'] ?? null,
            'workflow_class' => $payload['workflow_class'] ?? $action['workflow_class'] ?? null,
            'last_fire_at' => $payload['last_fire_at'] ?? $payload['last_fired_at'] ?? null,
            'last_fire_result' => $payload['last_fire_result'] ?? null,
        ];
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function stringInput(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
