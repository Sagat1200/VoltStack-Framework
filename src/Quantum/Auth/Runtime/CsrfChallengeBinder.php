<?php

declare(strict_types=1);

namespace Quantum\Auth\Runtime;

final class CsrfChallengeBinder
{
    public function issueChallenge(string $nonceValue, ?string $deviceRef = null, ?string $salt = 'csrf'): string
    {
        $material = $nonceValue . '|' . ($deviceRef ?? 'no-device');
        $length = 32;
        $raw = hash_hkdf('sha256', $material, $length, is_string($salt) ? $salt : 'csrf');
        return $raw !== false ? bin2hex($raw) : substr(hash('sha256', $material . $salt), 0, 32);
    }

    public function verifyChallenge(string $expectedChallenge, string $nonceValue, ?string $deviceRef = null, ?string $salt = 'csrf'): bool
    {
        $computed = $this->issueChallenge($nonceValue, $deviceRef, $salt);
        return hash_equals($computed, $expectedChallenge);
    }
}
