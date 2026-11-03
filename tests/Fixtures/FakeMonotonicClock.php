<?php

declare(strict_types=1);

namespace Waterline\Tests\Fixtures;

use Waterline\Support\MonotonicClock;

final class FakeMonotonicClock extends MonotonicClock
{
    public function __construct(private int $milliseconds = 0)
    {
    }

    public function milliseconds(): int
    {
        return $this->milliseconds;
    }

    public function advanceSeconds(int $seconds): void
    {
        $this->milliseconds += $seconds * 1000;
    }
}
