<?php

declare(strict_types=1);

namespace Quantum\Auth\AbuseProtection;

/**
 * @internal V2 Decision Adaptive Risk: ALLOW | STEP_UP_REQUIRED | DENY
 */
final readonly class RiskDecision
{
    public const ACTION_ALLOW = 'allow';
    public const ACTION_STEP_UP = 'step_up_required';
    public const ACTION_DENY = 'deny';

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $action,
        public ?string $reasonCode = null,
        public ?string $requiredStrengthName = null,
        public int $requiredStrengthValue = 0,
        public array $metadata = [],
    ) {
    }

    public function isAllow(): bool
    {
        return $this->action === self::ACTION_ALLOW;
    }

    public function isStepUpRequired(): bool
    {
        return $this->action === self::ACTION_STEP_UP;
    }

    public function isDenied(): bool
    {
        return $this->action === self::ACTION_DENY;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function allow(array $metadata = []): self
    {
        return new self(action: self::ACTION_ALLOW, metadata: $metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function stepUpRequired(
        string $reasonCode,
        string $requiredStrengthName = 'multi_factor',
        int $requiredStrengthValue = 500,
        array $metadata = [],
    ): self {
        return new self(
            action: self::ACTION_STEP_UP,
            reasonCode: $reasonCode,
            requiredStrengthName: $requiredStrengthName,
            requiredStrengthValue: $requiredStrengthValue,
            metadata: $metadata,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function deny(string $reasonCode, array $metadata = []): self
    {
        return new self(action: self::ACTION_DENY, reasonCode: $reasonCode, metadata: $metadata);
    }
}
