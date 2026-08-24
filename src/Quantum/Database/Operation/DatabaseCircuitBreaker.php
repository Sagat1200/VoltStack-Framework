<?php

declare(strict_types=1);

namespace Quantum\Database\Operation;

final class DatabaseCircuitBreaker
{
    /**
     * @var array<string, array{state:string,failures:int,opened_at:int|null}>
     */
    private array $segments = [];

    public function assertCanPass(string $segment, int $cooldownMs): string
    {
        $state = $this->segments[$segment] ?? ['state' => 'closed', 'failures' => 0, 'opened_at' => null];

        if ($state['state'] !== 'open') {
            return $state['state'];
        }

        $openedAt = $state['opened_at'] ?? null;
        if (!is_int($openedAt)) {
            $this->segments[$segment] = ['state' => 'half_open', 'failures' => 0, 'opened_at' => null];
            return 'half_open';
        }

        $elapsedMs = (int) ((hrtime(true) - $openedAt) / 1_000_000);
        if ($elapsedMs >= $cooldownMs) {
            $this->segments[$segment] = ['state' => 'half_open', 'failures' => 0, 'opened_at' => null];
            return 'half_open';
        }

        return 'open';
    }

    public function recordSuccess(string $segment): void
    {
        $this->segments[$segment] = ['state' => 'closed', 'failures' => 0, 'opened_at' => null];
    }

    public function recordTransientFailure(string $segment, int $failureThreshold): string
    {
        $state = $this->segments[$segment] ?? ['state' => 'closed', 'failures' => 0, 'opened_at' => null];
        $failures = $state['failures'] + 1;

        if ($state['state'] === 'half_open' || $failures >= $failureThreshold) {
            $this->segments[$segment] = ['state' => 'open', 'failures' => $failures, 'opened_at' => hrtime(true)];
            return 'open';
        }

        $this->segments[$segment] = ['state' => 'closed', 'failures' => $failures, 'opened_at' => null];
        return 'closed';
    }

    public function currentState(string $segment): string
    {
        return $this->segments[$segment]['state'] ?? 'closed';
    }
}
