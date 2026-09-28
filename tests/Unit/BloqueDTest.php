<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Runtime\AssuranceProfile;
use Quantum\Auth\Runtime\AssuranceStepUpEvaluator;
use Quantum\Auth\Runtime\AuthenticationMethodReferenceList;
use Quantum\Auth\Support\AuthenticationAssurance;

final class BloqueDTest extends TestCase
{
    public function test_amr_list_add_amr_deduplicates_entries(): void
    {
        $list = new AuthenticationMethodReferenceList(['pwd', 'session']);
        $list2 = $list->addAmr('pwd')->addAmr('mfa')->addAmr('session');

        self::assertSame(['pwd', 'session', 'mfa'], $list2->toArray());
        self::assertSame(3, $list2->count());
        self::assertTrue($list2->hasAmr('pwd'));
        self::assertTrue($list2->hasAmr('mfa'));
        self::assertFalse($list2->hasAmr('hwk'));
    }

    public function test_compose_assurance_password_only_maps_to_low_password(): void
    {
        $amr = new AuthenticationMethodReferenceList(['pwd']);
        $profile = AuthenticationAssurance::composeAssuranceFromAmr($amr);
        self::assertSame(AssuranceProfile::LowPassword, $profile);
    }

    public function test_compose_assurance_pwd_plus_session_mfa_maps_to_high_mfa(): void
    {
        $amr = new AuthenticationMethodReferenceList(['pwd', 'session', 'mfa']);
        $profile = AuthenticationAssurance::composeAssuranceFromAmr($amr);
        self::assertSame(AssuranceProfile::HighMfa, $profile);
    }

    public function test_compose_assurance_hardware_and_passkey_pick_highest_biometric(): void
    {
        $amr = new AuthenticationMethodReferenceList(['hwk', 'passkey']);
        $profile = AuthenticationAssurance::composeAssuranceFromAmr($amr);
        self::assertSame(AssuranceProfile::HighestBiometricPasskey, $profile);
    }

    public function test_meets_minimum_assurance_lower_required_is_ok(): void
    {
        $result = AuthenticationAssurance::meetsMinimumAssurance(
            actual: AssuranceProfile::HighMfa,
            required: AssuranceProfile::LowPassword,
        );
        self::assertTrue($result);
    }

    public function test_meets_minimum_assurance_equal_required_is_ok(): void
    {
        $result = AuthenticationAssurance::meetsMinimumAssurance(
            actual: AssuranceProfile::MediumToken,
            required: AssuranceProfile::MediumToken,
        );
        self::assertTrue($result);
    }

    public function test_meets_minimum_assurance_higher_required_returns_false(): void
    {
        $result = AuthenticationAssurance::meetsMinimumAssurance(
            actual: AssuranceProfile::LowPassword,
            required: AssuranceProfile::HighMfa,
        );
        self::assertFalse($result);
    }

    public function test_assurance_disabled_fallback_evaluator_skips_min_assurance_when_not_set(): void
    {
        $evaluator = new AssuranceStepUpEvaluator();
        $actual = AssuranceProfile::LowBasic;

        $noRequirement = $evaluator->evaluateFor($actual, []);
        self::assertFalse($noRequirement->isRequired);
        self::assertNull($noRequirement->requiredProfile);

        $withRequirement = $evaluator->evaluateFor(
            $actual,
            ['operation' => 'manage_admin', 'min_assurance' => AssuranceProfile::HighMfa],
        );
        self::assertTrue($withRequirement->isRequired);
        self::assertSame(AssuranceProfile::HighMfa, $withRequirement->requiredProfile);
        self::assertSame('step_up_required_for_manage_admin', $withRequirement->reasonCode);

        $profileStringRequirement = $evaluator->evaluateFor(
            $actual,
            ['operation' => 'critical_op', 'min_assurance' => 'high_mfa'],
        );
        self::assertTrue($profileStringRequirement->isRequired);
        self::assertSame(AssuranceProfile::HighMfa, $profileStringRequirement->requiredProfile);
    }
}
