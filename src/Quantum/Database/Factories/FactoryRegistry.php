<?php

declare(strict_types=1);

namespace Quantum\Database\Factories;

use Quantum\Database\Contracts\FactoryInterface;
use RuntimeException;

/**
 * Registry that indexes discovered factories by the entity FQCN they build.
 *
 * Typical usage:
 *   `$registry->for(Product::class)->times(10)->create();`
 *
 * The first access to `for()` triggers eager discovery of the default
 * factories directory (or an explicitly passed path via `discoverFrom()`).
 *
 * Implementations MUST be safe for singleton registration: discovery is
 * performed once and cached, and no request-scoped mutable state is kept
 * on the registry itself (the factory instances are immutable and receive
 * the scoped EntityManager through the Application at `create()` time via
 * their inner make/EntityManagerInterface resolution).
 */
final class FactoryRegistry
{
    /** @var array<string, FactoryInterface> map entityClass => factory */
    private array $byEntity = [];

    private bool $discovered = false;

    public function __construct(
        private readonly FactoryDiscovery $discovery,
    ) {
    }

    /**
     * Force discovery of factories from an explicit path (or the default
     * one) so subsequent `for()` calls see them.
     *
     * Safe to call multiple times; duplicate entries for the same entity
     * raise an exception (configuration error).
     */
    public function discoverFrom(?string $path = null): void
    {
        foreach ($this->discovery->discover($path) as $discovered) {
            $entity = $discovered->instance->entityClass();

            if (isset($this->byEntity[$entity])) {
                throw new RuntimeException(sprintf(
                    'Duplicate factory registration for entity [%s]; existing [%s], new [%s].',
                    $entity,
                    $this->byEntity[$entity]::class,
                    $discovered->instance::class,
                ));
            }

            $this->byEntity[$entity] = $discovered->instance;
        }

        $this->discovered = true;
    }

    /**
     * Explicitly register a factory instance for an entity (useful in tests
     * that do not rely on file-based discovery).
     *
     * @param class-string $entityClass
     */
    public function register(string $entityClass, FactoryInterface $factory): void
    {
        if (isset($this->byEntity[$entityClass])) {
            throw new RuntimeException(sprintf(
                'A factory for entity [%s] is already registered.',
                $entityClass,
            ));
        }

        $this->byEntity[$entityClass] = $factory;
    }

    /**
     * Resolve a factory for the given entity FQCN.
     *
     * Performs eager discovery on the default path the first time it is
     * invoked so callers do not need an explicit bootstrap step.
     *
     * @param class-string $entityClass
     */
    public function for(string $entityClass): FactoryInterface
    {
        if (! $this->discovered) {
            $this->discoverFrom();
        }

        if (! isset($this->byEntity[$entityClass])) {
            throw new RuntimeException(sprintf(
                'No factory is registered for entity [%s]. Make sure a factory file exists under [%s] and declares entityClass() = [%s].',
                $entityClass,
                $this->discovery->defaultPath(),
                $entityClass,
            ));
        }

        return $this->byEntity[$entityClass];
    }

    /**
     * Expose all currently-registered entity FQCN => factory pairs.
     *
     * @return array<string, FactoryInterface>
     */
    public function all(): array
    {
        if (! $this->discovered) {
            $this->discoverFrom();
        }

        return $this->byEntity;
    }
}
