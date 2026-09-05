<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Runtime\AuthenticationOperationContext;

interface AuthenticatorResolverInterface
{
    /**
     * @return list<AuthenticatorInterface>
     */
    public function resolve(AuthenticationOperationContext $context): array;
}
