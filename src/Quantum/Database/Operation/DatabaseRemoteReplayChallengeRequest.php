<?php

declare(strict_types=1);

namespace Quantum\Database\Operation;

final readonly class DatabaseRemoteReplayChallengeRequest
{
    /**
     * @param array<string, mixed> $confirmationEvidence
     */
    public function __construct(
        public string $challengeId,
        public string $challengeNonce,
        public string $requestedAt,
        public ?string $currentNodeId,
        public ?string $sourceNodeId,
        public string $keyHash,
        public string $requestId,
        public string $connectionName,
        public string $logicalTarget,
        public string $operationFingerprint,
        public string $confirmationFingerprint,
        public string $validationMode,
        public array $confirmationEvidence = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'challenge_id' => $this->challengeId,
            'challenge_nonce' => $this->challengeNonce,
            'requested_at' => $this->requestedAt,
            'current_node_id' => $this->currentNodeId,
            'source_node_id' => $this->sourceNodeId,
            'key_hash' => $this->keyHash,
            'request_id' => $this->requestId,
            'connection_name' => $this->connectionName,
            'logical_target' => $this->logicalTarget,
            'operation_fingerprint' => $this->operationFingerprint,
            'confirmation_fingerprint' => $this->confirmationFingerprint,
            'validation_mode' => $this->validationMode,
            'confirmation_evidence' => $this->confirmationEvidence,
        ];
    }
}
