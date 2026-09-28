<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

interface OidcJwksCacheInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function getKey(string $kid): ?array;

    /**
     * @param array<string, mixed> $jwk
     */
    public function saveKey(string $kid, array $jwk): void;
}
