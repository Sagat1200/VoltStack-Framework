<?php

declare(strict_types=1);

namespace Quantum\Authorization\Contracts;

interface PrincipalResolverInterface
{
    public function resolve(mixed $principal = null): PrincipalInterface;
}
