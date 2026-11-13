<?php

declare(strict_types=1);

namespace Waterline\Http\Controllers\Remote;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Waterline\Support\BackendConfiguration;
use Waterline\Support\Remote\RemoteBackend;

/**
 * Pauses and resumes activity dispatch on one task queue of the configured
 * namespace (native Server 3.8.0, task-queue-activity-dispatch-pause
 * contract). The Server records who paused and enforces the operator role;
 * Waterline refuses in read-only mode and when the backend cannot pause.
 */
final class RemoteTaskQueuesController extends RemoteController
{
    public const MAX_REASON_LENGTH = 1000;

    public function __construct(RemoteBackend $backend)
    {
        parent::__construct($backend);
    }

    public function pauseActivityDispatch(Request $request, string $taskQueue): JsonResponse
    {
        if ($response = $this->requireWriteAccess('task_queue.activity_dispatch.pause')) {
            return $response;
        }

        Validator::make($request->all(), [
            'reason' => ['nullable', 'string', 'max:'.self::MAX_REASON_LENGTH],
        ])->validate();

        if ($response = $this->requireActivityDispatchControl()) {
            return $response;
        }

        $reason = $request->input('reason');
        $reason = is_string($reason) && trim($reason) !== '' ? trim($reason) : null;

        return $this->outcome(
            $taskQueue,
            $this->backend->client()->pauseTaskQueueActivityDispatch($taskQueue, $reason),
        );
    }

    public function resumeActivityDispatch(string $taskQueue): JsonResponse
    {
        if ($response = $this->requireWriteAccess('task_queue.activity_dispatch.resume')) {
            return $response;
        }

        if ($response = $this->requireActivityDispatchControl()) {
            return $response;
        }

        return $this->outcome(
            $taskQueue,
            $this->backend->client()->resumeTaskQueueActivityDispatch($taskQueue),
        );
    }

    private function requireActivityDispatchControl(): ?JsonResponse
    {
        $control = $this->backend->activityDispatchControl();

        if ($control['available']) {
            return null;
        }

        $response = $this->capabilityUnavailable(
            RemoteBackend::ACTIVITY_DISPATCH_PAUSE_CAPABILITY,
            $control['reason'] === 'activity_dispatch_sdk_unavailable'
                ? implode(', ', RemoteBackend::ACTIVITY_DISPATCH_METHODS)
                : null,
        );
        $payload = $response->getData(true);
        $payload['message'] = $control['reason'] === 'activity_dispatch_sdk_unavailable'
            ? 'The installed durable-workflow/sdk cannot pause activity dispatch on a task queue.'
            : 'The connected Server does not advertise task_queue_activity_dispatch_pause. Pausing activity dispatch needs native Durable Workflow Server 3.8.0 or later.';
        $payload['activity_dispatch_reason'] = $control['reason'];

        return $response->setData($payload);
    }

    /** @param array<string, mixed> $result */
    private function outcome(string $taskQueue, array $result): JsonResponse
    {
        return response()->json($this->scoped([
            ...$result,
            'namespace' => is_string($result['namespace'] ?? null) ? $result['namespace'] : BackendConfiguration::namespace(),
            'task_queue' => is_string($result['task_queue'] ?? null) ? $result['task_queue'] : $taskQueue,
        ]));
    }
}
