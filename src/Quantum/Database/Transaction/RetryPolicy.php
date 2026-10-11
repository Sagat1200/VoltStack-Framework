<?php

declare(strict_types=1);

namespace Quantum\Database\Transaction;

/**
 * Immutable retry policy for transactional units.
 *
 * Retry always applies to the whole transactional unit (the callback passed to
 * TransactionManager::transaction), never to individual statements.
 */
final readonly class RetryPolicy
{
    public function __construct(
        public int $maxAttempts = 3,
        public int $initialDelayMs = 50,
        public int $maxDelayMs = 1000,
        public float $backoffMultiplier = 2.0,
        public bool $jitter = true,
    ) {
        if ($this->maxAttempts < 1) {
            throw new TransactionException('RetryPolicy maxAttempts must be at least 1.');
        }

        if ($this->initialDelayMs < 0) {
            throw new TransactionException('RetryPolicy initialDelayMs must be non-negative.');
        }

        if ($this->maxDelayMs < $this->initialDelayMs) {
            throw new TransactionException('RetryPolicy maxDelayMs must be greater than or equal to initialDelayMs.');
        }

        if ($this->backoffMultiplier < 1.0) {
            throw new TransactionException('RetryPolicy backoffMultiplier must be at least 1.0.');
        }
    }

    public static function defaults(): self
    {
        return new self();
    }

    /**
     * Compute the delay (in milliseconds) before the given attempt number.
     *
     * Attempt numbers are 1-based: delayForAttempt(1) is the wait before the
     * second attempt. The delay grows exponentially and is capped by maxDelayMs.
     * When jitter is enabled, a random fraction of the computed delay is added.
     */
    public function delayForAttempt(int $attempt): int
    {
        if ($attempt < 1) {
            $attempt = 1;
        }

        $delay = (int) ($this->initialDelayMs * ($this->backoffMultiplier ** ($attempt - 1)));

        if ($delay > $this->maxDelayMs) {
            $delay = $this->maxDelayMs;
        }

        if ($this->jitter && $delay > 0) {
            $delay += random_int(0, (int) ($delay * 0.25));
        }

        return $delay;
    }
}
