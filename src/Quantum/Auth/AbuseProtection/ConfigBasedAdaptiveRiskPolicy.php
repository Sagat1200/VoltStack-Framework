<?php

declare(strict_types=1);

namespace Quantum\Auth\AbuseProtection;

use Quantum\Auth\Contracts\AdaptiveRiskPolicyInterface;

/**
 * @internal Policy configurable por thresholds.
 *           scores:
 *             [0, stepUpThreshold)   → RiskDecision::allow
 *             [stepUpThreshold, denyThreshold) → RiskDecision::stepUpRequired(strength multi_factor)
 *             [denyThreshold, 100]    → RiskDecision::deny con reason_code risk.threshold_exceeded
 */
final class ConfigBasedAdaptiveRiskPolicy implements AdaptiveRiskPolicyInterface
{
    public function __construct(
        public readonly int $stepUpThreshold = 75,
        public readonly int $denyThreshold = 95,
        public readonly string $requiredStrengthName = 'multi_factor',
        public readonly int $requiredStrengthValue = 500,
    ) {
    }

    public function decide(RiskAssessmentResult $assessment): RiskDecision
    {
        $score = $assessment->riskScore;
        if ($score >= $this->denyThreshold) {
            return RiskDecision::deny(
                reasonCode: 'risk.deny_threshold_exceeded',
                metadata: [
                    'score' => $score,
                    'deny_threshold' => $this->denyThreshold,
                    'factors' => array_keys($assessment->riskFactors),
                    'assessed_at' => $assessment->assessedAt,
                ],
            );
        }
        if ($score >= $this->stepUpThreshold) {
            return RiskDecision::stepUpRequired(
                reasonCode: 'risk.step_up_required',
                requiredStrengthName: $this->requiredStrengthName,
                requiredStrengthValue: $this->requiredStrengthValue,
                metadata: [
                    'score' => $score,
                    'step_up_threshold' => $this->stepUpThreshold,
                    'factors' => array_keys($assessment->riskFactors),
                ],
            );
        }
        return RiskDecision::allow(['score' => $score, 'factors' => array_keys($assessment->riskFactors)]);
    }
}
