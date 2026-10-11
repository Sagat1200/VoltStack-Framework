<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

use Quantum\Auth\Contracts\RecoveryTransactionStoreInterface;

/**
 * Driver en memoria para transacciones de recuperación.
 *
 * Útil para tests y entornos efímeros. Las transacciones se indexan
 * por `tokenId`.
 */
final class InMemoryRecoveryTransactionStore implements RecoveryTransactionStoreInterface
{
    /**
     * @var array<string, RecoveryTransaction>
     */
    private array $transactions = [];

    public function find(string $tokenId): ?RecoveryTransaction
    {
        return $this->transactions[$tokenId] ?? null;
    }

    public function save(RecoveryTransaction $transaction): void
    {
        $this->transactions[$transaction->tokenId] = $transaction;
    }

    public function delete(string $tokenId): void
    {
        unset($this->transactions[$tokenId]);
    }

    public function deleteExpired(int $ttlSeconds, ?int $now = null): int
    {
        $now ??= time();
        $removed = 0;

        foreach ($this->transactions as $tokenId => $transaction) {
            if ($transaction->updatedAt > 0 && $now - $transaction->updatedAt > $ttlSeconds) {
                unset($this->transactions[$tokenId]);
                $removed++;
            }
        }

        return $removed;
    }
}
