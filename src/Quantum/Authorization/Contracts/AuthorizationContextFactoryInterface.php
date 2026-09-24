<?php

declare(strict_types=1);

namespace Quantum\Authorization\Contracts;

use Quantum\Authorization\Context\AuthorizationContext;

interface AuthorizationContextFactoryInterface
{
    public function create(?AuthorizationContext $context = null): AuthorizationContext;
}
