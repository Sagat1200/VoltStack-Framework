<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Decisions\AuthenticationDecision;
use Quantum\Auth\Runtime\AuthenticationOperationContext;

interface AuthenticationOrchestratorInterface
{
    public function execute(AuthenticationOperationContext $context): AuthenticationDecision;
}
