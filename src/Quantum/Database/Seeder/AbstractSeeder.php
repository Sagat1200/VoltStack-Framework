<?php

declare(strict_types=1);

namespace Quantum\Database\Seeder;

abstract class AbstractSeeder implements SeederInterface
{
    public function description(): string
    {
        return static::class;
    }

    public function isTransactional(): bool
    {
        return true;
    }
}
