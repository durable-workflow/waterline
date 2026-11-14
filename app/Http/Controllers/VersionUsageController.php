<?php

declare(strict_types=1);

namespace Waterline\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use UnexpectedValueException;
use Waterline\Support\EmbeddedVersionUsage;
use Waterline\Support\VersionUsageInput;
use Waterline\Support\WorkflowEngineSourceResolver;

final class VersionUsageController extends Controller
{
    public function show(Request $request, EmbeddedVersionUsage $reader): JsonResponse
    {
        $query = VersionUsageInput::fromRequest($request);
        if (! $reader->available() || ! WorkflowEngineSourceResolver::usesV2()) {
            return response()->json([
                'message' => 'The installed embedded backend does not expose version usage.',
                'reason' => 'backend_capability_unavailable',
                'capability' => 'workflow_version_usage',
            ], 501);
        }

        return $this->readPage($reader, $query);
    }

    /** @param array<string, mixed> $query */
    private function readPage(EmbeddedVersionUsage $reader, array $query): JsonResponse
    {
        try {
            return response()->json($reader->page($query));
        } catch (InvalidArgumentException) {
            return response()->json(['message' => 'Invalid version usage query or cursor.', 'reason' => 'invalid_request'], 422);
        } catch (UnexpectedValueException) {
            return response()->json(['message' => 'Invalid version usage response.', 'reason' => 'invalid_version_usage_response'], 502);
        }
    }
}
