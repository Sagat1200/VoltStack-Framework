<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Runtime\AuthenticationOperationContext;

interface OperationAssurancePolicyInterface
{
    public function minimumAssuranceFor(AuthenticationOperationContext $context): ?int;
}
