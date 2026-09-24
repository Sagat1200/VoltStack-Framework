<?php

declare(strict_types=1);

namespace Quantum\Facades;

use Quantum\Database\Schema\SchemaManager;

final class Schema extends Facade
{
    protected static function accessor(): string
    {
        return SchemaManager::class;
    }
}
