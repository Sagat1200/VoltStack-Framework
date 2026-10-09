<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Evidence;

use VoltStack\Runtime\RuntimeCapabilities;

final readonly class RuntimeCapabilityEvidenceArtifact
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private string $generationId,
        private string $driver,
        private string $platform,
        private string $fingerprint,
        private string $manifestPath,
        private array $payload,
        private int $createdAt,
    ) {
    }

    public function generationId(): string
    {
        return $this->generationId;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function platform(): string
    {
        return $this->platform;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    public function manifestPath(): string
    {
        return $this->manifestPath;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    public function createdAt(): int
    {
        return $this->createdAt;
    }

    public function capabilities(): RuntimeCapabilities
    {
        $snapshot = $this->payload['capability_snapshot'] ?? [];
        $evidence = $this->payload['capability_evidence'] ?? [];

        return new RuntimeCapabilities(
            persistent: (bool) ($snapshot['persistent'] ?? false),
            concurrent: (bool) ($snapshot['concurrent'] ?? false),
            streaming: (bool) ($snapshot['streaming'] ?? false),
            drainControl: (bool) ($snapshot['drain_control'] ?? false),
            nativeHttp: (bool) ($snapshot['native_http'] ?? false),
            evidenceLevel: (string) ($evidence['level'] ?? 'contractual'),
            nativeIntegrationVerified: (bool) ($evidence['native_integration_verified'] ?? false),
            evidenceNotes: is_array($evidence['notes'] ?? null) ? array_values($evidence['notes']) : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'generation_id' => $this->generationId,
            'driver' => $this->driver,
            'platform' => $this->platform,
            'fingerprint' => $this->fingerprint,
            'manifest_path' => $this->manifestPath,
            'created_at' => $this->createdAt,
            'capability_evidence' => $this->capabilities()->evidence(),
            'capability_snapshot' => [
                'persistent' => $this->capabilities()->persistent(),
                'concurrent' => $this->capabilities()->concurrent(),
                'streaming' => $this->capabilities()->streaming(),
                'drain_control' => $this->capabilities()->drainControl(),
                'native_http' => $this->capabilities()->nativeHttp(),
            ],
        ];
    }
}
