<?php

namespace Waterline\Http\Controllers;

use Workflow\V2\Support\HealthCheck;

class V2HealthController extends Controller
{
    public function show()
    {
        $snapshot = HealthCheck::snapshot();

        return response()->json($snapshot, HealthCheck::httpStatus($snapshot));
    }
}
