<?php declare(strict_types=1);

namespace Quantum\Database\Orm\UnitOfWork;

/**
 * IdentityMap contract. Contract anticipado del DDD-06 para evitar F5 dependa de F6.
 *
 * El scope es mínima: gestiona lookup por entityClass+idHash para garantizar MAP-001.
 * El estado de UoW (MANAGED, REMOVED, NEW) se implementa en FASE 6.
 */
interface IdentityMapInterface
{
    /**
     * @param class-string $entityClass
     */
    public function has(string $entityClass, string $idHash): bool;

    /**
     * @param class-string $entityClass
     */
    public function get(string $entityClass, string $idHash): ?object;

    /**
     * @param class-string $entityClass
     */
    public function set(string $entityClass, string $idHash, object $entity): void;

    public function detach(string $entityClass, string $idHash): void;

    public function clear(): void;

    /**
     * @return int total managed entities
     */
    public function count(): int;
}
