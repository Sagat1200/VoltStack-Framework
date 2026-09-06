<?php

declare(strict_types=1);

namespace Quantum\Auth\Support;

use Quantum\Controllers\Security\Context\AuthenticationStrength;

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
}