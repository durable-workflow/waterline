<?php

declare(strict_types=1);

namespace Waterline\Http\Controllers\Remote;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use DurableWorkflow\Exception\ServerCapabilityUnavailable;
use UnexpectedValueException;
use Waterline\Support\VersionUsageInput;
use Waterline\Support\VersionUsagePage;

final class RemoteVersionUsageController extends RemoteController
{
    public function show(Request $request): JsonResponse
    {
        $query = VersionUsageInput::fromRequest($request);
        if ($unavailable = $this->requireCapability('workflowVersionUsage', 'workflow_version_usage')) {
            return $unavailable;
        }

        return $this->readPage($query);
    }

    /** @param array<string, mixed> $query */
    private function readPage(array $query): JsonResponse
    {
        try {
            // The SDK owns capability discovery, control authentication,
            // namespace selection and its configured request budgets.
            $page = $this->backend->client()->workflowVersionUsage(
                $query['workflow_type'], $query['change_id'], $query['min_version'],
                $query['state'], $query['limit'], $query['cursor'],
            );

            return response()->json(VersionUsagePage::validate(is_object($page) ? ($page->raw ?? null) : null, $query));
        } catch (ServerCapabilityUnavailable) {
            return $this->capabilityUnavailable('workflow_version_usage', 'workflowVersionUsage');
        } catch (UnexpectedValueException) {
            return response()->json(['message' => 'Invalid version usage response.', 'reason' => 'invalid_version_usage_response'], 502);
        }
    }
}
