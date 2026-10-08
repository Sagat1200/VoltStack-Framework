<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Authenticators\OidcAuthenticator;
use Quantum\Auth\Context\AuthenticationRequest;
use Quantum\Auth\Contracts\IdentityProviderInterface;
use Quantum\Auth\Contracts\OidcJwksCacheInterface;
use Quantum\Auth\Contracts\OidcSignatureVerifierInterface;
use Quantum\Auth\Contracts\OidcWellKnownClientInterface;
use Quantum\Auth\Federation\Oidc\InMemoryOidcJwksCache;
use Quantum\Auth\Federation\Oidc\OidcIdentityTokenValidator;
use Quantum\Auth\Federation\Oidc\OidcProviderMetadata;
use Quantum\Auth\Identity\GenericIdentity;
use Quantum\Auth\Identity\IdentityIdentifier;
use Quantum\Auth\Identity\IdentityInterface;
use Quantum\Auth\Identity\IdentitySecurityState;
use Quantum\Auth\Runtime\AuthenticationOperationContext;

final class Bloque084OidcWellKnownHttpTest extends TestCase
{
    private static function b64uEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @param array<string,mixed> $header
     * @param array<string,mixed> $payload
     */
    private static function makeCompactJws(array $header, array $payload): string
    {
        return self::b64uEncode(json_encode($header, JSON_THROW_ON_ERROR))
            . '.'
            . self::b64uEncode(json_encode($payload, JSON_THROW_ON_ERROR))
            . '.sig';
    }

    public function test_oidc_authenticator_refreshes_jwks_on_kid_miss_and_retries_validation(): void
    {
        $cache = new InMemoryOidcJwksCache(3600);
        $client = new class () implements OidcWellKnownClientInterface {
            public int $configurationFetches = 0;
            public int $jwksFetches = 0;

            public function fetchConfiguration(string $issuerUrl): OidcProviderMetadata
            {
                $this->configurationFetches++;

                return new OidcProviderMetadata(
                    issuer: rtrim($issuerUrl, '/'),
                    authorizationEndpoint: rtrim($issuerUrl, '/') . '/auth',
                    tokenEndpoint: rtrim($issuerUrl, '/') . '/token',
                    userinfoEndpoint: rtrim($issuerUrl, '/') . '/userinfo',
                    jwksUri: rtrim($issuerUrl, '/') . '/jwks',
                    idTokenSigningAlgValuesSupported: ['RS256'],
                );
            }

            public function fetchJwksByUri(string $jwksUri): array
            {
                $this->jwksFetches++;

                return [
                    'keys' => [[
                        'kty' => 'RSA',
                        'kid' => 'kid-refresh-01',
                        'n' => 'N',
                        'e' => 'AQAB',
                    ]],
                ];
            }
        };

        $validator = new OidcIdentityTokenValidator(new class () implements OidcSignatureVerifierInterface {
            public function verifyIdTokenSignature(string $compactJws, OidcJwksCacheInterface $jwksCache, ?string $overriddenKid = null): array
            {
                $kid = $overriddenKid ?? 'kid-refresh-01';

                return [
                    'valid' => $jwksCache->getKey($kid) !== null,
                    'alg' => 'RS256',
                    'matched_kid' => $kid,
                    'error' => $jwksCache->getKey($kid) !== null ? null : 'jwk_missing',
                ];
            }
        });

        $authenticator = new OidcAuthenticator(
            tokenValidator: $validator,
            identityProvider: $this->identityProvider(),
            jwksCache: $cache,
            expectedClaims: [
                'issuer' => 'https://issuer.example.com',
                'audience' => 'voltstack-client',
            ],
            wellKnownClient: $client,
            refreshOnKidMiss: true,
            refreshOnExpiredCache: true,
        );

        $token = self::makeCompactJws(
            ['alg' => 'RS256', 'kid' => 'kid-refresh-01'],
            [
                'iss' => 'https://issuer.example.com',
                'sub' => 'federated-user',
                'aud' => 'voltstack-client',
                'exp' => time() + 300,
            ],
        );

        $decision = $authenticator->authenticate(new AuthenticationOperationContext(
            operation: 'authenticate',
            request: new AuthenticationRequest(
                requestId: 'req-oidc-kid-refresh',
                transport: 'http',
                attributes: [
                    'credentials' => [
                        'mechanism' => 'oidc',
                        'id_token' => $token,
                    ],
                ],
            ),
        ));

        self::assertTrue($decision->isAuthenticated(), json_encode($decision->metadata, JSON_THROW_ON_ERROR));
        self::assertSame(1, $client->configurationFetches);
        self::assertSame(1, $client->jwksFetches);
        self::assertNotNull($cache->getKey('kid-refresh-01'));
        self::assertSame('oidc', $decision->context?->method);
    }

    public function test_oidc_authenticator_refreshes_expired_jwks_cache_before_validation(): void
    {
        $cache = new InMemoryOidcJwksCache(1);
        $cache->saveKey('kid-expired-01', [
            'kty' => 'RSA',
            'kid' => 'kid-expired-01',
            'n' => 'OLD',
            'e' => 'AQAB',
        ]);
        $cache->markFetchedNow(time() - 100);

        $client = new class () implements OidcWellKnownClientInterface {
            public int $configurationFetches = 0;
            public int $jwksFetches = 0;

            public function fetchConfiguration(string $issuerUrl): OidcProviderMetadata
            {
                $this->configurationFetches++;

                return new OidcProviderMetadata(
                    issuer: rtrim($issuerUrl, '/'),
                    authorizationEndpoint: rtrim($issuerUrl, '/') . '/auth',
                    tokenEndpoint: rtrim($issuerUrl, '/') . '/token',
                    userinfoEndpoint: rtrim($issuerUrl, '/') . '/userinfo',
                    jwksUri: rtrim($issuerUrl, '/') . '/jwks',
                    idTokenSigningAlgValuesSupported: ['RS256'],
                );
            }

            public function fetchJwksByUri(string $jwksUri): array
            {
                $this->jwksFetches++;

                return [
                    'keys' => [[
                        'kty' => 'RSA',
                        'kid' => 'kid-expired-01',
                        'n' => 'NEW',
                        'e' => 'AQAB',
                    ]],
                ];
            }
        };

        $validator = new OidcIdentityTokenValidator(new class () implements OidcSignatureVerifierInterface {
            public function verifyIdTokenSignature(string $compactJws, OidcJwksCacheInterface $jwksCache, ?string $overriddenKid = null): array
            {
                $kid = $overriddenKid ?? 'kid-expired-01';
                $key = $jwksCache->getKey($kid);

                return [
                    'valid' => is_array($key) && ($key['n'] ?? null) === 'NEW',
                    'alg' => 'RS256',
                    'matched_kid' => $kid,
                    'error' => is_array($key) && ($key['n'] ?? null) === 'NEW' ? null : 'jwk_missing',
                ];
            }
        });

        $authenticator = new OidcAuthenticator(
            tokenValidator: $validator,
            identityProvider: $this->identityProvider(),
            jwksCache: $cache,
            expectedClaims: [
                'issuer' => 'https://issuer.example.com',
                'audience' => 'voltstack-client',
            ],
            wellKnownClient: $client,
            refreshOnKidMiss: true,
            refreshOnExpiredCache: true,
        );

        $token = self::makeCompactJws(
            ['alg' => 'RS256', 'kid' => 'kid-expired-01'],
            [
                'iss' => 'https://issuer.example.com',
                'sub' => 'federated-user',
                'aud' => 'voltstack-client',
                'exp' => time() + 300,
            ],
        );

        $decision = $authenticator->authenticate(new AuthenticationOperationContext(
            operation: 'authenticate',
            request: new AuthenticationRequest(
                requestId: 'req-oidc-expired-refresh',
                transport: 'http',
                attributes: [
                    'credentials' => [
                        'mechanism' => 'oidc',
                        'id_token' => $token,
                    ],
                ],
            ),
        ));

        self::assertTrue($decision->isAuthenticated(), json_encode($decision->metadata, JSON_THROW_ON_ERROR));
        self::assertSame(1, $client->configurationFetches);
        self::assertSame(1, $client->jwksFetches);
        self::assertSame('NEW', $cache->getKey('kid-expired-01')['n'] ?? null);
        self::assertGreaterThan(time() - 5, $cache->getFetchedAt());
    }

    private function identityProvider(): IdentityProviderInterface
    {
        return new class () implements IdentityProviderInterface {
            public function findByIdentifier(string $identifier): ?IdentityInterface
            {
                if ($identifier !== 'federated-user') {
                    return null;
                }

                return new GenericIdentity(
                    new IdentityIdentifier($identifier),
                    'user',
                    [],
                );
            }

            public function passwordHashFor(IdentityInterface $identity): ?string
            {
                return null;
            }

            public function securityStateFor(IdentityInterface $identity): IdentitySecurityState
            {
                return IdentitySecurityState::Active;
            }
        };
    }
}
