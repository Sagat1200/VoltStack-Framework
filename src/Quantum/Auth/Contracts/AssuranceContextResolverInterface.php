<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Runtime\AuthenticationOperationContext;

interface AssuranceContextResolverInterface
{
    /**
     * @return array{current_assurance:int, current_assurance_name:?string}
     */
    public function resolve(AuthenticationOperationContext $context): array;
}
