<?php

declare(strict_types=1);

namespace Waterline\Http\Controllers\Remote;

use DurableWorkflow\Exception\DurableWorkflowException;
use DurableWorkflow\Model\ScheduleDescription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Waterline\Support\BackendConfiguration;
use Waterline\Support\Remote\CursorPageReplay;
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

        $replay = CursorPageReplay::replay(
            CursorPageReplay::requestedPage($request),
            fn (?string $token) => $this->backend->client()->listSchedules(
                status: $this->stringQuery($request, 'status'),
                workflowType: $this->stringQuery($request, 'workflow_type'),
                query: $this->stringQuery($request, 'query'),
                pageSize: self::PAGE_SIZE,
                nextPageToken: $token,
            ),
        );
        $pageNumber = $replay['page_number'];
        $token = $replay['next_page_token'];
        $hasMore = $token !== null && ! $replay['page_limit_reached'];
        $listOnly = array_reduce(
            $replay['page']->schedules,
            static fn (bool $complete, ScheduleDescription $schedule): bool => $complete
                && array_key_exists('dispatch_summary', $schedule->raw),
            true,
        );

        $schedules = array_map(
            fn (ScheduleDescription $schedule): array => $this->listRow($schedule->raw),
            $replay['page']->schedules,
        );

        return response()->json($this->scoped([
            'data' => $schedules,
            'current_page' => $pageNumber,
            'last_page' => $hasMore ? $pageNumber + 1 : $pageNumber,
            'per_page' => self::PAGE_SIZE,
            'total' => (($pageNumber - 1) * self::PAGE_SIZE) + count($schedules) + ($hasMore ? 1 : 0),
            'next_page_token' => $token,
            'page_limit' => CursorPageReplay::MAX_PAGE,
            'page_limit_reached' => $replay['page_limit_reached'],
            'refresh_interval_ms' => $listOnly ? 3000 : 30000,
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
     * A present summary selects the bounded list path. Invalid or unsupported
     * observations stay unavailable instead of borrowing legacy detail facts.
     *
     * Older lists need detail for the outcome: their retained skip alone cannot
     * establish the latest dispatch. A failed detail preserves list facts with
     * an unknown outcome; a successful read supplies its own skip snapshot.
     *
     * @param array<string, mixed> $listItem
     * @return array<string, mixed>
     */
    private function listRow(array $listItem): array
    {
        if (array_key_exists('dispatch_summary', $listItem)) {
            return ScheduleRow::detail($this->withNamespace($listItem));
        }

        $scheduleId = is_string($listItem['schedule_id'] ?? null) ? $listItem['schedule_id'] : '';

        if ($scheduleId === '') {
            return [...ScheduleRow::detail($this->withNamespace($listItem)), 'last_outcome' => null];
        }

        try {
            $detail = $this->backend->client()->describeSchedule($scheduleId)->raw;
        } catch (DurableWorkflowException) {
            return [...ScheduleRow::detail($this->withNamespace($listItem)), 'last_outcome' => null];
        }

        // Do not let earlier flat skip facts mask the later nested detail,
        // including nulls or fields unavailable in that detail's contract.
        unset($listItem['last_skip_reason'], $listItem['last_skipped_at'], $listItem['skipped_trigger_count']);

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
