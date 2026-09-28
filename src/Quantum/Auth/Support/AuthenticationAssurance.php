<?php

declare(strict_types=1);

namespace Quantum\Auth\Support;

use Quantum\Controllers\Security\Context\AuthenticationStrength;
use Quantum\Auth\Runtime\AssuranceProfile;
use Quantum\Auth\Runtime\AuthenticationMethodReferenceList;

final class AuthenticationAssurance
{
    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public static function enrichAttributes(array $attributes, string $method): array
    {
        $strength = self::resolveStrength($attributes, $method);

        return array_merge($attributes, [
            'authentication_strength' => $strength->name,
            'authentication_strength_name' => $strength->name,
            'authentication_strength_value' => $strength->value,
            'authentication_assurance_profile' => self::profileFor($strength),
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function resolveStrength(array $attributes, string $method): AuthenticationStrength
    {
        $explicit = self::resolveExplicitStrength(
            $attributes['authentication_strength']
                ?? $attributes['authentication_strength_name']
                ?? $attributes['authentication_strength_value']
                ?? null,
        );

        if ($explicit !== null) {
            return $explicit;
        }

        return match (strtolower(trim($method))) {
            'password', 'session', 'manual' => AuthenticationStrength::Password,
            default => AuthenticationStrength::Password,
        };
    }

    public static function resolveExplicitStrength(mixed $value): ?AuthenticationStrength
    {
        return self::normalizeStrength($value);
    }

    public static function profileFor(AuthenticationStrength $strength): string
    {
        return match ($strength) {
            AuthenticationStrength::Anonymous => 'anonymous',
            AuthenticationStrength::Password => 'single_factor',
            AuthenticationStrength::Token => 'token_authenticated',
            AuthenticationStrength::MultiFactor => 'multi_factor',
            AuthenticationStrength::HardwareBacked => 'hardware_backed',
        };
    }

    private static function normalizeStrength(mixed $value): ?AuthenticationStrength
    {
        if ($value instanceof AuthenticationStrength) {
            return $value;
        }

        if (is_int($value) || (is_string($value) && is_numeric($value))) {
            return AuthenticationStrength::tryFrom((int) $value);
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));

        if ($normalized === '') {
            return null;
        }

        return match ($normalized) {
            'password', 'session', 'manual' => AuthenticationStrength::Password,
            'token', 'bearer' => AuthenticationStrength::Token,
            'multifactor', 'multi_factor', 'multi-factor', 'mfa' => AuthenticationStrength::MultiFactor,
            'hardwarebacked', 'hardware_backed', 'hardware-backed', 'hardware' => AuthenticationStrength::HardwareBacked,
            'anonymous', 'none', 'guest' => AuthenticationStrength::Anonymous,
            default => null,
        };
    }

    public static function composeAssuranceFromAmr(AuthenticationMethodReferenceList $amr): AssuranceProfile
    {
        $hasPwd = $amr->hasAmr('pwd') || $amr->hasAmr('password');
        $hasSession = $amr->hasAmr('session');
        $hasRemembered = $amr->hasAmr('remembered') || $amr->hasAmr('remember');
        $hasMfa = $amr->hasAmr('mfa') || $amr->hasAmr('multi_factor') || $amr->hasAmr('otp');
        $hasHwk = $amr->hasAmr('hwk') || $amr->hasAmr('hardware') || $amr->hasAmr('hardware_backed');
        $hasPasskey = $amr->hasAmr('passkey') || $amr->hasAmr('biometric') || $amr->hasAmr('fido2');
        $hasToken = $amr->hasAmr('token') || $amr->hasAmr('bearer');

        if ($hasPasskey) {
            return AssuranceProfile::HighestBiometricPasskey;
        }
        if ($hasHwk && ($hasMfa || $hasPwd)) {
            return AssuranceProfile::HighHardwareBacked;
        }
        if ($hasMfa || ($hasPwd && $hasSession && $hasRemembered)) {
            return AssuranceProfile::HighMfa;
        }
        if ($hasSession && $hasRemembered) {
            return AssuranceProfile::MediumSessionRemembered;
        }
        if ($hasToken) {
            return AssuranceProfile::MediumToken;
        }
        if ($hasPwd || $hasSession) {
            return AssuranceProfile::LowPassword;
        }
        if ($amr->count() === 0) {
            return AssuranceProfile::LowBasic;
        }
        return AssuranceProfile::Lowest;
    }

    public static function meetsMinimumAssurance(AssuranceProfile $actual, AssuranceProfile $required): bool
    {
        return $actual->value >= $required->value;
    }
}