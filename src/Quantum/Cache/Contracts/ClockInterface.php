<?php

declare(strict_types=1);

namespace Quantum\Cache\Contracts;

interface ClockInterface
{
    public function nowUnixSeconds(): int;

    public function nowUnixMilliseconds(): int;

    public function monotonicNanoseconds(): int;
}
