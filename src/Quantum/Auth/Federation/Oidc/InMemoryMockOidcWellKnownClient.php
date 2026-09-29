<?php

declare(strict_types=1);

namespace Quantum\Auth\Federation\Oidc;

use Quantum\Auth\Contracts\OidcWellKnownClientInterface;

final class InMemoryMockOidcWellKnownClient implements OidcWellKnownClientInterface
{
    /**
     * @var array<string, list<array<string, mixed>>>
     */
    private array $mockJwks = [];

    public function fetchConfiguration(string $issuerUrl): OidcProviderMetadata
    {
        $issuer = rtrim($issuerUrl, '/');
        return new OidcProviderMetadata(
            issuer: $issuer,
            authorizationEndpoint: $issuer . '/protocol/openid-connect/auth',
            tokenEndpoint: $issuer . '/protocol/openid-connect/token',
            userinfoEndpoint: $issuer . '/protocol/openid-connect/userinfo',
            jwksUri: $issuer . '/protocol/openid-connect/certs',
            idTokenSigningAlgValuesSupported: ['RS256', 'ES256', 'PS256'],
        );
    }

    /**
     * Para tests sin HTTP. Permite hardcodear JWKS por URI sin hacer fetch remoto.
     *
     * @param string $jwksUri
     * @return array{keys:list<array<string, mixed>>}
     */
    public function fetchJwksByUri(string $jwksUri): array
    {
        if (isset($this->mockJwks[$jwksUri])) {
            return ['keys' => $this->mockJwks[$jwksUri]];
        }
        return ['keys' => []];
    }

    /**
     * @param list<array<string, mixed>> $jwksKeys
     */
    public function setMockJwks(string $jwksUri, array $jwksKeys): void
    {
        $this->mockJwks[$jwksUri] = array_values($jwksKeys);
    }
}
