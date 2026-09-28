<?php

declare(strict_types=1);

namespace Quantum\Authorization\Manifest;

use Quantum\Authorization\Metadata\AuthorizationMetadataPayload;

final readonly class AuthorizationManifestEntry
{
    /**
     * @param array<string, mixed> $runtimeMetadata
     */
    public function __construct(
        public string $fingerprint,
        public AuthorizationMetadataPayload $payload,
        public int $createdAt,
        public int $updatedAt,
        public array $runtimeMetadata = [],
    ) {}

    public function matches(AuthorizationMetadataPayload $payload): bool
    {
        return hash_equals($this->fingerprint, $payload->fingerprint());
    }

    /**
     * @return array{fingerprint:string,payload:array{public:bool,requirements:list<array{ability:string,subject:mixed,source:string}>,fingerprint:string},created_at:int,updated_at:int,runtime_metadata:array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'fingerprint' => $this->fingerprint,
            'payload' => $this->payload->toArray(),
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'runtime_metadata' => $this->runtimeMetadata,
        ];
    }
}
