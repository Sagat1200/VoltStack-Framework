<?php

declare(strict_types=1);

namespace Quantum\Auth\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\AbuseProtection\ConfigBasedAdaptiveRiskPolicy;
use Quantum\Auth\AbuseProtection\RiskAssessmentResult;
use Quantum\Controllers\Security\Context\AuthenticationStrength;
use Quantum\Auth\Exceptions\AuthExceptionMapper;
use Quantum\Auth\Exceptions\RiskDeniedException;
use Quantum\Auth\Exceptions\StepUpRequiredException;

final class Bloque5RiskV2Test extends TestCase
{
    public function test_b5_01_risk_denied_exception_403_status(): void
    {
        $mapper = new AuthExceptionMapper();
        $e = new RiskDeniedException(riskScore: 97, denyThreshold: 95);
        $this->assertSame(403, $mapper->statusCode($e));
        $this->assertSame('auth.risk_denied', $e->reasonCode);
    }

    public function test_b5_02_risk_headers_include_score_and_threshold(): void
    {
        $mapper = new AuthExceptionMapper();
        $e = new RiskDeniedException(riskScore: 98, denyThreshold: 95);
        $headers = $mapper->headers($e);
        $this->assertSame('true', $headers['X-Auth-Risk-Denied']);
        $this->assertSame('98', $headers['X-Auth-Risk-Score']);
        $this->assertSame('95', $headers['X-Auth-Risk-Deny-Threshold']);
    }

    public function test_b5_03_risk_json_extensions(): void
    {
        $mapper = new AuthExceptionMapper();
        $e = new RiskDeniedException(riskScore: 96, denyThreshold: 90);
        $ext = $mapper->jsonExtensions($e, false);
        $this->assertSame('auth.risk_denied', $ext['reason_code']);
        $this->assertSame('96', $ext['risk_score']);
        $this->assertSame('90', $ext['deny_threshold']);
    }

    public function test_b5_04_stepup_exception_403_and_strength_headers(): void
    {
        $mapper = new AuthExceptionMapper();
        $e = new StepUpRequiredException(
            'auth.risk_stepup',
            AuthenticationStrength::MultiFactor,
            AuthenticationStrength::Password,
            'Step up is needed'
        );
        $this->assertSame(403, $mapper->statusCode($e));
        $headers = $mapper->headers($e);
        $this->assertSame('required', $headers['X-Auth-Step-Up']);
        $this->assertSame(AuthenticationStrength::MultiFactor->name, $headers['X-Auth-Required-Strength']);
    }

    public function test_b5_05_stepup_json_contains_strengths(): void
    {
        $mapper = new AuthExceptionMapper();
        $e = new StepUpRequiredException(
            'risk.step.up',
            AuthenticationStrength::HardwareBacked,
            AuthenticationStrength::Token,
        );
        $ext = $mapper->jsonExtensions($e, true);
        $this->assertSame('risk.step.up', $ext['reason_code']);
        $this->assertSame(AuthenticationStrength::HardwareBacked->name, $ext['required_strength_name']);
        $this->assertSame((string) AuthenticationStrength::HardwareBacked->value, $ext['required_strength_value']);
        $this->assertSame(AuthenticationStrength::Token->name, $ext['current_strength_name']);
    }

    public function test_b5_06_adaptive_policy_emits_allow_stepup_deny_per_score(): void
    {
        $policy = new ConfigBasedAdaptiveRiskPolicy(
            stepUpThreshold: 75,
            denyThreshold: 95,
            requiredStrengthName: 'multi_factor',
            requiredStrengthValue: 500,
        );
        $now = time();
        $low = $policy->decide(new RiskAssessmentResult(30, $now, ['geo' => 'ok']));
        $this->assertSame('allow', $low->action);
        $mid = $policy->decide(new RiskAssessmentResult(80, $now, ['new_device' => true]));
        $this->assertSame('step_up_required', $mid->action);
        $this->assertSame('multi_factor', $mid->requiredStrengthName);
        $high = $policy->decide(new RiskAssessmentResult(99, $now, ['impossible_travel' => true, 'bloom' => true]));
        $this->assertSame('deny', $high->action);
        $this->assertSame('risk.deny_threshold_exceeded', $high->reasonCode);
    }
}
