<?php

declare(strict_types=1);

namespace Waterline\Support\Remote;

/**
 * Elapsed-time source for request budgets.
 *
 * hrtime() never moves backwards and ignores wall-clock and test-clock
 * changes, so a clock step cannot extend a budget. Tests bind a fake through
 * the container.
 */
class MonotonicClock
{
    public function milliseconds(): float
    {
        return hrtime(true) / 1_000_000;
    }
}
