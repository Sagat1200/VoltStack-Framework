<?php

declare(strict_types=1);

namespace Quantum\Auth\AbuseProtection;

final readonly class ThrottleDecision
{
    public const ALLOW = 'allow';
    public const DENY = 'deny';

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $action,
        public ?string $reasonCode = null,
        public int $retryAfterSeconds = 0,
        public array $metadata = [],
    ) {
    }

    public function isAllowed(): bool
    {
        return $this->action === self::ALLOW;
    }

    public function isDenied(): bool
    {
        return $this->action === self::DENY;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function allow(array $metadata = []): self
    {
        return new self(
            action: self::ALLOW,
            reasonCode: null,
            retryAfterSeconds: 0,
            metadata: $metadata,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function deny(string $reasonCode, int $retryAfterSeconds, array $metadata = []): self
    {
        return new self(
            action: self::DENY,
            reasonCode: $reasonCode,
            retryAfterSeconds: $retryAfterSeconds,
            metadata: $metadata,
        );
    }
}
