<?php

declare(strict_types=1);

namespace Quantum\Auth\Sessions;

use Quantum\Auth\Identity\IdentityInterface;
use Quantum\Auth\Identity\IdentityReference;

final readonly class AuthenticationSession
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public AuthenticationSessionId $id,
        public IdentityInterface $identity,
        public IdentityReference $reference,
        public string $method,
        public int $issuedAt,
        public ?int $expiresAt = null,
        public array $attributes = [],
    ) {
    }

    public function isExpired(?int $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return ($now ?? time()) >= $this->expiresAt;
    }
}
