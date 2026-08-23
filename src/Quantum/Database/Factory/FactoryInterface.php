<?php

declare(strict_types=1);

namespace Quantum\Database\Factory;

interface FactoryInterface
{
    public function name(): string;

    public function description(): string;

    public function entityClass(): string;

    /**
     * @return array<string,mixed>
     */
    public function definition(FactoryContext $context): array;

    /**
     * @return array<string,callable(array<string,mixed>,FactoryContext):array<string,mixed>>
     */
    public function states(): array;

    /**
     * @param array<string,mixed> $attributes
     */
    public function instantiate(array $attributes, FactoryContext $context): object;
}
