<?php

declare(strict_types=1);

namespace Quantum\Authorization\Contracts;

use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\Context\AuthorizationContext;

interface TenantScopeResolverInterface
{
    public function normalize(AuthorizationContext $context): AuthorizationContext;

    public function resolveScope(?AuthorizationContext $context): Scope;
}
