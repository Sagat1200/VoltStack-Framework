<?php

declare(strict_types=1);

namespace Quantum\Database\ORM;

use Quantum\Database\ORM\Attributes\RepositoryFor;
use Quantum\Database\ORM\Contracts\EntityRepositoryInterface;
use ReflectionClass;
use RuntimeException;

/**
 * Singleton-safe registry of custom entity repositories.
 *
 * Two registration conventions are supported:
 *   1. Declarative via `#[RepositoryFor(EntityClass::class)]` attribute
 *      placed on the custom repository class.
 *   2. Explicit programmatic registration via `register()`, useful for
 *      compiled manifests, app bootstrappers or test fixtures that do
 *      not want to rely on attribute discovery alone.
 *
 * This registry is consulted by `EntityMetadataRegistry` (if wired in
 * the service provider) so both `#[Entity(repository: X)]` on the
 * entity class and `#[RepositoryFor(Entity)]` on the repository class
 * produce equivalent canonical behavior on `EntityManager::repository()`
 * and `RepositoryFactoryInterface::repositoryFor()`.
 */
final class CustomRepositoryRegistry
{
    /**
     * @var array<class-string, class-string<EntityRepositoryInterface>>
     */
    private array $entityToRepository = [];

    /**
     * @param iterable<class-string<EntityRepositoryInterface>> $repositoryClasses
     */
    public function __construct(iterable $repositoryClasses = [])
    {
        foreach ($repositoryClasses as $repositoryClass) {
            $this->register($repositoryClass);
        }
    }

    /**
     * Register a repository class.
     *
     * If the class carries a `#[RepositoryFor]` attribute, the target
     * entity is read from it. Otherwise the registration is rejected
     * unless a target entity is explicitly provided via `$entityClass`.
     *
     * @param class-string<EntityRepositoryInterface> $repositoryClass
     * @param class-string|null                       $entityClass     Optional override when not using `#[RepositoryFor]`.
     *
     * @throws RuntimeException when no entity mapping can be resolved
     *                          or a duplicate entity binding is found.
     */
    public function register(string $repositoryClass, ?string $entityClass = null): void
    {
        if (! class_exists($repositoryClass)) {
            throw new RuntimeException(sprintf(
                'Repository class [%s] does not exist.',
                $repositoryClass,
            ));
        }

        if (! is_subclass_of($repositoryClass, EntityRepositoryInterface::class)) {
            throw new RuntimeException(sprintf(
                'Repository class [%s] must implement [%s].',
                $repositoryClass,
                EntityRepositoryInterface::class,
            ));
        }

        $targetEntity = $entityClass ?? $this->resolveEntityFromAttribute($repositoryClass);

        if ($targetEntity === null) {
            throw new RuntimeException(sprintf(
                'Repository class [%s] must declare #[RepositoryFor(EntityClass::class)] or pass an explicit $entityClass.',
                $repositoryClass,
            ));
        }

        if (isset($this->entityToRepository[$targetEntity])) {
            throw new RuntimeException(sprintf(
                'Entity [%s] already has a custom repository registered [%s]. Duplicate registration for [%s].',
                $targetEntity,
                $this->entityToRepository[$targetEntity],
                $repositoryClass,
            ));
        }

        $this->entityToRepository[$targetEntity] = $repositoryClass;
    }

    /**
     * Return the custom repository class for the given entity, or NULL
     * when none was registered.
     *
     * @param class-string $entityClass
     *
     * @return class-string<EntityRepositoryInterface>|null
     */
    public function repositoryFor(string $entityClass): ?string
    {
        return $this->entityToRepository[$entityClass] ?? null;
    }

    /**
     * @return array<class-string, class-string<EntityRepositoryInterface>>
     */
    public function all(): array
    {
        return $this->entityToRepository;
    }

    /**
     * @param class-string<EntityRepositoryInterface> $repositoryClass
     *
     * @return class-string|null
     */
    private function resolveEntityFromAttribute(string $repositoryClass): ?string
    {
        $reflection = new ReflectionClass($repositoryClass);
        $attributes = $reflection->getAttributes(RepositoryFor::class);

        if ($attributes === []) {
            return null;
        }

        /** @var RepositoryFor $instance */
        $instance = $attributes[0]->newInstance();

        return $instance->entityClass;
    }
}
