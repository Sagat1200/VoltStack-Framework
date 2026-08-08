<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Policy\Composition;

use Quantum\Controllers\Security\Contracts\ControllerSecurityPolicyInterface;
use Quantum\Controllers\Security\Decision\SecurityDecision;
use Quantum\Controllers\Security\Decision\SecurityDecisionEffect;
use Quantum\Controllers\Security\Decision\SecurityEvaluationRequest;

final class AtLeastOnePolicy extends CompositePolicy
{
    public function __construct(
        string $policyId,
        array $children,
        private readonly int $minimum = 1,
    ) {
        parent::__construct($policyId, $children);
    }

    protected function evaluateChildren(array $children, SecurityEvaluationRequest $request): SecurityDecision
    {
        $permits = 0;
        $denials = [];
        $challenges = [];
        $obligations = [];

        foreach ($children as $child) {
            $d = $child->evaluate($request);
            switch ($d->effect) {
                case SecurityDecisionEffect::Allow:
                    $permits++;
                    $obligations = array_merge($obligations, $d->obligations);
                    break;
                case SecurityDecisionEffect::Challenge:
                    $challenges[] = $d;
                    break;
                case SecurityDecisionEffect::Deny:
                    $denials[] = $d;
                    break;
                case SecurityDecisionEffect::Abstain:
                default:
                    break;
            }
        }

        if ($permits >= $this->minimum) {
            return SecurityDecision::allow(
                policyId: $this->policyId,
                reasonCode: 'at_least_one_threshold_met',
                obligations: array_merge($obligations, [
                    'required' => $this->minimum,
                    'actual' => $permits,
                ]),
            );
        }
        if (count($challenges) > 0) {
            $c0 = $challenges[0];
            return SecurityDecision::challenge(
                policyId: $this->policyId,
                reasonCode: 'at_least_one_challenge_first',
                obligations: array_merge($c0->obligations, [
                    'required' => $this->minimum,
                    'actual' => $permits,
                ]),
            );
        }
        return SecurityDecision::deny(
            policyId: $this->policyId,
            reasonCode: 'at_least_one_threshold_not_met',
            obligations: [
                'required' => $this->minimum,
                'actual' => $permits,
                'denials' => count($denials),
                'children' => count($children),
            ],
        );
    }
}
