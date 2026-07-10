<?php

declare(strict_types=1);

namespace Waterline\Http\Resources;

final class HybridStoredWorkflowResource extends StoredWorkflowResource
{
    public function toArray($request): array
    {
        $payload = parent::toArray($request);
        $legacyId = $payload['id'];
        $status = is_object($payload['status']) && method_exists($payload['status'], '__toString')
            ? (string) $payload['status']
            : $payload['status'];
        $statusBucket = match ($status) {
            'completed', 'continued' => 'completed',
            'failed' => 'failed',
            default => 'running',
        };

        return [
            ...$payload,
            'id' => 'v1:'.$legacyId,
            'legacy_id' => $legacyId,
            'operator_id' => 'v1:'.$legacyId,
            'engine_source' => 'v1',
            'engine_version' => '1.x',
            'execution_engine' => 'finish-on-v1',
            'status' => $status,
            'status_bucket' => $statusBucket,
            'is_terminal' => $statusBucket !== 'running',
            'detail_path' => '/api/flows/v1:'.$legacyId,
            'signals' => StoredWorkflowSignalResource::collection($this->signals),
        ];
    }
}
