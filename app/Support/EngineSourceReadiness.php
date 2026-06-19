<?php

declare(strict_types=1);

namespace Waterline\Support;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;

final class EngineSourceReadiness
{
    /**
     * @param array<string, mixed>|null $status
     */
    public static function pinnedV2Unavailable(?array $status = null): bool
    {
        $status ??= WorkflowEngineSourceResolver::status();

        return ($status['resolved'] ?? null) === 'v2'
            && ($status['uses_v2'] ?? false) !== true;
    }

    /**
     * @param array<string, mixed>|null $status
     * @return array{
     *     message: string,
     *     operator_scope: array<string, mixed>,
     *     engine_source: array<string, mixed>,
     *     readiness_issues: list<mixed>,
     *     readiness_issue_codes: list<mixed>
     * }
     */
    public static function unavailablePayload(?array $status = null): array
    {
        $status ??= WorkflowEngineSourceResolver::status();

        return [
            'message' => self::unavailableMessage($status),
            'operator_scope' => OperatorScope::payload(),
            'engine_source' => $status,
            'readiness_issues' => is_array($status['readiness_issues'] ?? null)
                ? array_values($status['readiness_issues'])
                : [],
            'readiness_issue_codes' => is_array($status['readiness_issue_codes'] ?? null)
                ? array_values($status['readiness_issue_codes'])
                : [],
        ];
    }

    /**
     * @param array<string, mixed>|null $status
     */
    public static function unavailableResponse(?array $status = null): JsonResponse
    {
        return response()->json(self::unavailablePayload($status), 503);
    }

    /**
     * @param array<string, mixed>|null $status
     */
    public static function throwIfPinnedV2Unavailable(?array $status = null): void
    {
        $status ??= WorkflowEngineSourceResolver::status();

        if (! self::pinnedV2Unavailable($status)) {
            return;
        }

        throw new HttpResponseException(self::unavailableResponse($status));
    }

    /**
     * @param array<string, mixed> $status
     */
    private static function unavailableMessage(array $status): string
    {
        $message = $status['message'] ?? null;

        return is_string($message) && $message !== ''
            ? $message
            : 'Waterline v2 is unavailable because the workflow package operator surface is incomplete.';
    }
}
