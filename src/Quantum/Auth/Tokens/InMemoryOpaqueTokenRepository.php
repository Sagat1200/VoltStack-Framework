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
            consumed: $previous->consumed,
            consumedAt: $previous->consumedAt,
            rotatedTo: $previous->rotatedTo,
            familyId: $previous->familyId,
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

    public function consumeRefreshToken(string $tokenId, ?int $consumedAt = null): array
    {
        if (! isset($this->refreshTokens[$tokenId])) {
            return ['consumed' => false, 'already_consumed' => false, 'previous' => null];
        }
        $previous = $this->refreshTokens[$tokenId];
        if ($previous->consumed) {
            return ['consumed' => false, 'already_consumed' => true, 'previous' => $previous];
        }
        $consumedAt ??= time();
        $next = new OpaqueRefreshToken(
            id: $previous->id,
            reference: $previous->reference,
            issuedAt: $previous->issuedAt,
            expiresAt: $previous->expiresAt,
            accessTokenId: $previous->accessTokenId,
            clientId: $previous->clientId,
            scopes: $previous->scopes,
            attributes: $previous->attributes,
            revoked: $previous->revoked,
            consumed: true,
            consumedAt: $consumedAt,
            rotatedTo: $previous->rotatedTo,
            familyId: $previous->familyId,
        );
        $this->refreshTokens[$tokenId] = $next;
        return ['consumed' => true, 'already_consumed' => false, 'previous' => $previous];
    }

    public function findRefreshTokensByFamilyId(string $familyId): array
    {
        $result = [];
        foreach ($this->refreshTokens as $token) {
            if (is_string($token->familyId) && $token->familyId !== '' && hash_equals($familyId, $token->familyId)) {
                $result[] = $token;
            }
        }
        return $result;
    }

    public function revokeFamilyByReuse(string $familyId, ?int $reuseDetectedAt = null): int
    {
        $revoked = 0;
        $familyTokens = $this->findRefreshTokensByFamilyId($familyId);
        foreach ($familyTokens as $refresh) {
            if (! $refresh->revoked) {
                if ($this->revokeRefreshToken($refresh->id->value)) {
                    $revoked++;
                }
            }
            if ($refresh->accessTokenId instanceof TokenId && ! ($this->accessTokens[$refresh->accessTokenId->value]?->revoked ?? false)) {
                if ($this->revokeAccessToken($refresh->accessTokenId->value)) {
                    $revoked++;
                }
            }
            $rotated = $refresh->rotatedTo;
            while ($rotated instanceof TokenId) {
                $next = $this->refreshTokens[$rotated->value] ?? null;
                if (! $next) {
                    break;
                }
                if (! $next->revoked) {
                    if ($this->revokeRefreshToken($next->id->value)) {
                        $revoked++;
                    }
                }
                if ($next->accessTokenId instanceof TokenId && ! ($this->accessTokens[$next->accessTokenId->value]?->revoked ?? false)) {
                    if ($this->revokeAccessToken($next->accessTokenId->value)) {
                        $revoked++;
                    }
                }
                $rotated = $next->rotatedTo;
            }
        }
        foreach ($this->accessTokens as $access) {
            if (! $access->revoked && $access->refreshTokenId instanceof TokenId) {
                $linkedRefresh = $this->refreshTokens[$access->refreshTokenId->value] ?? null;
                if ($linkedRefresh instanceof OpaqueRefreshToken && is_string($linkedRefresh->familyId) && hash_equals($linkedRefresh->familyId, $familyId)) {
                    if ($this->revokeAccessToken($access->id->value)) {
                        $revoked++;
                    }
                }
            }
        }
        return $revoked;
    }

    public function markRotatedTo(string $parentRefreshTokenId, TokenId $nextRefreshId): void
    {
        if (! isset($this->refreshTokens[$parentRefreshTokenId])) {
            return;
        }
        $previous = $this->refreshTokens[$parentRefreshTokenId];
        $this->refreshTokens[$parentRefreshTokenId] = new OpaqueRefreshToken(
            id: $previous->id,
            reference: $previous->reference,
            issuedAt: $previous->issuedAt,
            expiresAt: $previous->expiresAt,
            accessTokenId: $previous->accessTokenId,
            clientId: $previous->clientId,
            scopes: $previous->scopes,
            attributes: $previous->attributes,
            revoked: $previous->revoked,
            consumed: $previous->consumed,
            consumedAt: $previous->consumedAt,
            rotatedTo: $nextRefreshId,
            familyId: $previous->familyId,
        );
    }
}
