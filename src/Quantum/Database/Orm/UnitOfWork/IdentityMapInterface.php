<?php declare(strict_types=1);

namespace Quantum\Database\Orm\UnitOfWork;

use Quantum\Database\Orm\UnitOfWork\Enum\EntityState;

/**
 * Identity Map: 1 instancia por EntityManager (no singleton global).
 *
 * Key = idHash de IdentifierExtractor (ya concatena tenantId).
 */
interface IdentityMapInterface
{
    /**
     * @param class-string $entityClass
     */
    public function has(string $entityClass, string $idHash): bool;

    /**
     * @param class-string $entityClass
     *
     * @throws \Quantum\Database\Orm\UnitOfWork\Exception\OrmException si no existe
     */
    public function get(string $entityClass, string $idHash): object;

    /**
     * @param class-string $entityClass
     */
    public function tryGet(string $entityClass, string $idHash): ?object;

    /**
     * @param class-string $entityClass
     */
    public function set(
        string $entityClass,
        string $idHash,
        object $entity,
        EntityState $initialState = EntityState::MANAGED,
    ): void;

    /**
     * @param class-string $entityClass
     */
    public function remove(string $entityClass, string $idHash): void;

    /** @deprecated alias of remove() para backward compat de F5 */
    public function detach(string $entityClass, string $idHash): void;

    /**
     * @return list<object> TODAS las entidades gestionadas en este IM.
     */
    public function all(): array;

    /**
     * @return list<array{class:class-string, id:string, entity:object, state:EntityState}>
     */
    public function allWithState(): array;

    /**
     * @param class-string $entityClass
     */
    public function stateOf(string $entityClass, string $idHash): ?EntityState;

    /**
     * @param class-string $entityClass
     */
    public function setState(string $entityClass, string $idHash, EntityState $state): void;

    /**
     * Vacía el IM (equivalente a EM::clear()).
     *
     * @param class-string|null $entityClass si != null solo vacía de esa clase.
     */
    public function clear(?string $entityClass = null): void;

    public function count(): int;
}
