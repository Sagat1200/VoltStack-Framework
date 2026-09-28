<?php

declare(strict_types=1);

namespace Quantum\Auth\Runtime;

final class AssuranceStepUpEvaluator
{
    /**
     * @param array{operation?: string, min_assurance?: AssuranceProfile|string|null} $operationRequirements
     */
    public function evaluateFor(
        AssuranceProfile $actualProfile,
        array $operationRequirements = [],
    ): StepUpRequirement {
        $minAssurance = $operationRequirements['min_assurance'] ?? null;

        if ($minAssurance === null) {
            return StepUpRequirement::none();
        }

        if (is_string($minAssurance)) {
            $enum = match (strtolower(trim($minAssurance))) {
                'lowest' => AssuranceProfile::Lowest,
                'low_basic', 'lowbasic' => AssuranceProfile::LowBasic,
                'low_password', 'lowpassword', 'pwd' => AssuranceProfile::LowPassword,
                'medium_token', 'mediumtoken', 'token' => AssuranceProfile::MediumToken,
                'medium_session_remembered', 'mediumsession', 'remembered' => AssuranceProfile::MediumSessionRemembered,
                'high_mfa', 'highmfa', 'mfa' => AssuranceProfile::HighMfa,
                'high_hardware_backed', 'hardware', 'hwk' => AssuranceProfile::HighHardwareBacked,
                'highest_biometric_passkey', 'passkey', 'biometric', 'highest' => AssuranceProfile::HighestBiometricPasskey,
                default => null,
            };
            if ($enum === null) {
                return StepUpRequirement::none();
            }
            $minAssurance = $enum;
        }

        if (! $minAssurance instanceof AssuranceProfile) {
            return StepUpRequirement::none();
        }

        $meets = $actualProfile->value >= $minAssurance->value;
        if ($meets) {
            return StepUpRequirement::none();
        }

        $op = $operationRequirements['operation'] ?? 'generic_protected_operation';
        return StepUpRequirement::required(
            profile: $minAssurance,
            reasonCode: 'step_up_required_for_' . $op,
        );
    }
}
