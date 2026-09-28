<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Passwords\PasswordPolicy;
use Quantum\Config\ConfigRepository;

final class PasswordPolicyTest extends TestCase
{
    public function test_it_enforces_min_and_max_lengths_when_verifying_passwords(): void
    {
        $policy = new PasswordPolicy(new ConfigRepository([
            'auth' => [
                'password' => [
                    'min_length' => 8,
                    'max_length' => 12,
                ],
            ],
        ]));

        $hash = password_hash('secret-123', PASSWORD_DEFAULT);

        self::assertFalse($policy->accepts('short'));
        self::assertFalse($policy->verify('short', $hash));
        self::assertFalse($policy->accepts(str_repeat('a', 13)));
        self::assertTrue($policy->verify('secret-123', $hash));
    }

    public function test_it_detects_expired_passwords_based_on_age_or_explicit_expiry_timestamp(): void
    {
        $policy = new PasswordPolicy(new ConfigRepository([
            'auth' => [
                'password' => [
                    'expires_after_seconds' => 60,
                ],
            ],
        ]));

        $futureCreatedAt = time() - 30;
        self::assertFalse($policy->isExpired($futureCreatedAt));

        $expiredCreatedAt = time() - 120;
        self::assertTrue($policy->isExpired($expiredCreatedAt));

        $explicitExpiry = time() - 10;
        self::assertTrue($policy->isExpired(time() - 1000, $explicitExpiry));

        $explicitFutureExpiry = time() + 1000;
        self::assertFalse($policy->isExpired(time() - 1000, $explicitFutureExpiry));
    }

    public function test_it_detects_password_rotation_requirement_based_on_min_interval(): void
    {
        $policyNoRotation = new PasswordPolicy(new ConfigRepository());
        self::assertFalse($policyNoRotation->needsRotation(time() - 365 * 86400));

        $policyWithInterval = new PasswordPolicy(new ConfigRepository([
            'auth' => [
                'password' => [
                    'min_rotation_interval_seconds' => 300,
                ],
            ],
        ]));

        self::assertFalse($policyWithInterval->needsRotation(time() - 60));
        self::assertTrue($policyWithInterval->needsRotation(time() - 600));
    }

    public function test_it_rejects_passwords_that_match_any_entry_in_rotation_history(): void
    {
        $policy = new PasswordPolicy(new ConfigRepository());

        $history = [
            password_hash('old-pass-1', PASSWORD_DEFAULT),
            password_hash('old-pass-2', PASSWORD_DEFAULT),
            password_hash('old-pass-3', PASSWORD_DEFAULT),
        ];

        self::assertFalse($policy->checkAgainstHistory('old-pass-2', $history));
        self::assertFalse($policy->checkAgainstHistory('old-pass-1', $history));
        self::assertTrue($policy->checkAgainstHistory('brand-new-password', $history));
        self::assertTrue($policy->checkAgainstHistory('brand-new-password', []));
    }
}
