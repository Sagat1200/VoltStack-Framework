<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Policy\Composition;

use Quantum\Controllers\Security\Contracts\ControllerSecurityPolicyInterface;
use Quantum\Controllers\Security\Decision\SecurityDecision;
use Quantum\Controllers\Security\Decision\SecurityDecisionEffect;
use Quantum\Controllers\Security\Decision\SecurityEvaluationRequest;

final class AnyOfPolicy extends CompositePolicy
{
    protected function evaluateChildren(array $children, SecurityEvaluationRequest $request): SecurityDecision
    {
        $permits = 0;
        $denials = [];
        $challenges = [];
        $abstentions = 0;
        $allowObligations = [];

        foreach ($children as $child) {
            $d = $child->evaluate($request);
            switch ($d->effect) {
                case SecurityDecisionEffect::Allow:
                    $permits++;
                    $allowObligations = array_merge($allowObligations, $d->obligations);
                    break 2;
                case SecurityDecisionEffect::Challenge:
                    $challenges[] = $d;
                    break;
                case SecurityDecisionEffect::Deny:
                    $denials[] = $d;
                    break;
                case SecurityDecisionEffect::Abstain:
                default:
                    $abstentions++;
                    break;
            }
        }

        if ($permits > 0) {
            return SecurityDecision::allow(
                policyId: $this->policyId,
                reasonCode: 'any_of_child_allowed',
                obligations: $allowObligations,
            );
        }
        if (count($challenges) > 0) {
            $c0 = $challenges[0];
            return SecurityDecision::challenge(
                policyId: $this->policyId,
                reasonCode: 'any_of_first_challenge',
                obligations: array_merge($c0->obligations, [
                    'child_policy_id' => $c0->policyId,
                    'child_reason_code' => $c0->reasonCode,
                ]),
            );
        }
        if (count($denials) > 0 && $abstentions === 0) {
            $d0 = $denials[0];
            return SecurityDecision::deny(
                policyId: $this->policyId,
                reasonCode: 'any_of_all_children_denied',
                obligations: array_merge($d0->obligations, [
                    'child_policy_id' => $d0->policyId,
                    'child_reason_code' => $d0->reasonCode,
                ]),
            );
        }
        return SecurityDecision::abstain(
            policyId: $this->policyId,
            reasonCode: 'any_of_no_decision',
            obligations: [
                'denials' => count($denials),
                'abstentions' => $abstentions,
                'children' => count($children),
            ],
        );
    }
}
