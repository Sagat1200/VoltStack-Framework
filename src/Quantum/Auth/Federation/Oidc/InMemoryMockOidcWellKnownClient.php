<?php

declare(strict_types=1);

namespace Quantum\Auth\Federation\Oidc;

use Quantum\Auth\Contracts\OidcWellKnownClientInterface;

/**
 * @internal skeleton V1 devuelve well-known hardcodeado sin HTTP real para tests.
 */
final class InMemoryMockOidcWellKnownClient implements OidcWellKnownClientInterface
{
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
}
