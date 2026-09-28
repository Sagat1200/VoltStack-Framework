<?php

declare(strict_types=1);

namespace Quantum\Auth\Runtime;

final readonly class PolicyDecision
{
    /**
     * @param array<int, string> $reasonCodes
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public PolicyEffect $effect,
        public array $reasonCodes = [],
        public array $metadata = [],
    ) {}

    public function isAllowed(): bool
    {
        return $this->effect === PolicyEffect::Allow;
    }

    public function isDenied(): bool
    {
        return $this->effect === PolicyEffect::Deny;
    }

    /**
     * @param array<int, string> $reasonCodes
     * @param array<string, mixed> $metadata
     */
    public static function allow(array $reasonCodes = [], array $metadata = []): self
    {
        return new self(PolicyEffect::Allow, $reasonCodes, $metadata);
    }

    /**
     * @param array<int, string> $reasonCodes
     * @param array<string, mixed> $metadata
     */
    public static function deny(array $reasonCodes = [], array $metadata = []): self
    {
        return new self(PolicyEffect::Deny, $reasonCodes, $metadata);
    }
}
