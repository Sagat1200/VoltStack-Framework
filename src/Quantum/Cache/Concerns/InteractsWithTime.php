<?php

declare(strict_types=1);

namespace Quantum\Cache\Concerns;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Quantum\Cache\Contracts\ClockInterface;
use Quantum\Cache\SystemClock;

trait InteractsWithTime
{
    protected readonly ClockInterface $clock;

    private function cacheClock(): ClockInterface
    {
        return $this->clock ?? new SystemClock();
    }

    protected function expirationTimestamp(DateInterval|DateTimeInterface|int|null $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if ($ttl instanceof DateInterval) {
            return (new DateTimeImmutable('@' . $this->cacheClock()->nowUnixSeconds()))->add($ttl)->getTimestamp();
        }

        if ($ttl instanceof DateTimeInterface) {
            return $ttl->getTimestamp();
        }

        return $this->cacheClock()->nowUnixSeconds() + $ttl;
    }

    protected function nowUnixSeconds(): int
    {
        return $this->cacheClock()->nowUnixSeconds();
    }

    protected function nowUnixMilliseconds(): int
    {
        return $this->cacheClock()->nowUnixMilliseconds();
    }
}
