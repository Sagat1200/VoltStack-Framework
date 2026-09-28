<?php

declare(strict_types=1);

namespace Quantum\Auth\Federation\Oidc;

/**
 * @internal skeleton V1 para 082 — sin cliente HTTP real, validación estructural.
 */
final readonly class OidcProviderMetadata
{
    /**
     * @param list<string> $idTokenSigningAlgValuesSupported
     */
    public function __construct(
        public string $issuer,
        public string $authorizationEndpoint,
        public string $tokenEndpoint,
        public string $userinfoEndpoint,
        public string $jwksUri,
        public array $idTokenSigningAlgValuesSupported = ['RS256'],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'issuer' => $this->issuer,
            'authorization_endpoint' => $this->authorizationEndpoint,
            'token_endpoint' => $this->tokenEndpoint,
            'userinfo_endpoint' => $this->userinfoEndpoint,
            'jwks_uri' => $this->jwksUri,
            'id_token_signing_alg_values_supported' => $this->idTokenSigningAlgValuesSupported,
        ];
    }
}
