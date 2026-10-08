<?php

declare(strict_types=1);

namespace Quantum\Auth\AbuseProtection;

use Quantum\Auth\Contracts\DistributedThrottleCounterInterface;

final class BruteForceCounter
{
    public const WINDOW_1M = 60;
    public const WINDOW_5M = 300;
    public const WINDOW_15M = 900;

    /**
     * @var array<string, list<int>>
     */
    private array $buckets = [];

    public function __construct(
        private readonly ?DistributedThrottleCounterInterface $distributed = null,
    ) {
    }

    /**
     * @return array{1m: int, 5m: int, 15m: int}
     */
    public function currentCounts(string $key, ?int $nowTs = null): array
    {
        if ($this->distributed instanceof DistributedThrottleCounterInterface) {
            return $this->currentCountsDistributed($key, $nowTs);
        }

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
        if ($this->distributed instanceof DistributedThrottleCounterInterface) {
            $now = $nowTs ?? time();
            $this->distributed->increment($this->distributedBucketKey($key, '1m'), self::WINDOW_1M, $now);
            $this->distributed->increment($this->distributedBucketKey($key, '5m'), self::WINDOW_5M, $now);
            $this->distributed->increment($this->distributedBucketKey($key, '15m'), self::WINDOW_15M, $now);

            return;
        }

        $now = $nowTs ?? time();
        if (! isset($this->buckets[$key])) {
            $this->buckets[$key] = [];
        }
        $this->buckets[$key][] = $now;
        $this->purgeExpired($key, $now);
    }

    public function reset(string $key): void
    {
        if ($this->distributed instanceof DistributedThrottleCounterInterface) {
            $this->distributed->reset($this->distributedBucketKey($key, '1m'));
            $this->distributed->reset($this->distributedBucketKey($key, '5m'));
            $this->distributed->reset($this->distributedBucketKey($key, '15m'));

            return;
        }

        unset($this->buckets[$key]);
    }

    /**
     * @return array{1m: int, 5m: int, 15m: int}
     */
    private function currentCountsDistributed(string $key, ?int $nowTs = null): array
    {
        $now = $nowTs ?? time();

        return [
            '1m' => $this->distributed->currentCount($this->distributedBucketKey($key, '1m'), $now),
            '5m' => $this->distributed->currentCount($this->distributedBucketKey($key, '5m'), $now),
            '15m' => $this->distributed->currentCount($this->distributedBucketKey($key, '15m'), $now),
        ];
    }

    private function distributedBucketKey(string $key, string $window): string
    {
        return $key . '|window:' . $window;
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
