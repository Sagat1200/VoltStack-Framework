<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Policy;

use Quantum\Controllers\Security\Contracts\ControllerSecurityPolicyInterface;
use Quantum\Controllers\Security\Decision\SecurityDecision;
use Quantum\Controllers\Security\Decision\SecurityDecisionEffect;
use Quantum\Controllers\Security\Decision\SecurityEvaluationRequest;

abstract class ControllerSecurityPolicy implements ControllerSecurityPolicyInterface
{
    abstract public function id(): string;

    abstract public function evaluate(SecurityEvaluationRequest $request): SecurityDecision;

    protected function allow(string $reasonCode = 'explicit_allow', array $obligations = []): SecurityDecision
    {
        return SecurityDecision::allow($this->id(), $reasonCode, $obligations);
    }

    protected function deny(string $reasonCode = 'deny_by_policy', array $obligations = []): SecurityDecision
    {
        return SecurityDecision::deny($this->id(), $reasonCode, $obligations);
    }

    protected function abstain(string $reasonCode = 'policy_not_applicable'): SecurityDecision
    {
        return SecurityDecision::abstain($this->id(), $reasonCode);
    }

    protected function challenge(string $reasonCode = 'authentication_required', array $obligations = []): SecurityDecision
    {
        return SecurityDecision::challenge($this->id(), $reasonCode, $obligations);
    }

    protected function effect(SecurityDecisionEffect $effect, string $reasonCode = ''): SecurityDecision
    {
        return new SecurityDecision($effect, $this->id(), $reasonCode, []);
    }
}
