<?php

declare(strict_types=1);

namespace Quantum\Database\Factory;

use VoltStack\Framework\Application;

final class FactoryContext
{
    /**
     * @param list<string> $stateNames
     */
    public function __construct(
        private readonly Application $app,
        private readonly FactoryManager $manager,
        private readonly FactoryRandomSource $random,
        private readonly int $index,
        private readonly int $count,
        private readonly array $stateNames,
    ) {
    }

    public function app(): Application
    {
        return $this->app;
    }

    public function factories(): FactoryManager
    {
        return $this->manager;
    }

    public function random(): FactoryRandomSource
    {
        return $this->random;
    }

    public function index(): int
    {
        return $this->index;
    }

    public function count(): int
    {
        return $this->count;
    }

    /**
     * @return list<string>
     */
    public function states(): array
    {
        return $this->stateNames;
    }

    /**
     * @template T
     * @param list<T> $values
     * @return T
     */
    public function sequence(array $values): mixed
    {
        if ($values === []) {
            throw new \InvalidArgumentException('Factory sequence requires at least one value.');
        }

        return $values[$this->index % count($values)];
    }
}
