<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\AbuseProtection\ThrottleDecision;

interface AbuseProtectionThrottleInterface
{
    public function decide(
        string $identifier,
        ?string $deviceRef = null,
        ?string $ipPrefix = null,
        ?string $rawPassword = null,
    ): ThrottleDecision;

    public function recordAttempt(
        string $identifier,
        bool $wasSuccessful,
        ?string $deviceRef = null,
        ?string $ipPrefix = null,
    ): void;
}
