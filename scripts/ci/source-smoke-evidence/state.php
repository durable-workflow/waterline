<?php

declare(strict_types=1);

/** Synthetic harness state only; candidate code has no mount of this volume. */
function sourceFixtureState(): array
{
    $path = '/state/mode.json';
    $before = lstat($path);
    if ($before === false || ($before['mode'] & 0170000) !== 0100000
        || $before['nlink'] !== 1 || $before['size'] > 16384 || realpath($path) !== $path) {
        throw new RuntimeException('Source fixture state refused.');
    }
    $stream = fopen($path, 'rb');
    if ($stream === false) {
        throw new RuntimeException('Source fixture state unavailable.');
    }
    try {
        $opened = fstat($stream);
        $raw = stream_get_contents($stream, 16385);
        $closed = fstat($stream);
        clearstatcache(true, $path);
        $after = lstat($path);
        foreach (['dev','ino','mode','nlink','size','mtime','ctime'] as $field) {
            if ($opened === false || $closed === false || $after === false
                || $before[$field] !== $opened[$field] || $before[$field] !== $closed[$field]
                || $before[$field] !== $after[$field]) {
                throw new RuntimeException('Source fixture state changed.');
            }
        }
        if (!is_string($raw) || strlen($raw) > 16384 || strlen($raw) !== $before['size']) {
            throw new RuntimeException('Source fixture state bound.');
        }
        $value = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new RuntimeException('Source fixture state shape.');
        }
        return $value;
    } finally {
        fclose($stream);
    }
}
