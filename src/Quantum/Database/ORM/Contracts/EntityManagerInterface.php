<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Contracts;

use Quantum\Database\ORM\EntityQuery;
use Quantum\Database\ORM\EntityState;

interface EntityManagerInterface
{
    public function find(string $entityClass, mixed $identifier): ?object;

    /**
     * Return a managed placeholder for the given entity identifier WITHOUT a DB row hit.
     *
     * The returned object is managed by UnitOfWork but its initialization status is
     * Uninitialized. Any flush or data-access operation that requires a snapshot will
     * throw RuntimeException until initializeProxy() or refresh() has been called.
     *
     * If the identifier is already present in the IdentityMap, the existing managed
     * instance (regardless of its initialization state) is returned directly.
     */
    public function getReference(string $entityClass, mixed $identifier): object;

    /**
     * Initialize an Uninitialized proxy placeholder with a real DB row and snapshots.
     *
     * No-op when the object is already Initialized. Throws RuntimeException when the
     * initialization status is Initializing (circular init guard) or when the object
     * is not recognized as a managed placeholder.
     */
    public function initializeProxy(object $placeholder): void;

    public function persist(object $entity): void;

    public function remove(object $entity): void;

    public function refresh(object $entity): void;

    public function flush(): void;

    public function clear(): void;

    public function contains(object $entity): bool;

    public function state(object $entity): EntityState;

    public function repository(string $entityClass): EntityRepositoryInterface;

    public function query(string $entityClass): EntityQuery;
}
