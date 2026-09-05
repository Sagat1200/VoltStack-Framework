<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Decisions\AuthenticationDecision;
use Quantum\Auth\Runtime\AuthenticationOperationContext;

interface AuthenticatorInterface
{
    public function supports(AuthenticationOperationContext $context): bool;

    public function authenticate(AuthenticationOperationContext $context): AuthenticationDecision;
}
