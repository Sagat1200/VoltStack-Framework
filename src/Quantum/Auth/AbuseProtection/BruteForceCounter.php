<?php

declare(strict_types=1);

namespace Quantum\Auth\AbuseProtection;

final class BruteForceCounter
{
    public const WINDOW_1M = 60;
    public const WINDOW_5M = 300;
    public const WINDOW_15M = 900;

    /**
     * @var array<string, list<int>>
     */
    private array $buckets = [];

    /**
     * @return array{1m: int, 5m: int, 15m: int}
     */
    public function currentCounts(string $key, ?int $nowTs = null): array
    {
        $now = $nowTs ?? time();
        $this->purgeExpired($key, $now);

        $timestamps = $this->buckets[$key] ?? [];
        $count1m = 0;
        $count5m = 0;
        $count15m = 0;

        foreach ($timestamps as $ts) {
            if ($ts >= $now - self::WINDOW_1M) {
                $count1m++;
            }
            if ($ts >= $now - self::WINDOW_5M) {
                $count5m++;
            }
            if ($ts >= $now - self::WINDOW_15M) {
                $count15m++;
            }
        }

        return [
            '1m' => $count1m,
            '5m' => $count5m,
            '15m' => $count15m,
        ];
    }

    public function increment(string $key, ?int $nowTs = null): void
    {
        $now = $nowTs ?? time();
        if (! isset($this->buckets[$key])) {
            $this->buckets[$key] = [];
        }
        $this->buckets[$key][] = $now;
        $this->purgeExpired($key, $now);
    }

    public function reset(string $key): void
    {
        unset($this->buckets[$key]);
    }

    private function purgeExpired(string $key, int $nowTs): void
    {
        if (! isset($this->buckets[$key])) {
            return;
        }
        $cutoff = $nowTs - self::WINDOW_15M;
        $this->buckets[$key] = array_values(array_filter(
            $this->buckets[$key],
            static fn (int $ts): bool => $ts >= $cutoff,
        ));
    }
}
