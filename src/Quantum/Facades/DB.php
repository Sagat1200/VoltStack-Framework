<?php

declare(strict_types=1);

namespace Quantum\Facades;

use Quantum\Database\Contracts\DatabaseInterface;

final class DB extends Facade
{
    protected static function accessor(): string
    {
        return DatabaseInterface::class;
    }
}
