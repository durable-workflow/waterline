<?php

namespace Waterline\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Workflow\V2\Contracts\OperatorObservabilityRepository;
use Workflow\V2\Models\WorkflowRun;

/**
 * @mixin WorkflowRun
 */
class V2StoredWorkflowResource extends JsonResource
{
    public static $wrap = null;

    public function toArray($request)
    {
        return app(OperatorObservabilityRepository::class)->runDetail($this->resource);
    }
}
