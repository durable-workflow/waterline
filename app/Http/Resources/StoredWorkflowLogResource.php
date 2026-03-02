<?php
declare(strict_types=1);

namespace Waterline\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Workflow\Models\StoredWorkflowLog;
use Workflow\Serializers\Serializer;

/**
 * @mixin StoredWorkflowLog
 */
class StoredWorkflowLogResource extends JsonResource
{
    public static $wrap = null;

    public function toArray($request)
    {
        return [
            "id" => $this->id,
            "index" => (int) $this->index,
            "now" => $this->now,
            "class" => $this->class,
            "result" => serialize(Serializer::unserialize($this->result)),
            "created_at" => $this->created_at,
        ];
    }
}
