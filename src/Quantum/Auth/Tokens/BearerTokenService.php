<?php

declare(strict_types=1);

namespace Quantum\Auth\Tokens;

use Quantum\Auth\Contracts\OpaqueTokenRepositoryInterface;
use Quantum\Auth\Identity\IdentityReference;

/**
 * @internal V2 Bearer token service para ciclo 083.
 *           Encapsula rotate refresh token one-time-consume + family_id rotation + reuse detection invalidation bulk.
 */
final class BearerTokenService
{
    public function __construct(
        private readonly OpaqueTokenRepositoryInterface $repository,
        private readonly int $accessTokenTtlSec = 3600,
        private readonly int $refreshTokenTtlSec = 1209600,
    ) {
    }

    /**
     * Rota un refresh-token one-time:
     *   - consume el refresh padre
     *   genera nuevo access token + refresh token (misma familyId
     *   - enlaza rotatedTo del padre al nuevo refresh
     *   - si el token padre ya estaba consumido -> familia invalidate bulk revoke familyId y retorna status:family_revoked
     *
     * @return array{status:string, new_access_token:?OpaqueAccessToken, new_refresh_token:?OpaqueRefreshToken, revoked_count:int, reason_code:?string}
     */
    public function rotateRefresh(string $refreshTokenId, ?int $nowTs = null): array
    {
        $now = $nowTs ?? time();
        $refresh = $this->repository->findRefreshToken($refreshTokenId);
        if (! $refresh instanceof OpaqueRefreshToken) {
            return ['status' => 'not_found', 'new_access_token' => null, 'new_refresh_token' => null, 'revoked_count' => 0, 'reason_code' => 'refresh_token_unknown'];
        }
        if (! $refresh->isActive($now)) {
            if ($refresh->revoked) {
                return ['status' => 'revoked', 'new_access_token' => null, 'new_refresh_token' => null, 'revoked_count' => 0, 'reason_code' => 'refresh_token_revoked'];
            }
            return ['status' => 'expired', 'new_access_token' => null, 'new_refresh_token' => null, 'revoked_count' => 0, 'reason_code' => 'refresh_token_expired'];
        }
        if ($refresh->consumed) {
            $familyId = $refresh->familyId;
            $revoked = 0;
            if (is_string($familyId) && $familyId !== '') {
                $revoked = $this->repository->revokeFamilyByReuse($familyId, $now);
            }
            return ['status' => 'family_revoked', 'new_access_token' => null, 'new_refresh_token' => null, 'revoked_count' => $revoked, 'reason_code' => 'refresh_token_reuse_detected_family_revoked'];
        }
        $consumeResult = $this->repository->consumeRefreshToken($refreshTokenId, $now);
        if ($consumeResult['already_consumed']) {
            $familyId = $refresh->familyId;
            $revoked = 0;
            if (is_string($familyId) && $familyId !== '') {
                $revoked = $this->repository->revokeFamilyByReuse($familyId, $now);
            }
            return ['status' => 'family_revoked', 'new_access_token' => null, 'new_refresh_token' => null, 'revoked_count' => $revoked, 'reason_code' => 'refresh_token_concurrent_reuse_revoked'];
        }
        $newFamilyId = $refresh->familyId ?? ('fam_' . bin2hex(random_bytes(8)));
        $newAccessId = TokenId::generateAccess();
        $newRefreshId = TokenId::generateRefresh();
        $identityRef = $refresh->reference;
        $newAccess = new OpaqueAccessToken(
            id: $newAccessId,
            reference: $identityRef,
            issuedAt: $now,
            expiresAt: $now + $this->accessTokenTtlSec,
            clientId: $refresh->clientId,
            scopes: $refresh->scopes,
            refreshTokenId: $newRefreshId,
            attributes: array_replace($refresh->attributes, ['rotation_generation' => 'bearer_v2_rotated']),
            revoked: false,
        );
        $newRefresh = new OpaqueRefreshToken(
            id: $newRefreshId,
            reference: $identityRef,
            issuedAt: $now,
            expiresAt: $now + $this->refreshTokenTtlSec,
            accessTokenId: $newAccessId,
            clientId: $refresh->clientId,
            scopes: $refresh->scopes,
            attributes: array_replace($refresh->attributes, ['rotation_generation' => 'bearer_v2_rotated', 'parent_refresh_token_id' => $refresh->id->value]),
            revoked: false,
            consumed: false,
            consumedAt: null,
            rotatedTo: null,
            familyId: $newFamilyId,
        );
        $this->repository->saveAccessToken($newAccess);
        $this->repository->saveRefreshToken($newRefresh);
        $this->repository->markRotatedTo($refresh->id->value, $newRefreshId);
        return ['status' => 'rotated', 'new_access_token' => $newAccess, 'new_refresh_token' => $newRefresh, 'revoked_count' => 0, 'reason_code' => null];
    }

    /**
     * Crea el árbol inicial de tokens (primer refresh) para establece el familyId.
     */
    public function issueTokenPair(IdentityReference $reference, ?string $clientId = null, array $scopes = [], ?string $familyId = null, ?int $nowTs = null, array $attributes = []): array
    {
        $now = $nowTs ?? time();
        $accessId = TokenId::generateAccess();
        $refreshId = TokenId::generateRefresh();
        $famId = $familyId ?? ('fam_' . bin2hex(random_bytes(8)));
        $access = new OpaqueAccessToken(
            id: $accessId,
            reference: $reference,
            issuedAt: $now,
            expiresAt: $now + $this->accessTokenTtlSec,
            clientId: $clientId,
            scopes: $scopes,
            refreshTokenId: $refreshId,
            attributes: array_replace($attributes, ['family_id' => $famId]),
            revoked: false,
        );
        $refresh = new OpaqueRefreshToken(
            id: $refreshId,
            reference: $reference,
            issuedAt: $now,
            expiresAt: $now + $this->refreshTokenTtlSec,
            accessTokenId: $accessId,
            clientId: $clientId,
            scopes: $scopes,
            attributes: array_replace($attributes, ['family_id' => $famId]),
            revoked: false,
            consumed: false,
            consumedAt: null,
            rotatedTo: null,
            familyId: $famId,
        );
        $this->repository->saveAccessToken($access);
        $this->repository->saveRefreshToken($refresh);
        return ['access_token' => $access, 'refresh_token' => $refresh];
    }

    /**
     * Introspección de un access token: retorna estado estructurado.
     *
     * @return array{active:bool, token_type:?string, client_id:?string, identifier:?string, identity_type:?string, scopes:array<int,string>, issued_at:?int, expires_at:?int, family_id:?string, refresh_token_id:?string, revoked:bool}
     */
    public function introspectAccessToken(string $accessTokenId, ?int $nowTs = null): array
    {
        $token = $this->repository->findAccessToken($accessTokenId);
        if (! $token instanceof OpaqueAccessToken) {
            return [
                'active' => false, 'token_type' => null, 'client_id' => null,
                'identifier' => null, 'identity_type' => null, 'scopes' => [],
                'issued_at' => null, 'expires_at' => null, 'family_id' => null,
                'refresh_token_id' => null, 'revoked' => false,
            ];
        }
        $now = $nowTs ?? time();
        $active = $token->isActive($now);
        $familyId = is_string($token->attributes['family_id'] ?? null) ? $token->attributes['family_id'] : null;
        return [
            'active' => $active,
            'token_type' => 'access_token',
            'client_id' => $token->clientId,
            'identifier' => $token->reference->identifier->value,
            'identity_type' => $token->reference->type,
            'scopes' => $token->scopes,
            'issued_at' => $token->issuedAt,
            'expires_at' => $token->expiresAt,
            'family_id' => $familyId,
            'refresh_token_id' => $token->refreshTokenId?->value,
            'revoked' => $token->revoked,
        ];
    }

    /**
     * Introspección de un refresh token: incluye consumo, familia y rotación.
     *
     * @return array{active:bool, token_type:?string, client_id:?string, identifier:?string, identity_type:?string, scopes:array<int,string>, issued_at:?int, expires_at:?int, consumed:bool, consumed_at:?int, family_id:?string, rotated_to:?string, revoked:bool}
     */
    public function introspectRefreshToken(string $refreshTokenId, ?int $nowTs = null): array
    {
        $token = $this->repository->findRefreshToken($refreshTokenId);
        if (! $token instanceof OpaqueRefreshToken) {
            return [
                'active' => false, 'token_type' => null, 'client_id' => null,
                'identifier' => null, 'identity_type' => null, 'scopes' => [],
                'issued_at' => null, 'expires_at' => null, 'consumed' => false,
                'consumed_at' => null, 'family_id' => null, 'rotated_to' => null, 'revoked' => false,
            ];
        }
        $now = $nowTs ?? time();
        $active = $token->isActive($now);
        return [
            'active' => $active,
            'token_type' => 'refresh_token',
            'client_id' => $token->clientId,
            'identifier' => $token->reference->identifier->value,
            'identity_type' => $token->reference->type,
            'scopes' => $token->scopes,
            'issued_at' => $token->issuedAt,
            'expires_at' => $token->expiresAt,
            'consumed' => $token->consumed,
            'consumed_at' => $token->consumedAt,
            'family_id' => $token->familyId,
            'rotated_to' => $token->rotatedTo?->value,
            'revoked' => $token->revoked,
        ];
    }
}
