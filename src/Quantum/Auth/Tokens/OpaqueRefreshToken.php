<?php

declare(strict_types=1);

namespace Quantum\Auth\Tokens;

use Quantum\Auth\Identity\IdentityReference;

final readonly class OpaqueRefreshToken
{
    /**
     * @param array<int, string> $scopes
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public TokenId $id,
        public IdentityReference $reference,
        public int $issuedAt,
        public int $expiresAt,
        public ?TokenId $accessTokenId = null,
        public ?string $clientId = null,
        public array $scopes = [],
        public array $attributes = [],
        public bool $revoked = false,
    ) {}

    public function isExpired(?int $now = null): bool
    {
        $now ??= time();

        return $now >= $this->expiresAt;
    }

    public function isActive(?int $now = null): bool
    {
        return ! $this->revoked && ! $this->isExpired($now);
    }
}
