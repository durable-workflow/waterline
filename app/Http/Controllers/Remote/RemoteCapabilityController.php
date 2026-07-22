<?php

declare(strict_types=1);

namespace Waterline\Http\Controllers\Remote;

use Illuminate\Http\JsonResponse;
use Waterline\Support\Remote\RemoteBackend;

final class RemoteCapabilityController extends RemoteController
{
    public function __construct(RemoteBackend $backend)
    {
        parent::__construct($backend);
    }

    public function unavailable(): JsonResponse
    {
        return $this->capabilityUnavailable('services');
    }
}
