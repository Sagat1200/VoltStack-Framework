<?php

declare(strict_types=1);

namespace Quantum\Auth\Tokens;

use Quantum\Auth\Contracts\OpaqueTokenRepositoryInterface;

final class InMemoryOpaqueTokenRepository implements OpaqueTokenRepositoryInterface
{
    /**
     * @var array<string, OpaqueAccessToken>
     */
    private array $accessTokens = [];

    /**
     * @var array<string, OpaqueRefreshToken>
     */
    private array $refreshTokens = [];

    public function findAccessToken(string $tokenId): ?OpaqueAccessToken
    {
        $token = $this->accessTokens[$tokenId] ?? null;

        return $token instanceof OpaqueAccessToken ? $token : null;
    }

    public function findRefreshToken(string $tokenId): ?OpaqueRefreshToken
    {
        $token = $this->refreshTokens[$tokenId] ?? null;

        return $token instanceof OpaqueRefreshToken ? $token : null;
    }

    public function saveAccessToken(OpaqueAccessToken $token): void
    {
        $this->accessTokens[$token->id->value] = $token;
    }

    public function saveRefreshToken(OpaqueRefreshToken $token): void
    {
        $this->refreshTokens[$token->id->value] = $token;
    }

    public function revokeAccessToken(string $tokenId): bool
    {
        if (! isset($this->accessTokens[$tokenId])) {
            return false;
        }

        $previous = $this->accessTokens[$tokenId];
        $this->accessTokens[$tokenId] = new OpaqueAccessToken(
            id: $previous->id,
            reference: $previous->reference,
            issuedAt: $previous->issuedAt,
            expiresAt: $previous->expiresAt,
            clientId: $previous->clientId,
            scopes: $previous->scopes,
            refreshTokenId: $previous->refreshTokenId,
            attributes: $previous->attributes,
            revoked: true,
        );

        return true;
    }

    public function revokeRefreshToken(string $tokenId): bool
    {
        if (! isset($this->refreshTokens[$tokenId])) {
            return false;
        }

        $previous = $this->refreshTokens[$tokenId];
        $this->refreshTokens[$tokenId] = new OpaqueRefreshToken(
            id: $previous->id,
            reference: $previous->reference,
            issuedAt: $previous->issuedAt,
            expiresAt: $previous->expiresAt,
            accessTokenId: $previous->accessTokenId,
            clientId: $previous->clientId,
            scopes: $previous->scopes,
            attributes: $previous->attributes,
            revoked: true,
        );

        return true;
    }

    public function revokeAllForIdentity(string $identityType, string $identityId): int
    {
        $revoked = 0;

        foreach (array_keys($this->accessTokens) as $key) {
            if (
                $this->accessTokens[$key] instanceof OpaqueAccessToken
                && $this->accessTokens[$key]->reference->type === $identityType
                && $this->accessTokens[$key]->reference->identifier->value === $identityId
                && ! $this->accessTokens[$key]->revoked
            ) {
                if ($this->revokeAccessToken($key)) {
                    $revoked++;
                }
            }
        }

        foreach (array_keys($this->refreshTokens) as $key) {
            if (
                $this->refreshTokens[$key] instanceof OpaqueRefreshToken
                && $this->refreshTokens[$key]->reference->type === $identityType
                && $this->refreshTokens[$key]->reference->identifier->value === $identityId
                && ! $this->refreshTokens[$key]->revoked
            ) {
                if ($this->revokeRefreshToken($key)) {
                    $revoked++;
                }
            }
        }

        return $revoked;
    }

    public function listAccessTokensForIdentity(string $identityType, string $identityId, ?int $now = null): array
    {
        $now ??= time();
        $result = [];

        foreach ($this->accessTokens as $token) {
            if (
                $token->reference->type === $identityType
                && $token->reference->identifier->value === $identityId
            ) {
                $result[] = $token;
            }
        }

        return $result;
    }

    public function listRefreshTokensForIdentity(string $identityType, string $identityId, ?int $now = null): array
    {
        $now ??= time();
        $result = [];

        foreach ($this->refreshTokens as $token) {
            if (
                $token->reference->type === $identityType
                && $token->reference->identifier->value === $identityId
            ) {
                $result[] = $token;
            }
        }

        return $result;
    }
}
