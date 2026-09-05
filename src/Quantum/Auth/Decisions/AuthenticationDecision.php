<?php

declare(strict_types=1);

namespace Quantum\Auth\Decisions;

use Quantum\Auth\Context\AuthenticationContext;

final readonly class AuthenticationDecision
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public AuthenticationDecisionStatus $status,
        public ?AuthenticationContext $context = null,
        public array $metadata = [],
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function authenticated(AuthenticationContext $context, array $metadata = []): self
    {
        return new self(
            status: AuthenticationDecisionStatus::Authenticated,
            context: $context,
            metadata: $metadata,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function rejected(array $metadata = []): self
    {
        return new self(
            status: AuthenticationDecisionStatus::Rejected,
            context: null,
            metadata: $metadata,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function unauthenticated(array $metadata = []): self
    {
        return new self(
            status: AuthenticationDecisionStatus::Unauthenticated,
            context: null,
            metadata: $metadata,
        );
    }

    public function isAuthenticated(): bool
    {
        return $this->status === AuthenticationDecisionStatus::Authenticated && $this->context !== null;
    }
}
