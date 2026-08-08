<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Policy\Composition;

use Quantum\Controllers\Security\Contracts\ControllerSecurityPolicyInterface;
use Quantum\Controllers\Security\Decision\SecurityDecision;
use Quantum\Controllers\Security\Decision\SecurityDecisionEffect;
use Quantum\Controllers\Security\Decision\SecurityEvaluationRequest;
use Quantum\Controllers\Security\Policy\Composition\CompositePolicy;

final class AllOfPolicy extends CompositePolicy
{
    protected function evaluateChildren(array $children, SecurityEvaluationRequest $request): SecurityDecision
    {
        $permits = 0;
        $denials = [];
        $challenges = [];
        $abstentions = 0;
        $obligations = [];

        foreach ($children as $child) {
            $d = $child->evaluate($request);
            switch ($d->effect) {
                case SecurityDecisionEffect::Deny:
                    $denials[] = $d;
                    break 2;
                case SecurityDecisionEffect::Challenge:
                    $challenges[] = $d;
                    break;
                case SecurityDecisionEffect::Allow:
                    $permits++;
                    $obligations = array_merge($obligations, $d->obligations);
                    break;
                case SecurityDecisionEffect::Abstain:
                default:
                    $abstentions++;
                    break;
            }
        }

        if (count($denials) > 0) {
            $d0 = $denials[0];
            return SecurityDecision::deny(
                policyId: $this->policyId,
                reasonCode: 'all_of_child_denied',
                obligations: array_merge($d0->obligations, [
                    'child_policy_id' => $d0->policyId,
                    'child_reason_code' => $d0->reasonCode,
                ]),
            );
        }
        if (count($challenges) > 0) {
            $c0 = $challenges[0];
            return SecurityDecision::challenge(
                policyId: $this->policyId,
                reasonCode: 'all_of_child_challenge',
                obligations: array_merge($c0->obligations, [
                    'child_policy_id' => $c0->policyId,
                    'child_reason_code' => $c0->reasonCode,
                ]),
            );
        }
        if ($permits === 0) {
            return SecurityDecision::abstain(
                policyId: $this->policyId,
                reasonCode: 'all_of_no_children_applicable',
            );
        }
        if ($permits + $abstentions !== count($children)) {
            return SecurityDecision::abstain(
                policyId: $this->policyId,
                reasonCode: 'all_of_not_all_matched',
                obligations: [
                    'permits' => $permits,
                    'abstentions' => $abstentions,
                    'children' => count($children),
                ],
            );
        }

        return SecurityDecision::allow(
            policyId: $this->policyId,
            reasonCode: 'all_of_all_children_allowed',
            obligations: $obligations,
        );
    }
}
