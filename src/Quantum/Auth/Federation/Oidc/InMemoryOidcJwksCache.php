<?php

declare(strict_types=1);

namespace Quantum\Auth\Federation\Oidc;

use Quantum\Auth\Contracts\OidcJwksCacheInterface;

final class InMemoryOidcJwksCache implements OidcJwksCacheInterface
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $keys = [];

    /**
     * @return array<string, mixed>|null
     */
    public function getKey(string $kid): ?array
    {
        if (trim($kid) === '') {
            return null;
        }
        return $this->keys[$kid] ?? null;
    }

    /**
     * @param array<string, mixed> $jwk
     */
    public function saveKey(string $kid, array $jwk): void
    {
        if (trim($kid) === '') {
            return;
        }
        $this->keys[$kid] = $jwk;
    }
}
