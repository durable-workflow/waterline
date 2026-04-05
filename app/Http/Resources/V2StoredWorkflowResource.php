<?php

namespace Waterline\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\RunDetailView;

/**
 * @mixin WorkflowRun
 */
class V2StoredWorkflowResource extends JsonResource
{
    public static $wrap = null;

    public function toArray($request)
    {
        return RunDetailView::forRun($this->resource);
    }
}
