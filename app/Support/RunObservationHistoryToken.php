<?php

namespace Waterline\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Throwable;

final class RunObservationHistoryToken
{
    public static function encode(string $runId, int $after, int $through): string
    {
        return Crypt::encryptString(json_encode([
            'version' => 1, 'run_id' => $runId, 'after' => $after, 'through' => $through,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{after: int, through: int}|null */
    public static function decode(?string $token, string $runId): ?array
    {
        if ($token === null) {
            return null;
        }
        try {
            $value = json_decode(Crypt::decryptString($token), true, 8, JSON_THROW_ON_ERROR);
            if (is_array($value) && ($value['version'] ?? null) === 1 && ($value['run_id'] ?? null) === $runId
                && is_int($value['after'] ?? null) && $value['after'] >= 0
                && is_int($value['through'] ?? null) && $value['through'] >= $value['after']) {
                return ['after' => $value['after'], 'through' => $value['through']];
            }
        } catch (Throwable) {
        }

        throw ValidationException::withMessages(['history_page_token' => 'Invalid history cursor for this run.']);
    }
}
