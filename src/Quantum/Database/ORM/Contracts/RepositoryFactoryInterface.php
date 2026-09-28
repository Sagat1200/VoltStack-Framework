<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Contracts;

/**
 * Resolves typed entity repositories without forcing the caller to
 * depend on the concrete EntityManager or EntityMetadataRegistry.
 *
 * This contract enables dependency injection of repositories by
 * entity class in application services, handlers, HTTP controllers
 * and CLI commands, keeping consumers decoupled from ORM internals.
 *
 * Lifetime discipline: implementations are scoped because they
 * depend on the currently-scoped EntityManagerInterface (which in
 * turn owns the IdentityMap, UnitOfWork and ConnectionLease for
 * the active request/command scope).
 */
interface RepositoryFactoryInterface
{
    /**
     * Resolve the repository for the given entity class.
     *
     * When a custom repository subclass is declared via the
     * `#[RepositoryFor(EntityClass::class)]` attribute, that
     * concrete class is returned (instantiated with the scoped
     * EntityManager and the canonical EntityMetadata). When no
     * custom repository is registered the default `EntityRepository`
     * implementation is returned, preserving backward compatibility.
     *
     * @param class-string $entityClass
     *
     * @throws \RuntimeException when the class is not a known managed entity.
     */
    public function repositoryFor(string $entityClass): EntityRepositoryInterface;
}
