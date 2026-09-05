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
}
