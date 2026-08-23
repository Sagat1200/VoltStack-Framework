<?php

declare(strict_types=1);

namespace Quantum\Database\Seeder;

interface SeederInterface
{
    public function name(): string;

    public function description(): string;

    public function isTransactional(): bool;

    public function run(SeedExecutionContext $context): void;
}