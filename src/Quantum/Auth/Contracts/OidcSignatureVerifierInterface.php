<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

interface OidcSignatureVerifierInterface
{
    /**
     * @param string $compactJws JWT compact serializado (header.payload.signature)
     * @param OidcJwksCacheInterface $jwksCache Cache con kid → JWK entry
     * @param string|null $overriddenKid Si no está en header, usar este
     * @return array{valid:bool, alg:string|null, matched_kid:string|null, error:string|null}
     */
    public function verifyIdTokenSignature(string $compactJws, OidcJwksCacheInterface $jwksCache, ?string $overriddenKid = null): array;
}
