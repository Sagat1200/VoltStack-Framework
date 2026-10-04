<?php

declare(strict_types=1);

namespace Quantum\Cache;

use Quantum\Cache\Contracts\ClockInterface;

final class SystemClock implements ClockInterface
{
    public function nowUnixSeconds(): int
    {
        return time();
    }

    public function nowUnixMilliseconds(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    public function monotonicNanoseconds(): int
    {
        return hrtime(true);
    }
}
