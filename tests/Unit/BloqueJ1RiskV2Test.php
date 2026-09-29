<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\AbuseProtection\ConfigBasedAdaptiveRiskPolicy;
use Quantum\Auth\AbuseProtection\RiskAssessmentResult;
use Quantum\Auth\AbuseProtection\RiskDecision;
use Quantum\Auth\Exceptions\AuthExceptionMapper;
use Quantum\Auth\Exceptions\RiskDeniedException;

final class BloqueJ1RiskV2Test extends TestCase
{
    public function test_risk_assessment_result_clamps_score_between_0_and_100(): void
    {
        $low = RiskAssessmentResult::ofScore(0, 1_700_000_000, ['trusted_device' => true]);
        self::assertSame(0, $low->riskScore);
        self::assertSame(1_700_000_000, $low->assessedAt);

        $mid = RiskAssessmentResult::ofScore(60, 1_700_000_001);
        self::assertSame(60, $mid->riskScore);

        $over100 = RiskAssessmentResult::ofScore(9999, 1_700_000_002);
        self::assertSame(100, $over100->riskScore);

        $negative = RiskAssessmentResult::ofScore(-10, 1_700_000_003);
        self::assertSame(0, $negative->riskScore);

        $allow = RiskAssessmentResult::allow(1_700_000_000, ['user_recognized' => 'yes']);
        self::assertSame(0, $allow->riskScore);
        self::assertArrayHasKey('user_recognized', $allow->riskFactors);
    }

    public function test_config_based_policy_allow_for_low_scores_below_stepup_threshold(): void
    {
        $policy = new ConfigBasedAdaptiveRiskPolicy(stepUpThreshold: 75, denyThreshold: 95);
        $scores = [0, 10, 50, 74];
        foreach ($scores as $s) {
            $decision = $policy->decide(RiskAssessmentResult::ofScore($s, time()));
            self::assertTrue($decision->isAllow(), "score $s must be ALLOW");
            self::assertFalse($decision->isStepUpRequired());
            self::assertFalse($decision->isDenied());
        }
    }

    public function test_config_based_policy_returns_step_up_required_with_reason_and_strength_metadata(): void
    {
        $policy = new ConfigBasedAdaptiveRiskPolicy(
            stepUpThreshold: 75,
            denyThreshold: 95,
            requiredStrengthName: 'hardware_key',
            requiredStrengthValue: 900,
        );
        foreach ([75, 80, 94] as $s) {
            $assess = RiskAssessmentResult::ofScore($s, time(), [
                'new_ip' => true,
                'new_device' => true,
                'user_agent_mismatch' => true,
            ]);
            $decision = $policy->decide($assess);
            self::assertTrue($decision->isStepUpRequired(), "score $s must trigger STEP_UP_REQUIRED");
            self::assertFalse($decision->isAllow());
            self::assertFalse($decision->isDenied());
            self::assertSame('risk.step_up_required', $decision->reasonCode);
            self::assertSame('hardware_key', $decision->requiredStrengthName);
            self::assertSame(900, $decision->requiredStrengthValue);
            self::assertSame($s, $decision->metadata['score'] ?? null);
            self::assertContains('new_ip', $decision->metadata['factors'] ?? []);
        }
    }

    public function test_config_based_policy_deny_exceeds_deny_threshold_with_reason_code(): void
    {
        $policy = new ConfigBasedAdaptiveRiskPolicy(stepUpThreshold: 75, denyThreshold: 95);
        foreach ([95, 99, 100] as $s) {
            $assess = RiskAssessmentResult::ofScore($s, time(), [
                'shared_ip_60_identities' => true,
                'credential_stuffing_pattern' => true,
                'bot_like_keystroke' => true,
            ]);
            $decision = $policy->decide($assess);
            self::assertTrue($decision->isDenied(), "score $s must trigger DENY");
            self::assertFalse($decision->isAllow());
            self::assertFalse($decision->isStepUpRequired());
            self::assertSame('risk.deny_threshold_exceeded', $decision->reasonCode);
            self::assertSame(95, $decision->metadata['deny_threshold'] ?? null);
            self::assertSame($s, $decision->metadata['score'] ?? null);
        }
    }

    public function test_risk_denied_exception_is_auth_exception_mapped_to_403_with_risk_headers(): void
    {
        $e = new RiskDeniedException(riskScore: 97, denyThreshold: 95);
        self::assertSame('auth.risk_denied', $e->reasonCode);
        self::assertSame(97, $e->riskScore);
        self::assertSame(95, $e->denyThreshold);
        $mapper = new AuthExceptionMapper();
        self::assertSame(403, $mapper->statusCode($e));
        $headers = $mapper->headers($e);
        self::assertSame('true', $headers['X-Auth-Risk-Denied'] ?? null);
        self::assertSame('97', $headers['X-Auth-Risk-Score'] ?? null);
        self::assertSame('95', $headers['X-Auth-Risk-Deny-Threshold'] ?? null);
        $jsonExt = $mapper->jsonExtensions($e, false);
        self::assertSame('auth.risk_denied', $jsonExt['reason_code'] ?? null);
        self::assertSame('97', $jsonExt['risk_score'] ?? null);
        self::assertSame('95', $jsonExt['deny_threshold'] ?? null);
    }

    public function test_risk_decision_static_factories_construct_correct_action_values(): void
    {
        $allow = RiskDecision::allow(['hello' => 'world']);
        self::assertSame(RiskDecision::ACTION_ALLOW, $allow->action);
        self::assertSame('world', $allow->metadata['hello'] ?? null);

        $step = RiskDecision::stepUpRequired(
            reasonCode: 'custom_step_up',
            requiredStrengthName: 'otp_sms',
            requiredStrengthValue: 300,
            metadata: ['channel' => 'sms'],
        );
        self::assertSame(RiskDecision::ACTION_STEP_UP, $step->action);
        self::assertSame('custom_step_up', $step->reasonCode);
        self::assertSame('otp_sms', $step->requiredStrengthName);
        self::assertSame(300, $step->requiredStrengthValue);

        $deny = RiskDecision::deny('blocked.country_restricted', ['country' => 'XX']);
        self::assertSame(RiskDecision::ACTION_DENY, $deny->action);
        self::assertSame('blocked.country_restricted', $deny->reasonCode);
        self::assertSame('XX', $deny->metadata['country'] ?? null);
    }
}
