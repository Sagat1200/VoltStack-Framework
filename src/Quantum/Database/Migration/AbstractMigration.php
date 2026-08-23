<?php

declare(strict_types=1);

namespace Quantum\Database\Migration;

abstract class AbstractMigration implements MigrationInterface
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
