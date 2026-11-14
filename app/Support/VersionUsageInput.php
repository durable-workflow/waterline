<?php

declare(strict_types=1);

namespace Waterline\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request as QueryRequest;

final class VersionUsageInput
{
    /** @return array{namespace: ?string, workflow_type: string, change_id: string, min_version: int, state: ?string, limit: int, cursor: ?string} */
    public static function fromRequest(Request $request): array
    {
        // Host TrimStrings/ConvertEmptyStringsToNull middleware must not change
        // a recorded identity or opaque cursor before this endpoint sees it.
        $raw = (string) $request->server('QUERY_STRING', '');
        if (strlen($raw) > 65536) {
            self::invalid('query');
        }

        $values = QueryRequest::create('/?'.$raw)->query->all();
        if (array_diff(array_keys($values), ['namespace', 'workflow_type', 'change_id', 'min_version', 'state', 'limit', 'cursor']) !== []) {
            self::invalid('query');
        }

        $namespace = array_key_exists('namespace', $values) ? self::text($values['namespace'], 'namespace', 255) : null;
        $scope = OperatorScope::namespace();
        if ($scope !== null && $namespace !== null && $scope !== $namespace) {
            throw new AuthorizationException('The requested namespace is outside the Waterline scope.');
        }

        $state = $values['state'] ?? null;
        if ($state !== null && ! in_array($state, ['legacy', 'current', 'unknown'], true)) {
            self::invalid('state');
        }

        return [
            'namespace' => $scope ?? $namespace,
            'workflow_type' => self::text($values['workflow_type'] ?? null, 'workflow_type', 255),
            'change_id' => self::text($values['change_id'] ?? null, 'change_id', 1024),
            'min_version' => self::integer($values['min_version'] ?? null, 'min_version', -2147483648, 2147483647),
            'state' => $state,
            'limit' => self::integer($values['limit'] ?? 50, 'limit', 1, 200),
            'cursor' => array_key_exists('cursor', $values) ? self::text($values['cursor'], 'cursor', 8192) : null,
        ];
    }

    private static function text(mixed $value, string $field, int $maximum): string
    {
        if (! is_string($value) || strlen($value) > $maximum || preg_match('/[^\s\p{Z}]/u', $value) !== 1) {
            self::invalid($field);
        }

        return $value;
    }

    private static function integer(mixed $value, string $field, int $minimum, int $maximum): int
    {
        $parsed = is_string($value) || is_int($value)
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum, 'max_range' => $maximum]])
            : false;
        if ($parsed === false) {
            self::invalid($field);
        }

        return $parsed;
    }

    private static function invalid(string $field): never
    {
        throw ValidationException::withMessages([$field => 'Invalid version usage query.']);
    }
}
