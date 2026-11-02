<?php

declare(strict_types=1);

namespace Waterline\Support\Remote;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Waterline\Support\RequestTimeouts;

/**
 * Bounds page-number navigation over the standalone server's cursor pagination.
 *
 * The server only hands out opaque next-page tokens, so showing page N costs N
 * sequential list calls. One Waterline request makes at most MAX_PAGE calls and
 * starts no further call once the replay budget has elapsed. The replay budget
 * ends RESPONSE_MARGIN_MS before the browser request budget, so the refusal
 * normally reaches the browser before it gives up. The per-call SDK timeout
 * still bounds the call already in flight. Elapsed time comes from the
 * monotonic clock, so a wall-clock step cannot extend the replay.
 */
final class CursorPageReplay
{
    public const MAX_PAGE = 100;

    public const RESPONSE_MARGIN_MS = 5000;

    public static function requestedPage(Request $request): int
    {
        $raw = $request->query('page');

        if ($raw === null || $raw === '') {
            return 1;
        }

        if (! is_string($raw) || preg_match('/^[1-9]\d{0,8}$/', $raw) !== 1) {
            self::refuse(422, 'invalid_page', 'The page must be a positive whole number.');
        }

        $page = (int) $raw;

        if ($page > self::MAX_PAGE) {
            self::refuse(422, 'page_beyond_replay_limit', sprintf(
                'Service mode can show at most %d pages of a list; narrow the filters to reach later rows.',
                self::MAX_PAGE,
            ));
        }

        return $page;
    }

    /**
     * Fetches pages 1..$requestedPage, stopping early when the server has no
     * further page.
     *
     * @template TPage of object
     *
     * @param  callable(?string): TPage  $fetch  receives the next-page token and returns a page with a nullable nextPageToken
     * @return array{page: TPage, page_number: int, next_page_token: ?string, page_limit_reached: bool}
     */
    public static function replay(int $requestedPage, callable $fetch): array
    {
        $requestedPage = max(1, min($requestedPage, self::MAX_PAGE));
        $clock = app(MonotonicClock::class);
        $deadline = $clock->milliseconds() + self::budgetMilliseconds();
        $token = null;
        $page = null;
        $current = 0;

        while ($current < $requestedPage) {
            if ($current > 0 && $clock->milliseconds() >= $deadline) {
                self::refuse(504, 'page_replay_budget_exceeded', sprintf(
                    'Waterline stopped after reading %d of %d server pages because the request budget elapsed. Retry, or narrow the filters.',
                    $current,
                    $requestedPage,
                ), ['pages_read' => $current, 'requested_page' => $requestedPage]);
            }

            $page = $fetch($token);
            $current++;
            $token = self::nextToken($page);

            if ($token === null) {
                break;
            }
        }

        return [
            'page' => $page,
            'page_number' => $current,
            'next_page_token' => $token,
            'page_limit_reached' => $token !== null && $current >= self::MAX_PAGE,
        ];
    }

    /**
     * The browser request budget minus a response margin, never less than
     * half of the browser budget.
     */
    public static function budgetMilliseconds(): int
    {
        $browser = RequestTimeouts::browserMilliseconds();

        return max(intdiv($browser, 2), $browser - self::RESPONSE_MARGIN_MS);
    }

    private static function nextToken(object $page): ?string
    {
        $token = $page->nextPageToken ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    /** @param array<string, mixed> $extra */
    private static function refuse(int $status, string $reason, string $message, array $extra = []): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message,
            'error' => $status === 504 ? 'remote_page_replay_timeout' : 'invalid_pagination',
            'reason' => $reason,
            'max_page' => self::MAX_PAGE,
            ...$extra,
        ], $status));
    }
}
