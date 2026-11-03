<?php

declare(strict_types=1);

namespace Waterline\Support;

/**
 * Elapsed-time source for request deadlines.
 *
 * Wall-clock and test-clock changes (NTP steps, manual changes, Carbon test
 * time) must not extend or shorten a deadline, so deadlines read this clock
 * instead of Date::now(). Its value has no meaning on its own; only the
 * difference between two readings is used. Tests bind a fake in the container.
 */
class MonotonicClock
{
    public function milliseconds(): int
    {
        return intdiv(hrtime(true), 1_000_000);
    }
}
