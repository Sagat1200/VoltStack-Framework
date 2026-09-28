<?php

declare(strict_types=1);

namespace Quantum\Auth\Federation\Oidc;

use Quantum\Auth\Contracts\OidcJwksCacheInterface;

/**
 * @internal skeleton V1 para 082 — NO realiza validación criptográfica real de firma,
 *           únicamente chequeos estructurales sobre claims del payload + presence kid en cache.
 *           Firma signature bypass return true simulado.
 * @todo integrar validación real de JOSE con RS256/ES256 sobre JWKS en 083.
 */
final class OidcIdentityTokenValidator
{
    /**
     * @param array<string, mixed> $tokenClaims
     */
    public function validateIssuer(array $tokenClaims, string $expectedIssuer): bool
    {
        $iss = $tokenClaims['iss'] ?? null;
        return is_string($iss) && rtrim($iss, '/') === rtrim($expectedIssuer, '/');
    }

    /**
     * @param array<string, mixed> $tokenClaims
     * @param string|list<string> $expectedAud
     */
    public function validateAudience(array $tokenClaims, mixed $expectedAud): bool
    {
        $aud = $tokenClaims['aud'] ?? null;
        $audList = is_array($aud) ? array_values($aud) : (is_string($aud) ? [$aud] : []);
        $expectedList = is_array($expectedAud) ? array_values($expectedAud) : (is_string($expectedAud) ? [$expectedAud] : []);

        foreach ($expectedList as $e) {
            if (! is_string($e)) {
                continue;
            }
            if (in_array($e, $audList, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $tokenClaims
     */
    public function validateExpiry(array $tokenClaims, int $nowTs): bool
    {
        $exp = $tokenClaims['exp'] ?? null;
        if (! is_int($exp)) {
            return false;
        }
        return $exp > $nowTs;
    }

    /**
     * @param array<string, mixed> $tokenClaims
     */
    public function validateNonce(array $tokenClaims, string $expectedNonce): bool
    {
        $nonce = $tokenClaims['nonce'] ?? null;
        return is_string($nonce) && hash_equals($expectedNonce, $nonce);
    }

    /**
     * @param array<string, mixed> $tokenHeader
     */
    public function validateSignaturePresent(array $tokenHeader, OidcJwksCacheInterface $cache, ?string $kid = null): bool
    {
        $resolvedKid = is_string($kid) && trim($kid) !== '' ? $kid : ($tokenHeader['kid'] ?? null);
        if (! is_string($resolvedKid) || trim($resolvedKid) === '') {
            return false;
        }
        return $cache->getKey($resolvedKid) !== null;
    }

    /**
     * @param array<string, mixed> $tokenClaims
     * @param array{issuer?: string, audience?: string|list<string>, now_ts?: int, nonce?: string, kid?: string, token_header?: array<string, mixed>, jwks_cache?: OidcJwksCacheInterface} $expectations
     * @return array{valid: bool, reason_codes: list<string>}
     */
    public function validateAll(array $tokenClaims, array $expectations = []): array
    {
        $reasons = [];

        if (isset($expectations['issuer']) && is_string($expectations['issuer'])) {
            if (! $this->validateIssuer($tokenClaims, $expectations['issuer'])) {
                $reasons[] = 'issuer_mismatch';
            }
        }
        if (array_key_exists('audience', $expectations)) {
            if (! $this->validateAudience($tokenClaims, $expectations['audience'])) {
                $reasons[] = 'audience_mismatch';
            }
        }
        $now = isset($expectations['now_ts']) && is_int($expectations['now_ts']) ? $expectations['now_ts'] : time();
        if (! $this->validateExpiry($tokenClaims, $now)) {
            $reasons[] = 'token_expired';
        }
        if (isset($expectations['nonce']) && is_string($expectations['nonce'])) {
            if (! $this->validateNonce($tokenClaims, $expectations['nonce'])) {
                $reasons[] = 'nonce_mismatch';
            }
        }
        if (isset($expectations['jwks_cache']) && $expectations['jwks_cache'] instanceof OidcJwksCacheInterface) {
            $header = is_array($expectations['token_header'] ?? null) ? $expectations['token_header'] : [];
            $kid = isset($expectations['kid']) && is_string($expectations['kid']) ? $expectations['kid'] : null;
            if (! $this->validateSignaturePresent($header, $expectations['jwks_cache'], $kid)) {
                $reasons[] = 'signature_jwk_missing';
            }
        }

        return [
            'valid' => $reasons === [],
            'reason_codes' => $reasons,
        ];
    }
}
