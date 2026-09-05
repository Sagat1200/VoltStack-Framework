<?php

declare(strict_types=1);

namespace Quantum\Facades;

use Quantum\Auth\Contracts\AuthenticationManagerInterface;

final class Auth extends Facade
{
    protected static function accessor(): string
    {
        return AuthenticationManagerInterface::class;
    }
}
