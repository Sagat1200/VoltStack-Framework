<?php

declare(strict_types=1);

namespace Quantum\Database\Factories;

use Quantum\Database\Contracts\FactoryInterface;
use Quantum\Database\ORM\Contracts\EntityManagerInterface;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use VoltStack\Framework\Application;

/**
 * Base convenience class for entity factories.
 *
 * Concrete factories typically extend this class and implement only the
 * `definition()` + `entityClass()` methods; `make()`/`create()`/`times()`
 * are handled by the base using reflection-based hydration over the same
 * EntityManager scoped instance consumed by the rest of Quantum\Database.
 *
 * The class is intentionally immutable: `times(N)` returns a cloned
 * instance so chained calls do not leak state across invocations.
 */
abstract class AbstractFactory implements FactoryInterface
{
    protected int $count = 1;

    public function __construct(
        protected readonly Application $application,
    ) {
    }

    abstract public function definition(): array;

    /**
     * @return class-string
     */
    abstract public function entityClass(): string;

    public function times(int $count): static
    {
        if ($count < 1) {
            throw new RuntimeException(sprintf('Factory count must be >= 1, got [%d].', $count));
        }

        $clone = clone $this;
        $clone->count = $count;

        return $clone;
    }

    public function make(array $overrides = []): object|array
    {
        $instances = [];

        for ($i = 0; $i < $this->count; $i++) {
            $instances[] = $this->makeSingle($overrides);
        }

        return $this->count === 1 ? $instances[0] : $instances;
    }

    public function create(array $overrides = []): object|array
    {
        $manager = $this->application->make(EntityManagerInterface::class);

        $items = $this->make($overrides);
        $list = is_array($items) ? $items : [$items];

        foreach ($list as $entity) {
            $manager->persist($entity);
        }

        return is_array($items) ? $items : $list[0];
    }

    /**
     * Build a single domain-populated entity instance without persisting.
     *
     * Uses `newInstanceWithoutConstructor()` so entity constructors are not
     * forced to accept zero arguments; every key returned by `definition()`
     * merged with `$overrides` is assigned through a matching reflected
     * property (including non-public ones) exactly as the EntityManager
     * hydration layer does.
     *
     * @param array<string, mixed> $overrides
     */
    private function makeSingle(array $overrides): object
    {
        $class = $this->entityClass();
        $attributes = array_replace($this->definition(), $overrides);

        $reflection = new ReflectionClass($class);
        $instance = $reflection->newInstanceWithoutConstructor();

        foreach ($attributes as $name => $value) {
            if (! $reflection->hasProperty($name)) {
                throw new RuntimeException(sprintf(
                    'Factory for entity [%s] references unknown property [%s].',
                    $class,
                    $name,
                ));
            }

            $property = new ReflectionProperty($class, $name);
            $property->setAccessible(true);
            $property->setValue($instance, $value);
        }

        return $instance;
    }
}
