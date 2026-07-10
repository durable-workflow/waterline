<?php

declare(strict_types=1);

namespace Waterline\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Workflow\Models\StoredWorkflowSignal;
use Workflow\Serializers\Serializer;

/**
 * @mixin StoredWorkflowSignal
 */
final class StoredWorkflowSignalResource extends JsonResource
{
    public static $wrap = null;

    public function toArray($request): array
    {
        $arguments = $this->arguments === null
            ? null
            : serialize(Serializer::unserialize($this->arguments));

        return [
            'id' => $this->id,
            'name' => $this->method,
            'method' => $this->method,
            'status' => 'recorded',
            'outcome' => 'legacy_signal_recorded',
            'target_scope' => 'workflow',
            'arguments_available' => $arguments !== null,
            'arguments' => $arguments,
            'received_at' => $this->created_at,
            'created_at' => $this->created_at,
        ];
    }
}
