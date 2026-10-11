<?php

declare(strict_types=1);

namespace Quantum\Authorization\RateLimit;

use Quantum\Authorization\Contracts\RateLimitStoreInterface;

/**
 * Implementacion in-memory (scoped) de RateLimitStoreInterface.
 *
 * Los contadores viven en memoria y se reinician con el ciclo de vida
 * del contenedor. No es compartida entre workers.
 */
final class InMemoryRateLimitStore implements RateLimitStoreInterface
{
    /**
     * @var array<string, array{count:int,window_start:int}>
     */
    private array $counters = [];

    public function increment(string $key, int $windowSeconds): int
    {
        $now = time();
        $current = $this->counters[$key] ?? null;

        if ($current === null || ($now - $current['window_start']) >= $windowSeconds) {
            $this->counters[$key] = ['count' => 1, 'window_start' => $now];

            return 1;
        }

        $this->counters[$key]['count']++;

        return $this->counters[$key]['count'];
    }

    public function reset(string $key): void
    {
        unset($this->counters[$key]);
    }
}
