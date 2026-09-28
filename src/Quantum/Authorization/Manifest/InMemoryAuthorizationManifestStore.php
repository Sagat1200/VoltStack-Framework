<?php

declare(strict_types=1);

namespace Quantum\Authorization\Manifest;

use Quantum\Authorization\Manifest\Contracts\AuthorizationManifestStoreInterface;
use Quantum\Authorization\Metadata\AuthorizationMetadataPayload;

final class InMemoryAuthorizationManifestStore implements AuthorizationManifestStoreInterface
{
    /**
     * @var array<string, AuthorizationManifestEntry>
     */
    private array $entries = [];

    public function has(string $fingerprint): bool
    {
        return isset($this->entries[$fingerprint]);
    }

    public function get(string $fingerprint): ?AuthorizationMetadataPayload
    {
        if (! isset($this->entries[$fingerprint])) {
            return null;
        }

        return $this->entries[$fingerprint]->payload;
    }

    public function put(AuthorizationMetadataPayload $payload): void
    {
        $fingerprint = $payload->fingerprint();
        $now = time();

        if (isset($this->entries[$fingerprint])) {
            $this->entries[$fingerprint] = new AuthorizationManifestEntry(
                fingerprint: $fingerprint,
                payload: $payload,
                createdAt: $this->entries[$fingerprint]->createdAt,
                updatedAt: $now,
                runtimeMetadata: $this->entries[$fingerprint]->runtimeMetadata,
            );

            return;
        }

        $this->entries[$fingerprint] = new AuthorizationManifestEntry(
            fingerprint: $fingerprint,
            payload: $payload,
            createdAt: $now,
            updatedAt: $now,
            runtimeMetadata: [],
        );
    }

    public function forget(string $fingerprint): void
    {
        unset($this->entries[$fingerprint]);
    }

    public function clear(): int
    {
        $count = count($this->entries);
        $this->entries = [];

        return $count;
    }
}
