<?php

declare(strict_types=1);

namespace Quantum\Authorization\Decision;

final readonly class DecisionResult
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private Decision $decision,
        private string $source = 'authorization',
        private string $reasonCode = 'deny_by_default',
        private array $metadata = [],
    ) {}

    /**
     * @param array<string, mixed> $metadata
     */
    public static function allow(string $source = 'authorization', string $reasonCode = 'explicit_allow', array $metadata = []): self
    {
        return new self(Decision::Allow, $source, $reasonCode, $metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function deny(string $source = 'authorization', string $reasonCode = 'deny_by_default', array $metadata = []): self
    {
        return new self(Decision::Deny, $source, $reasonCode, $metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function abstain(string $source = 'authorization', string $reasonCode = 'policy_abstain', array $metadata = []): self
    {
        return new self(Decision::Abstain, $source, $reasonCode, $metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function challenge(string $source = 'authorization', string $reasonCode = 'authentication_required', array $metadata = []): self
    {
        return new self(Decision::Challenge, $source, $reasonCode, $metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function failure(string $source = 'authorization', string $reasonCode = 'authorization_evaluation_failed', array $metadata = []): self
    {
        return new self(Decision::Failure, $source, $reasonCode, $metadata);
    }

    public function decision(): Decision
    {
        return $this->decision;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function isAllowed(): bool
    {
        return $this->decision === Decision::Allow;
    }

    public function isDenied(): bool
    {
        return $this->decision === Decision::Deny;
    }

    public function isChallenge(): bool
    {
        return $this->decision === Decision::Challenge;
    }

    public function isFailure(): bool
    {
        return $this->decision === Decision::Failure;
    }

    public function isAbstain(): bool
    {
        return $this->decision === Decision::Abstain;
    }
}
