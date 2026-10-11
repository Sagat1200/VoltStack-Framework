<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

/**
 * Almacén one-time de nonces para FederatedLink evidence.
 *
 * Protege contra replay: un nonce solo puede consumirse una vez.
 * Si el nonce ya fue consumido, `consume()` retorna false y la
 * evidencia federada debe rechazarse.
 */
interface FederatedLinkNonceStoreInterface
{
    /**
     * Intenta consumir un nonce.
     *
     * @return bool true si el nonce era válido y se marcó como consumido;
     *              false si ya había sido consumido o no existe.
     */
    public function consume(string $nonce): bool;

    /**
     * Verifica si un nonce ya fue consumido sin marcarlo.
     */
    public function isConsumed(string $nonce): bool;

    /**
     * Elimina nonces consumidos anteriores al timestamp dado.
     */
    public function purge(int $beforeTimestamp): int;
}
