<?php

declare(strict_types=1);

namespace Quantum\Database\ORM;

use Quantum\Database\ORM\Contracts\EntityManagerInterface;
use Quantum\Database\ORM\Contracts\EntityRepositoryInterface;
use Quantum\Database\ORM\Contracts\RepositoryFactoryInterface;
use RuntimeException;

/**
 * Default scoped implementation of RepositoryFactoryInterface.
 *
 * Resolution order for a given entity class:
 *   1. Ask the currently-scoped `EntityManagerInterface::repository()`.
 *      The EntityManager internally consults the canonical
 *      `EntityMetadata::$repositoryClass` (populated either from
 *      `#[Entity(repository: X)]` on the entity itself, or injected
 *      via `CustomRepositoryRegistry` when wired by the provider).
 *   2. If EntityManager returns a concrete `EntityRepositoryInterface`
 *      implementation, return it (cached per scoped EM instance).
 *   3. Otherwise bubble RuntimeException from underlying layers.
 *
 * Because this factory depends on the scoped EntityManager, it MUST
 * be registered as a SCOPED service (never a process singleton) so
 * FrankenPHP / RoadRunner workers get a freshly wired factory per
 * request / CLI command scope.
 */
final class EntityRepositoryFactory implements RepositoryFactoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function repositoryFor(string $entityClass): EntityRepositoryInterface
    {
        if (! class_exists($entityClass)) {
            throw new RuntimeException(sprintf(
                'Cannot resolve repository for unknown entity class [%s].',
                $entityClass,
            ));
        }

        return $this->entityManager->repository($entityClass);
    }
}
