<?php

declare(strict_types=1);

namespace Quantum\Auth\Authenticators;

use Quantum\Auth\Context\AuthenticationContext;
use Quantum\Auth\Contracts\AuthenticatorInterface;
use Quantum\Auth\Contracts\IdentityProviderInterface;
use Quantum\Auth\Contracts\OidcJwksCacheInterface;
use Quantum\Auth\Contracts\OidcWellKnownClientInterface;
use Quantum\Auth\Decisions\AuthenticationDecision;
use Quantum\Auth\Federation\Oidc\OidcIdentityTokenValidator;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Auth\Runtime\AuthenticationOperationContext;
use Quantum\Auth\Support\AuthenticationAssurance;

final class OidcAuthenticator implements AuthenticatorInterface
{
    public function __construct(
        private readonly OidcIdentityTokenValidator $tokenValidator,
        private readonly IdentityProviderInterface $identityProvider,
        private readonly ?OidcJwksCacheInterface $jwksCache = null,
        private readonly array $expectedClaims = [],
        private readonly ?OidcWellKnownClientInterface $wellKnownClient = null,
        private readonly bool $refreshOnKidMiss = true,
        private readonly bool $refreshOnExpiredCache = true,
    ) {
    }

    public function supports(AuthenticationOperationContext $context): bool
    {
        $credentials = $context->request->attributes['credentials'] ?? [];
        if (! is_array($credentials)) {
            return false;
        }
        $mechanism = $credentials['mechanism'] ?? null;
        if ($mechanism === 'oidc') {
            return true;
        }
        $idToken = $credentials['id_token'] ?? $credentials['compact_jws'] ?? null;
        return is_string($idToken) && trim($idToken) !== '';
    }

    public function authenticate(AuthenticationOperationContext $context): AuthenticationDecision
    {
        $credentials = $context->request->attributes['credentials'] ?? [];
        if (! is_array($credentials)) {
            return $this->reject('invalid_credentials_shape');
        }

        $compactJws = is_string($credentials['id_token'] ?? null)
            ? trim($credentials['id_token'])
            : (is_string($credentials['compact_jws'] ?? null) ? trim($credentials['compact_jws']) : '');

        if ($compactJws === '') {
            return $this->reject('oidc_id_token_missing');
        }

        $decoded = $this->tokenValidator->decodeCompactJwsHeaderAndPayload($compactJws);
        if ($decoded === null) {
            return $this->reject('oidc_jws_malformed');
        }

        $header = $decoded['header'];
        $payload = $decoded['payload'];

        $this->refreshJwksCacheIfNeeded($credentials, $header);
        $expectations = $this->buildExpectations($credentials, $compactJws, $header);

        $validationResult = $this->tokenValidator->validateAll($payload, $expectations);
        if (
            ! $validationResult['valid']
            && $this->shouldRetryAfterJwksRefresh($validationResult['reason_codes'] ?? [])
            && $this->refreshJwksCache($credentials, $header)
        ) {
            $validationResult = $this->tokenValidator->validateAll($payload, $expectations);
        }

        if (! $validationResult['valid']) {
            $reasonCodes = is_array($validationResult['reason_codes'] ?? null)
                ? array_values($validationResult['reason_codes'])
                : [];
            $primary = $reasonCodes[0] ?? 'oidc_token_validation_failed';
            return $this->reject($primary, ['reason_codes' => $reasonCodes]);
        }

        $sub = is_string($payload['sub'] ?? null) ? trim($payload['sub']) : '';
        if ($sub === '') {
            return $this->reject('oidc_sub_missing');
        }

        $identity = $this->identityProvider->findByIdentifier($sub);
        if ($identity === null) {
            return $this->reject('oidc_identity_not_resolved', ['sub' => $sub]);
        }

        $amr = ['federated', 'oidc'];
        $amrClaim = $payload['amr'] ?? null;
        if (is_array($amrClaim)) {
            foreach ($amrClaim as $v) {
                if (is_string($v) && $v !== '') {
                    $amr[] = $v;
                }
            }
        }
        $amr = array_values(array_unique($amr));

        $assuranceProfile = AuthenticationAssurance::composeAssuranceFromAmr(
            new \Quantum\Auth\Runtime\AuthenticationMethodReferenceList($amr)
        );

        $attributes = [
            'amr' => $amr,
            'federated_issuer' => is_string($payload['iss'] ?? null) ? $payload['iss'] : null,
            'federated_provider' => 'oidc',
            'oidc_subject' => $sub,
            'oidc_nonce_present' => isset($payload['nonce']) && is_string($payload['nonce']),
            'assurance_profile' => $assuranceProfile->value,
        ];

        $enriched = AuthenticationAssurance::enrichAttributes($attributes, 'oidc');

        $authContext = new AuthenticationContext(
            identity: $identity,
            reference: new IdentityReference(
                identifier: $identity->identifier(),
                type: $identity->type(),
            ),
            requestId: $context->request->requestId,
            method: 'oidc',
            attributes: $enriched,
        );

        return AuthenticationDecision::authenticated(
            $authContext,
            [
                'authenticator' => 'oidc',
                'reason' => 'oidc_ok',
                'sub' => $sub,
                'iss' => is_string($payload['iss'] ?? null) ? $payload['iss'] : null,
                'aud' => $payload['aud'] ?? null,
                'alg' => is_string($header['alg'] ?? null) ? $header['alg'] : null,
                'kid' => is_string($header['kid'] ?? null) ? $header['kid'] : null,
                'amr' => $amr,
                'federated' => true,
            ],
        );
    }

    /**
     * @param array<string, mixed> $credentials
     * @param array<string, mixed> $header
     * @return array<string, mixed>
     */
    private function buildExpectations(array $credentials, string $compactJws, array $header): array
    {
        $expectations = [];

        if (isset($this->expectedClaims['issuer']) && is_string($this->expectedClaims['issuer'])) {
            $expectations['issuer'] = $this->expectedClaims['issuer'];
        } elseif (isset($credentials['expected_issuer']) && is_string($credentials['expected_issuer'])) {
            $expectations['issuer'] = $credentials['expected_issuer'];
        }

        if (array_key_exists('audience', $this->expectedClaims)) {
            $expectations['audience'] = $this->expectedClaims['audience'];
        } elseif (array_key_exists('expected_audience', $credentials)) {
            $expectations['audience'] = $credentials['expected_audience'];
        }

        if (isset($credentials['now_ts']) && is_int($credentials['now_ts'])) {
            $expectations['now_ts'] = $credentials['now_ts'];
        }

        $leeway = 0;
        if (isset($this->expectedClaims['clock_skew_leeway']) && is_int($this->expectedClaims['clock_skew_leeway'])) {
            $leeway = $this->expectedClaims['clock_skew_leeway'];
        } elseif (isset($credentials['clock_skew_leeway']) && is_int($credentials['clock_skew_leeway'])) {
            $leeway = $credentials['clock_skew_leeway'];
        }
        if ($leeway > 0) {
            $expectations['clock_skew_leeway'] = $leeway;
        }

        if (isset($credentials['expected_nonce']) && is_string($credentials['expected_nonce'])) {
            $expectations['nonce'] = $credentials['expected_nonce'];
        }

        if ($this->jwksCache !== null) {
            $expectations['jwks_cache'] = $this->jwksCache;
            $expectations['compact_jws'] = $compactJws;
            if (isset($credentials['kid']) && is_string($credentials['kid'])) {
                $expectations['kid'] = $credentials['kid'];
            } elseif (isset($header['kid']) && is_string($header['kid'])) {
                $expectations['kid'] = $header['kid'];
            }
        }

        return $expectations;
    }

    /**
     * @param array<string, mixed> $credentials
     * @param array<string, mixed> $header
     */
    private function refreshJwksCacheIfNeeded(array $credentials, array $header): void
    {
        if ($this->jwksCache === null || $this->wellKnownClient === null) {
            return;
        }

        $kid = $this->resolvedKid($credentials, $header);
        if ($kid === null) {
            return;
        }

        if ($this->refreshOnExpiredCache && $this->jwksCacheExpired()) {
            $this->refreshJwksCache($credentials, $header);

            return;
        }

        if ($this->refreshOnKidMiss && $this->jwksCache->getKey($kid) === null) {
            $this->refreshJwksCache($credentials, $header);
        }
    }

    /**
     * @param array<string, mixed> $credentials
     * @param array<string, mixed> $header
     */
    private function refreshJwksCache(array $credentials, array $header): bool
    {
        if ($this->jwksCache === null || $this->wellKnownClient === null) {
            return false;
        }

        $issuer = $this->resolvedIssuer($credentials);
        if ($issuer === null) {
            return false;
        }

        try {
            $metadata = $this->wellKnownClient->fetchConfiguration($issuer);
            $jwks = $this->wellKnownClient->fetchJwksByUri($metadata->jwksUri);
        } catch (\Throwable) {
            return false;
        }

        $keys = is_array($jwks['keys'] ?? null) ? array_values($jwks['keys']) : [];
        $savedAny = false;

        foreach ($keys as $jwk) {
            if (! is_array($jwk)) {
                continue;
            }

            $kid = $this->nonEmptyString($jwk['kid'] ?? null);
            if ($kid === null) {
                continue;
            }

            $this->jwksCache->saveKey($kid, $jwk);
            $savedAny = true;
        }

        if (method_exists($this->jwksCache, 'markFetchedNow')) {
            $this->jwksCache->markFetchedNow();
        }

        return $savedAny;
    }

    /**
     * @param list<mixed> $reasonCodes
     */
    private function shouldRetryAfterJwksRefresh(array $reasonCodes): bool
    {
        if (! $this->refreshOnKidMiss || $this->wellKnownClient === null || $this->jwksCache === null) {
            return false;
        }

        foreach ($reasonCodes as $reasonCode) {
            if ($reasonCode === 'signature_invalid:jwk_missing') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $credentials
     * @param array<string, mixed> $header
     */
    private function resolvedKid(array $credentials, array $header): ?string
    {
        return $this->nonEmptyString($credentials['kid'] ?? null)
            ?? $this->nonEmptyString($header['kid'] ?? null);
    }

    /**
     * @param array<string, mixed> $credentials
     */
    private function resolvedIssuer(array $credentials): ?string
    {
        return $this->nonEmptyString($credentials['expected_issuer'] ?? null)
            ?? $this->nonEmptyString($credentials['issuer'] ?? null)
            ?? $this->nonEmptyString($this->expectedClaims['issuer'] ?? null);
    }

    private function jwksCacheExpired(): bool
    {
        if (! method_exists($this->jwksCache, 'hasExpired')) {
            return false;
        }

        return (bool) $this->jwksCache->hasExpired();
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function reject(string $reason, array $extra = []): AuthenticationDecision
    {
        return AuthenticationDecision::rejected(array_merge([
            'authenticator' => 'oidc',
            'reason' => $reason,
        ], $extra));
    }
}
