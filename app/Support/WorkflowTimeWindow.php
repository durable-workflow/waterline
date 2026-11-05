<?php

declare(strict_types=1);

namespace Waterline\Support;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Illuminate\Http\Request;

/**
 * Saved views keep a duration or fixed bounds, never an evaluated rolling range.
 * A list request supplies one clock instant before it starts reading pages.
 */
final class WorkflowTimeWindow
{
    public const FIELDS = ['started_time', 'started_within_seconds', 'started_from', 'started_before'];

    public const MAX_SECONDS = 315360000;

    public static function requestTime(Request $request): DateTimeImmutable
    {
        $key = self::class.'.clock';
        $clock = $request->attributes->get($key);
        if (! $clock instanceof DateTimeImmutable) {
            $clock = DateTimeImmutable::createFromInterface(now())->setTimezone(new DateTimeZone('UTC'));
            $request->attributes->set($key, $clock);
        }

        return $clock;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, int|string>
     */
    public static function normalize(array $filters): array
    {
        $values = array_intersect_key($filters, array_flip(self::FIELDS));
        $values = array_filter($values, static fn (mixed $value): bool => $value !== null
            && (! is_string($value) || trim($value) !== ''));

        if ($values === []) {
            return [];
        }

        $mode = is_string($values['started_time'] ?? null) ? trim($values['started_time']) : null;

        if (! in_array($mode, ['any', 'rolling', 'fixed'], true)) {
            throw new InvalidArgumentException('Choose Any time, Rolling window, or Fixed range for the start time.');
        }

        $hasDuration = array_key_exists('started_within_seconds', $values);
        $hasFrom = array_key_exists('started_from', $values);
        $hasBefore = array_key_exists('started_before', $values);

        if ($mode === 'any') {
            if ($hasDuration || $hasFrom || $hasBefore) {
                throw new InvalidArgumentException('Any time cannot include a duration or fixed bounds.');
            }

            // Preserve an explicit override of a selected saved view's window.
            return ['started_time' => 'any'];
        }

        if ($mode === 'rolling') {
            if ($hasFrom || $hasBefore) {
                throw new InvalidArgumentException('Choose either a rolling window or a fixed range.');
            }

            $seconds = $values['started_within_seconds'] ?? null;
            if ((! is_int($seconds) && ! is_string($seconds))
                || preg_match('/^[1-9][0-9]{0,8}$/D', (string) $seconds) !== 1
                || (int) $seconds > self::MAX_SECONDS) {
                throw new InvalidArgumentException('Enter a whole rolling duration between 1 second and 3650 days.');
            }

            return ['started_time' => 'rolling', 'started_within_seconds' => (int) $seconds];
        }

        if ($hasDuration || ! $hasFrom || ! $hasBefore) {
            throw new InvalidArgumentException('A fixed range needs both bounds and cannot include a rolling duration.');
        }

        $from = self::timestamp($values['started_from']);
        $before = self::timestamp($values['started_before']);

        if ($from >= $before) {
            throw new InvalidArgumentException('The start of the fixed range must be earlier than its end.');
        }

        return ['started_time' => 'fixed', 'started_from' => $from, 'started_before' => $before];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{timestamp: string, mode: string, from: string, before: string, evaluated_at: string}|null
     */
    public static function evaluate(array $filters, DateTimeInterface $at): ?array
    {
        $normalized = self::normalize($filters);
        $mode = $normalized['started_time'] ?? 'any';

        if ($mode === 'any') {
            return null;
        }

        $now = DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'));
        $before = self::format($now);
        $from = $mode === 'rolling'
            ? self::format($now->sub(new DateInterval('PT'.$normalized['started_within_seconds'].'S')))
            : $normalized['started_from'];

        return [
            'timestamp' => 'started_at',
            'mode' => $mode,
            'from' => $from,
            'before' => $mode === 'rolling' ? $before : $normalized['started_before'],
            'evaluated_at' => $before,
        ];
    }

    private static function timestamp(mixed $value): string
    {
        if (! is_string($value) || preg_match(
            '/^(?<date>[0-9]{4}-[0-9]{2}-[0-9]{2})T(?<time>[0-9]{2}:[0-9]{2}:[0-9]{2})(?:\.(?<fraction>[0-9]{1,6}))?(?<offset>Z|[+-](?:[01][0-9]|2[0-3]):[0-5][0-9])$/D',
            trim($value),
            $parts,
        ) !== 1) {
            throw new InvalidArgumentException('Enter valid start-time bounds with explicit UTC offsets.');
        }

        $offset = $parts['offset'] === 'Z' ? '+00:00' : $parts['offset'];
        $input = $parts['date'].'T'.$parts['time'].'.'.str_pad($parts['fraction'] ?? '', 6, '0').$offset;
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $input);
        $errors = DateTimeImmutable::getLastErrors();

        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException('Enter valid start-time bounds with explicit UTC offsets.');
        }

        return self::format($parsed->setTimezone(new DateTimeZone('UTC')));
    }

    private static function format(DateTimeImmutable $date): string
    {
        $year = (int) $date->format('Y');

        if ($year < 1000 || $year > 9999) {
            throw new InvalidArgumentException('Start-time bounds must be between years 1000 and 9999.');
        }

        return $date->format('Y-m-d\TH:i:s.u\Z');
    }
}
