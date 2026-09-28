<?php

declare(strict_types=1);

namespace Quantum\Authorization\Manifest\Contracts;

use Quantum\Authorization\Metadata\AuthorizationMetadataPayload;

interface AuthorizationManifestStoreInterface
{
    public function has(string $fingerprint): bool;

    public function get(string $fingerprint): ?AuthorizationMetadataPayload;

    public function put(AuthorizationMetadataPayload $payload): void;

    public function forget(string $fingerprint): void;

    public function clear(): int;
}
