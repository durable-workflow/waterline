<?php

declare(strict_types=1);

namespace Waterline\Support\Remote;

use DurableWorkflow\Codec\AvroBinaryValue;
use DurableWorkflow\Codec\AvroMapValue;

final class RemotePayloadPreview
{
    public static function display(mixed $value): mixed
    {
        if ($value instanceof AvroBinaryValue) {
            return ['$type' => 'bytes', 'base64' => base64_encode($value->bytes)];
        }
        if ($value instanceof AvroMapValue) {
            return ['$type' => 'map', 'entries' => array_map(
                static fn (array $pair): array => ['key' => $pair[0], 'value' => self::display($pair[1])],
                $value->pairs,
            )];
        }
        if (is_int($value) && ($value > 9007199254740991 || $value < -9007199254740991)) {
            return ['$type' => 'long', 'value' => (string) $value];
        }
        if (is_float($value) && ! is_finite($value)) {
            return ['$type' => 'double', 'value' => is_nan($value) ? 'NaN' : ($value > 0 ? 'Infinity' : '-Infinity')];
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::display($item);
            }
        }

        return $value;
    }
}
