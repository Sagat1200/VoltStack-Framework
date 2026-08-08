<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Policy\Composition;

use Quantum\Controllers\Security\Contracts\ControllerSecurityPolicyInterface;
use Quantum\Controllers\Security\Decision\SecurityDecision;
use Quantum\Controllers\Security\Decision\SecurityEvaluationRequest;

final class NullCompositePolicy implements ControllerSecurityPolicyInterface
{
    public function __construct(
        private readonly string $policyId,
    ) {}

    public function id(): string
    {
        return $this->policyId;
    }

    public function evaluate(SecurityEvaluationRequest $request): SecurityDecision
    {
        return SecurityDecision::abstain($this->policyId, 'null_composite_abstain');
    }
}
