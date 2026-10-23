<?php

declare(strict_types=1);

namespace Waterline\Http\Controllers\Remote;

use DurableWorkflow\Exception\DurableWorkflowException;
use DurableWorkflow\Model\ScheduleDescription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Waterline\Support\BackendConfiguration;
use Waterline\Support\Remote\RemoteBackend;
use Waterline\Support\ScheduleActionResult;
use Waterline\Support\ScheduleRow;

final class RemoteSchedulesController extends RemoteController
{
    private const PAGE_SIZE = 50;

    public function __construct(RemoteBackend $backend)
    {
        parent::__construct($backend);
    }

    public function index(Request $request): JsonResponse
    {
        Validator::make($request->query(), [
            'workflow_type' => ['sometimes', 'required', 'string', 'min:1', 'max:255', 'regex:/\S/'],
            'query' => ['sometimes', 'required', 'string', 'min:1', 'max:2048'],
        ])->validate();

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
            fn (ScheduleDescription $schedule): array => $this->listRow($schedule->raw),
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

    public function show(Request $request, string $scheduleId): JsonResponse
    {
        if ($response = $this->rejectOtherNamespace($request)) {
            return $response;
        }

        return $this->detailResponse($scheduleId);
    }

    public function pause(Request $request, string $scheduleId): JsonResponse
    {
        if ($response = $this->requireWriteAccess('schedule.pause') ?? $this->rejectOtherNamespace($request)) {
            return $response;
        }

        $this->backend->client()->pauseSchedule($scheduleId, $this->stringInput($request, 'note'));

        return $this->detailResponse($scheduleId);
    }

    public function resume(Request $request, string $scheduleId): JsonResponse
    {
        if ($response = $this->requireWriteAccess('schedule.resume') ?? $this->rejectOtherNamespace($request)) {
            return $response;
        }

        $this->backend->client()->resumeSchedule($scheduleId, $this->stringInput($request, 'note'));

        return $this->detailResponse($scheduleId);
    }

    public function trigger(Request $request, string $scheduleId): JsonResponse
    {
        if ($response = $this->requireWriteAccess('schedule.trigger') ?? $this->rejectOtherNamespace($request)) {
            return $response;
        }

        $overlapPolicy = $this->stringInput($request, 'overlap_policy');
        if ($response = $this->rejectUnknownOverlapPolicy($overlapPolicy)) {
            return $response;
        }

        return response()->json($this->scoped(ScheduleActionResult::trigger(
            $scheduleId,
            BackendConfiguration::namespace(),
            $this->backend->client()->triggerSchedule($scheduleId, $overlapPolicy),
        )));
    }

    public function backfill(Request $request, string $scheduleId): JsonResponse
    {
        if ($response = $this->requireWriteAccess('schedule.backfill') ?? $this->rejectOtherNamespace($request)) {
            return $response;
        }

        $from = $this->stringInput($request, 'from');
        $to = $this->stringInput($request, 'to');
        $overlapPolicy = $this->stringInput($request, 'overlap_policy');

        if ($response = $this->rejectInvalidBackfill($from, $to, $overlapPolicy)) {
            return $response;
        }

        $result = $this->backend->client()->backfillSchedule($scheduleId, (string) $from, (string) $to, $overlapPolicy);

        return response()->json($this->scoped(ScheduleActionResult::backfill(
            $scheduleId,
            BackendConfiguration::namespace(),
            is_array($result['results'] ?? null) ? array_values($result['results']) : [],
        )));
    }

    public function history(Request $request, string $scheduleId): JsonResponse
    {
        if ($response = $this->rejectOtherNamespace($request)) {
            return $response;
        }

        return response()->json($this->scoped([
            'namespace' => BackendConfiguration::namespace(),
            ...$this->backend->client()->scheduleHistory(
                $scheduleId,
                max(1, min(500, (int) $request->query('limit', 100))),
                $request->integer('after_sequence') ?: null,
            ),
        ]));
    }

    public function destroy(Request $request, string $scheduleId): JsonResponse
    {
        if ($response = $this->requireWriteAccess('schedule.delete') ?? $this->rejectOtherNamespace($request)) {
            return $response;
        }

        $this->backend->client()->deleteSchedule($scheduleId);

        return response()->json($this->scoped([
            'id' => $scheduleId,
            'schedule_id' => $scheduleId,
            'namespace' => BackendConfiguration::namespace(),
            'status' => 'deleted',
        ]));
    }

    /**
     * Build a list row from the server's list item and the schedule's detail.
     *
     * The server's list item has no fire outcomes, so each listed schedule's
     * detail supplies its last outcome. When that read fails, the row keeps the
     * list facts and reports no outcome rather than failing the whole page.
     *
     * @param array<string, mixed> $listItem
     * @return array<string, mixed>
     */
    private function listRow(array $listItem): array
    {
        $scheduleId = is_string($listItem['schedule_id'] ?? null) ? $listItem['schedule_id'] : '';

        if ($scheduleId === '') {
            return ScheduleRow::detail($this->withNamespace($listItem));
        }

        try {
            $detail = $this->backend->client()->describeSchedule($scheduleId)->raw;
        } catch (DurableWorkflowException) {
            return ScheduleRow::detail($this->withNamespace($listItem));
        }

        return ScheduleRow::detail($this->withNamespace([...$listItem, ...$detail]));
    }

    private function detailResponse(string $scheduleId): JsonResponse
    {
        $schedule = $this->backend->client()->describeSchedule($scheduleId);

        return response()->json($this->scoped(ScheduleRow::detail($this->withNamespace($schedule->raw))));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withNamespace(array $payload): array
    {
        if (! is_string($payload['namespace'] ?? null) || trim($payload['namespace']) === '') {
            $payload['namespace'] = BackendConfiguration::namespace();
        }

        return $payload;
    }

    /**
     * Service mode observes exactly one namespace, so a row from any other
     * namespace is not visible here.
     */
    private function rejectOtherNamespace(Request $request): ?JsonResponse
    {
        $requested = $this->stringInput($request, 'namespace');

        // The server stores namespaces in lower case, so rows may differ in
        // case from the configured namespace.
        if ($requested === null || strcasecmp($requested, BackendConfiguration::namespace()) === 0) {
            return null;
        }

        return response()->json($this->scoped([
            'message' => 'Schedule not found.',
            'reason' => 'schedule_not_found',
        ]), 404);
    }

    private function rejectInvalidBackfill(?string $from, ?string $to, ?string $overlapPolicy): ?JsonResponse
    {
        if ($from === null || $to === null) {
            return response()->json([
                'message' => 'Both "from" and "to" ISO 8601 timestamps are required.',
                'reason' => 'invalid_backfill_window',
            ], 422);
        }

        return $this->rejectUnknownOverlapPolicy($overlapPolicy);
    }

    private function rejectUnknownOverlapPolicy(?string $policy): ?JsonResponse
    {
        if (ScheduleActionResult::validOverlapPolicy($policy)) {
            return null;
        }

        return response()->json($this->scoped([
            'message' => sprintf(
                'Unknown overlap policy [%s]. Use one of: %s.',
                $policy,
                implode(', ', ScheduleActionResult::OVERLAP_POLICIES),
            ),
            'reason' => 'invalid_overlap_policy',
        ]), 422);
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
