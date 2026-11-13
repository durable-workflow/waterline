<?php

declare(strict_types=1);

namespace Waterline\Support;

use Illuminate\Http\Request;
use Waterline\Waterline;

/**
 * Workflow 2.27.0 reports work an app refused because its namespace is
 * outside the app's allowlist (`namespace_scope_refusals`), naming each
 * refused namespace. Those names are shown only to a viewer the host's
 * Waterline::auth gate admits. Like embedded exception source, they are
 * always hidden when WATERLINE_ALLOW_UNAUTHENTICATED is on; such an observer
 * stack sees the counts by path without them.
 */
final class NamespaceScopeRefusalVisibility
{
    public const CHECK = 'namespace_scope_refusals';

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function forRequest(array $payload, ?Request $request = null): array
    {
        $request ??= request();

        return Waterline::allowsUnauthenticatedAccess() || ! Waterline::check($request)
            ? self::redact($payload)
            : $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function redact(array $payload): array
    {
        if (is_array($payload['checks'] ?? null)) {
            foreach ($payload['checks'] as $index => $check) {
                if (is_array($check) && ($check['name'] ?? null) === self::CHECK && is_array($check['data'] ?? null)) {
                    $payload['checks'][$index]['data'] = self::redactBlock($check['data']);
                }
            }
        }

        if (is_array($payload['operator_metrics'][self::CHECK] ?? null)) {
            $payload['operator_metrics'][self::CHECK] = self::redactBlock($payload['operator_metrics'][self::CHECK]);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private static function redactBlock(array $block): array
    {
        $block['namespaces'] = [];

        if (is_array($block['scope'] ?? null)) {
            $block['scope']['namespaces'] = null;
        }

        if (is_string($block['scope_error'] ?? null)) {
            $block['scope_error'] = 'The configured namespace allowlist is invalid.';
        }

        $block['namespaces_redacted'] = true;

        return $block;
    }
}
