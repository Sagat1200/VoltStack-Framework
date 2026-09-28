<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Tokens\OpaqueAccessToken;
use Quantum\Auth\Tokens\OpaqueRefreshToken;

interface OpaqueTokenRepositoryInterface
{
    public function findAccessToken(string $tokenId): ?OpaqueAccessToken;

    public function findRefreshToken(string $tokenId): ?OpaqueRefreshToken;

    public function saveAccessToken(OpaqueAccessToken $token): void;

    public function saveRefreshToken(OpaqueRefreshToken $token): void;

    public function revokeAccessToken(string $tokenId): bool;

    public function revokeRefreshToken(string $tokenId): bool;

    public function revokeAllForIdentity(string $identityType, string $identityId): int;

    /**
     * @return array<int, OpaqueAccessToken>
     */
    public function listAccessTokensForIdentity(string $identityType, string $identityId, ?int $now = null): array;

    /**
     * @return array<int, OpaqueRefreshToken>
     */
    public function listRefreshTokensForIdentity(string $identityType, string $identityId, ?int $now = null): array;
}
