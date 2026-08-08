<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Policy\Composition;

use Quantum\Controllers\Security\Contracts\ControllerSecurityPolicyInterface;
use Quantum\Controllers\Security\Decision\SecurityDecision;
use Quantum\Controllers\Security\Decision\SecurityDecisionEffect;
use Quantum\Controllers\Security\Decision\SecurityEvaluationRequest;

final class WeightedVotingPolicy extends CompositePolicy
{
    /**
     * @param array<string,int> $weights map child policyId => weight; default child = 1
     */
    public function __construct(
        string $policyId,
        array $children,
        private readonly array $weights = [],
        private readonly float $approvalRatio = 0.5,
    ) {
        parent::__construct($policyId, $children);
    }

    protected function evaluateChildren(array $children, SecurityEvaluationRequest $request): SecurityDecision
    {
        $allowWeight = 0;
        $denyWeight = 0;
        $abstainWeight = 0;
        $challenges = [];
        $allowObligations = [];
        $totalWeight = 0;

        foreach ($children as $child) {
            \assert($child instanceof ControllerSecurityPolicyInterface);
            $w = $this->weights[$child->id()] ?? 1;
            $totalWeight += $w;
            $d = $child->evaluate($request);
            switch ($d->effect) {
                case SecurityDecisionEffect::Allow:
                    $allowWeight += $w;
                    $allowObligations = array_merge($allowObligations, $d->obligations);
                    break;
                case SecurityDecisionEffect::Deny:
                    $denyWeight += $w;
                    break;
                case SecurityDecisionEffect::Challenge:
                    $challenges[] = $d;
                    break;
                case SecurityDecisionEffect::Abstain:
                default:
                    $abstainWeight += $w;
                    break;
            }
        }
        if ($totalWeight <= 0) {
            return SecurityDecision::abstain($this->policyId, 'weighted_zero_total');
        }
        $approval = $allowWeight / $totalWeight;
        $denial = $denyWeight / $totalWeight;

        if ($approval >= $this->approvalRatio) {
            return SecurityDecision::allow(
                policyId: $this->policyId,
                reasonCode: 'weighted_voting_passed',
                obligations: array_merge($allowObligations, [
                    'approval_ratio' => $this->approvalRatio,
                    'approval_actual' => $approval,
                    'allow_weight' => $allowWeight,
                    'deny_weight' => $denyWeight,
                    'total_weight' => $totalWeight,
                ]),
            );
        }
        if (count($challenges) > 0 && $denial < $this->approvalRatio) {
            $c0 = $challenges[0];
            return SecurityDecision::challenge(
                policyId: $this->policyId,
                reasonCode: 'weighted_voting_challenge',
                obligations: array_merge($c0->obligations, [
                    'approval_ratio' => $this->approvalRatio,
                    'approval_actual' => $approval,
                ]),
            );
        }
        return SecurityDecision::deny(
            policyId: $this->policyId,
            reasonCode: 'weighted_voting_failed',
            obligations: [
                'approval_ratio' => $this->approvalRatio,
                'approval_actual' => $approval,
                'allow_weight' => $allowWeight,
                'deny_weight' => $denyWeight,
                'abstain_weight' => $abstainWeight,
                'total_weight' => $totalWeight,
            ],
        );
    }
}
