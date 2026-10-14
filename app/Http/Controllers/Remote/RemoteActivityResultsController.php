<?php

declare(strict_types=1);

namespace Waterline\Http\Controllers\Remote;

use DurableWorkflow\Exception\ServerException;
use Illuminate\Http\JsonResponse;
use Throwable;
use Waterline\Support\OperatorScope;
use Waterline\Support\Remote\RemotePayloadPreview;

final class RemoteActivityResultsController extends RemoteController
{
    public function show(string $instanceId, string $runId, string $activityId): JsonResponse
    {
        $identity = ['instance_id' => $instanceId, 'run_id' => $runId, 'activity_id' => $activityId];
        $client = $this->backend->client();
        foreach (['workflowActivityResult', 'clusterInfo', 'payloadCodec'] as $method) {
            if (! $this->backend->supports($method)) {
                return $this->state($identity, 'unsupported', 'activity_result_sdk_unavailable');
            }
        }

        try {
            if (($client->clusterInfo()->capabilities['workflow_activity_result_inspection'] ?? null) !== true) {
                return $this->state($identity, 'unsupported', 'activity_result_server_unavailable');
            }
            $response = $client->workflowActivityResult($instanceId, $runId, $activityId);
        } catch (ServerException $exception) {
            return $this->state($identity, 'unavailable', $exception->reason ?? 'activity_result_read_failed',
                $exception->status >= 400 ? $exception->status : 502);
        }

        if (($response['workflow_id'] ?? null) !== $instanceId || ($response['run_id'] ?? null) !== $runId
            || ($response['activity_id'] ?? null) !== $activityId) {
            return $this->state($identity, 'unavailable', 'activity_result_identity_mismatch', 502);
        }
        $state = $response['state'] ?? null;
        if (($response['available'] ?? null) !== true || $state !== 'available') {
            if (($response['available'] ?? null) !== false
                || ! in_array($state, ['pending', 'failed', 'cancelled', 'pruned', 'unsupported', 'unavailable'], true)) {
                return $this->state($identity, 'unavailable', 'activity_result_response_invalid', 502);
            }

            return $this->state($identity, $state,
                is_string($response['reason'] ?? null) ? $response['reason'] : 'activity_result_unavailable');
        }
        if (! is_array($response['result_envelope'] ?? null)) {
            return $this->state($identity, 'unavailable', 'activity_result_envelope_unavailable', 502);
        }

        try {
            $result = RemotePayloadPreview::display($client->payloadCodec()->decodeEnvelope($response['result_envelope']));
        } catch (Throwable) {
            return $this->state($identity, 'unavailable', 'activity_result_decode_failed', 422);
        }

        return response()->json($identity + [
            'state' => 'available', 'available' => true, 'reason' => null,
            'history_sequence' => $response['history_sequence'] ?? null, 'result' => $result,
            'operator_scope' => OperatorScope::payload(),
        ], 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @param array<string, string> $identity */
    private function state(array $identity, string $state, string $reason, int $status = 200): JsonResponse
    {
        return response()->json($identity + [
            'state' => $state, 'available' => false, 'reason' => $reason,
            'operator_scope' => OperatorScope::payload(),
        ], $status);
    }
}
