<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Policy\Composition;

use Quantum\Controllers\Security\Contracts\ControllerSecurityPolicyInterface;
use Quantum\Controllers\Security\Decision\SecurityDecision;
use Quantum\Controllers\Security\Decision\SecurityDecisionEffect;
use Quantum\Controllers\Security\Decision\SecurityEvaluationRequest;

final class NotPolicy extends CompositePolicy
{
    protected function evaluateChildren(array $children, SecurityEvaluationRequest $request): SecurityDecision
    {
        \assert(count($children) === 1, 'NotPolicy expects exactly one child');
        $inner = $children[0]->evaluate($request);

        return match ($inner->effect) {
            SecurityDecisionEffect::Allow => SecurityDecision::deny(
                policyId: $this->policyId,
                reasonCode: 'not_child_allowed_inverted_to_deny',
                obligations: [
                    'child_policy_id' => $inner->policyId,
                    'child_reason_code' => $inner->reasonCode,
                ] + $inner->obligations,
            ),
            SecurityDecisionEffect::Deny => SecurityDecision::allow(
                policyId: $this->policyId,
                reasonCode: 'not_child_denied_inverted_to_allow',
                obligations: [
                    'child_policy_id' => $inner->policyId,
                    'child_reason_code' => $inner->reasonCode,
                ] + $inner->obligations,
            ),
            SecurityDecisionEffect::Challenge => SecurityDecision::deny(
                policyId: $this->policyId,
                reasonCode: 'not_child_challenge_treated_as_deny',
                obligations: [
                    'child_policy_id' => $inner->policyId,
                    'child_reason_code' => $inner->reasonCode,
                ] + $inner->obligations,
            ),
            SecurityDecisionEffect::Abstain => SecurityDecision::abstain(
                policyId: $this->policyId,
                reasonCode: 'not_child_abstain_preserved',
            ),
        };
    }
}
