<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Tokens\OpaqueAccessToken;
use Quantum\Auth\Tokens\OpaqueRefreshToken;

interface OpaqueTokenRepositoryInterface extends RefreshTokenRotationStoreInterface
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

    /**
     * Marca un refresh token como consumido (one-time use).
     * Devuelve array con consumed:bool true si se aplicó el cambio, ya_consumido:bool si previamente estaba consumed=true.
     *
     * @return array{consumed:bool, already_consumed:bool, previous:?OpaqueRefreshToken}
     */
    public function consumeRefreshToken(string $tokenId, ?int $consumedAt = null): array;

    /**
     * Localiza tokens refresh de una familia por su familyId (si el padre de familia fue reusado).
     *
     * @return array<int, OpaqueRefreshToken>
     */
    public function findRefreshTokensByFamilyId(string $familyId): array;

    /**
     * Revoca bulk todo el árbol descendiente de refresh tokens de una misma familia detectando reuse.
     * Devuelve número de tokens revocados (incluye refresh y accesos vinculados).
     */
    public function revokeFamilyByReuse(string $familyId, ?int $reuseDetectedAt = null): int;

    /**
     * Asigna rotatedTo al refresh token padre tras una rotación exitosa.
     */
    public function markRotatedTo(string $parentRefreshTokenId, \Quantum\Auth\Tokens\TokenId $nextRefreshId): void;
}
