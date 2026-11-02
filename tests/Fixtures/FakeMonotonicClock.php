<?php

declare(strict_types=1);

namespace Waterline\Tests\Fixtures;

use Waterline\Support\Remote\MonotonicClock;

final class FakeMonotonicClock extends MonotonicClock
{
    public float $now = 1_000_000.0;

    public function milliseconds(): float
    {
        return $this->now;
    }

    public function advanceSeconds(float $seconds): void
    {
        $this->now += $seconds * 1000;
    }
}
