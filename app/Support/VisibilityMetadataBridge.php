<?php

namespace Waterline\Support;

final class VisibilityMetadataBridge
{
    /**
     * Preserve legacy JSON visibility metadata when the resolved workflow package
     * returns an empty typed view for persisted runs or summaries.
     *
     * @return array<string, mixed>
     */
    public static function preserve(mixed $current, mixed ...$legacyCandidates): array
    {
        if (is_array($current) && $current !== []) {
            return $current;
        }

        foreach ($legacyCandidates as $candidate) {
            $decoded = self::decode($candidate);

            if ($decoded !== []) {
                return $decoded;
            }
        }

        return is_array($current) ? $current : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
