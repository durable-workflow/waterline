<?php

namespace Waterline\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Workflow\V2\Contracts\OperatorObservabilityRepository;
use Workflow\V2\Models\WorkflowRun;
use Waterline\Support\RunDiagnostics;

/**
 * @mixin WorkflowRun
 */
class V2StoredWorkflowResource extends JsonResource
{
    public static $wrap = null;

    public function toArray($request)
    {
        $detail = app(OperatorObservabilityRepository::class)->runDetail($this->resource);
        $detail['run_diagnostics'] = app(RunDiagnostics::class)->forRun($this->resource, $detail);

        return $detail;
    }
}
