<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Recovery\RecoveryTransaction;

/**
 * Almacenamiento pluggable de transacciones de recuperación.
 *
 * Cada transacción está ligada a un `tokenId` y persiste el estado de
 * los pasos multi-step para permitir roll-forward en reintentos y
 * rollback parcial explicable.
 */
interface RecoveryTransactionStoreInterface
{
    public function find(string $tokenId): ?RecoveryTransaction;

    public function save(RecoveryTransaction $transaction): void;

    public function delete(string $tokenId): void;

    /**
     * Elimina transacciones cuya fecha de actualización sea anterior a
     * `$now - $ttlSeconds`. Retorna la cantidad eliminada.
     */
    public function deleteExpired(int $ttlSeconds, ?int $now = null): int;
}
