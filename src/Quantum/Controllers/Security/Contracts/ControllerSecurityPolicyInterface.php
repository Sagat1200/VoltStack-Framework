<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Contracts;

use Quantum\Controllers\Security\Decision\SecurityDecision;
use Quantum\Controllers\Security\Decision\SecurityEvaluationRequest;

interface ControllerSecurityPolicyInterface
{
    public function id(): string;

    public function evaluate(SecurityEvaluationRequest $request): SecurityDecision;
}
