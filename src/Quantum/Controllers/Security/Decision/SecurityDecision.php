<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Decision;

final readonly class SecurityDecision
{
    public function __construct(
        public SecurityDecisionEffect $effect,
        public string $policyId,
        public string $reasonCode = '',
        public array $obligations = [],
    ) {}

    public static function allow(string $policyId, string $reasonCode = 'explicit_allow', array $obligations = []): self
    {
        return new self(SecurityDecisionEffect::Allow, $policyId, $reasonCode, $obligations);
    }

    public static function deny(string $policyId, string $reasonCode = 'deny_by_default', array $obligations = []): self
    {
        return new self(SecurityDecisionEffect::Deny, $policyId, $reasonCode, $obligations);
    }

    public static function abstain(string $policyId, string $reasonCode = 'policy_abstain'): self
    {
        return new self(SecurityDecisionEffect::Abstain, $policyId, $reasonCode, []);
    }

    public static function challenge(string $policyId, string $reasonCode = 'authentication_required', array $obligations = []): self
    {
        return new self(SecurityDecisionEffect::Challenge, $policyId, $reasonCode, $obligations);
    }

    public function isAllow(): bool
    {
        return $this->effect === SecurityDecisionEffect::Allow;
    }

    public function isDeny(): bool
    {
        return $this->effect === SecurityDecisionEffect::Deny || $this->effect === SecurityDecisionEffect::Challenge;
    }
}
