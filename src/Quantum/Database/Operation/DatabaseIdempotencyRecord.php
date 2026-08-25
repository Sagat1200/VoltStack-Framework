<?php

declare(strict_types=1);

namespace Quantum\Database\Operation;

final readonly class DatabaseIdempotencyRecord
{
    public function __construct(
        public string $keyHash,
        public string $operationFingerprint,
        public string $requestId,
        public string $connectionName,
        public string $logicalTarget,
        public string $createdAt,
        public ?string $nodeId = null,
        public string $status = 'pending',
        public ?string $expiresAt = null,
        public array $confirmation = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key_hash' => $this->keyHash,
            'operation_fingerprint' => $this->operationFingerprint,
            'request_id' => $this->requestId,
            'connection_name' => $this->connectionName,
            'logical_target' => $this->logicalTarget,
            'created_at' => $this->createdAt,
            'node_id' => $this->nodeId,
            'status' => $this->status,
            'expires_at' => $this->expiresAt,
            'confirmation' => $this->confirmation,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            keyHash: (string) ($payload['key_hash'] ?? ''),
            operationFingerprint: (string) ($payload['operation_fingerprint'] ?? ''),
            requestId: (string) ($payload['request_id'] ?? ''),
            connectionName: (string) ($payload['connection_name'] ?? ''),
            logicalTarget: (string) ($payload['logical_target'] ?? ''),
            createdAt: (string) ($payload['created_at'] ?? ''),
            nodeId: isset($payload['node_id']) ? (string) $payload['node_id'] : null,
            status: (string) ($payload['status'] ?? 'pending'),
            expiresAt: isset($payload['expires_at']) ? (string) $payload['expires_at'] : null,
            confirmation: is_array($payload['confirmation'] ?? null) ? $payload['confirmation'] : [],
        );
    }

    public function withStatus(string $status, array $confirmation = []): self
    {
        return new self(
            keyHash: $this->keyHash,
            operationFingerprint: $this->operationFingerprint,
            requestId: $this->requestId,
            connectionName: $this->connectionName,
            logicalTarget: $this->logicalTarget,
            createdAt: $this->createdAt,
            nodeId: $this->nodeId,
            status: $status,
            expiresAt: $this->expiresAt,
            confirmation: $confirmation !== [] ? $confirmation : $this->confirmation,
        );
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        if ($this->expiresAt === null || trim($this->expiresAt) === '' || $this->status !== 'pending') {
            return false;
        }

        try {
            $expiresAt = new \DateTimeImmutable($this->expiresAt);
            $reference = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return false;
        }

        return $expiresAt <= $reference;
    }
}