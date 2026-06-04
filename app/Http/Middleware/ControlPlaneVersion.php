<?php

namespace Waterline\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ControlPlaneVersion
{
    public const HEADER = 'X-Durable-Workflow-Control-Plane-Version';

    public const VERSION = '2';

    public function handle(Request $request, Closure $next): Response
    {
        $version = $request->header(self::HEADER);

        if (! is_string($version) || trim($version) === '' || trim($version) === self::VERSION) {
            $response = $next($request);
            $response->headers->set(self::HEADER, self::VERSION);

            return $response;
        }

        return $this->unsupportedVersion($version);
    }

    private function unsupportedVersion(string $version): JsonResponse
    {
        return response()
            ->json([
                'message' => 'Unsupported control-plane version for Waterline observer routes.',
                'reason' => 'unsupported_control_plane_version',
                'supported_version' => self::VERSION,
                'requested_version' => trim($version),
                'remediation' => sprintf(
                    'Waterline renders v2 observer state for control-plane %s. Upgrade Waterline, pin the observer to a compatible server, or connect to a server that supports %s.',
                    self::VERSION,
                    trim($version),
                ),
            ], 400)
            ->header(self::HEADER, self::VERSION);
    }
}
