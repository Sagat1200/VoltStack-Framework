<?php

declare(strict_types=1);

namespace Quantum\Database\Factory;

final class FactoryBuilder
{
    /** @var list<string|callable(array<string,mixed>,FactoryContext):array<string,mixed>> */
    private array $states = [];

    private int $count = 1;
    private ?int $seed = null;

    public function __construct(
        private readonly FactoryManager $manager,
        private readonly FactoryInterface $factory,
    ) {
    }

    public function seed(int $seed): self
    {
        $clone = clone $this;
        $clone->seed = $seed;
        return $clone;
    }

    public function count(int $count): self
    {
        $clone = clone $this;
        $clone->count = max(1, $count);
        return $clone;
    }

    /**
     * @param string|callable(array<string,mixed>,FactoryContext):array<string,mixed> $state
     */
    public function state(string|callable $state): self
    {
        $clone = clone $this;
        $clone->states[] = $state;
        return $clone;
    }

    public function makeOne(): object
    {
        return $this->make()[0];
    }

    /**
     * @return list<object>
     */
    public function make(): array
    {
        $random = new FactoryRandomSource($this->seed ?? $this->manager->defaultSeed());
        $stateNames = array_values(array_filter($this->states, 'is_string'));
        $entities = [];

        for ($index = 0; $index < $this->count; $index++) {
            $context = new FactoryContext(
                app: $this->manager->app(),
                manager: $this->manager,
                random: $random,
                index: $index,
                count: $this->count,
                stateNames: $stateNames,
            );

            $attributes = $this->factory->definition($context);
            $attributes = $this->applyStates($attributes, $context);
            $entities[] = $this->factory->instantiate($attributes, $context);
        }

        return $entities;
    }

    public function createOne(): object
    {
        return $this->create()[0];
    }

    /**
     * @return list<object>
     */
    public function create(): array
    {
        $entities = $this->make();
        $this->manager->persist($entities);
        return $entities;
    }

    /**
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    private function applyStates(array $attributes, FactoryContext $context): array
    {
        foreach ($this->states as $state) {
            if (is_string($state)) {
                $namedStates = $this->factory->states();
                $callback = $namedStates[$state] ?? null;

                if (!is_callable($callback)) {
                    throw new \RuntimeException(sprintf(
                        'Factory [%s] does not define state [%s].',
                        $this->factory::class,
                        $state,
                    ));
                }

                $attributes = $callback($attributes, $context);
                continue;
            }

            $attributes = $state($attributes, $context);
        }

        return $attributes;
    }
}
