<?php

declare(strict_types=1);

namespace Quantum\Facades;

use Quantum\Authorization\Contracts\AuthorizationManagerInterface;

final class Authorization extends Facade
{
    protected static function accessor(): string
    {
        return AuthorizationManagerInterface::class;
    }
}
