<?php

declare(strict_types=1);

namespace Quantum\Database\Operation;

final readonly class DatabaseRemoteReplayChallengeResponse
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public string $status,
        public string $challenger = 'unknown',
        public ?string $message = null,
        public ?string $challengedNodeId = null,
        public ?string $challengeId = null,
        public ?string $challengeNonce = null,
        public ?string $respondedAt = null,
        public ?string $operationFingerprint = null,
        public ?string $confirmationFingerprint = null,
        public ?string $proofType = null,
        public ?string $proofFingerprint = null,
        public array $details = [],
    ) {}

    /**
     * @param array<string, mixed> $details
     */
    public static function verified(
        string $challenger = 'unknown',
        ?string $message = null,
        ?string $challengedNodeId = null,
        ?string $challengeId = null,
        ?string $challengeNonce = null,
        ?string $respondedAt = null,
        ?string $operationFingerprint = null,
        ?string $confirmationFingerprint = null,
        ?string $proofType = null,
        ?string $proofFingerprint = null,
        array $details = [],
    ): self {
        return new self(
            status: 'verified',
            challenger: $challenger,
            message: $message,
            challengedNodeId: $challengedNodeId,
            challengeId: $challengeId,
            challengeNonce: $challengeNonce,
            respondedAt: $respondedAt,
            operationFingerprint: $operationFingerprint,
            confirmationFingerprint: $confirmationFingerprint,
            proofType: $proofType,
            proofFingerprint: $proofFingerprint,
            details: $details,
        );
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function unavailable(
        string $challenger = 'unknown',
        ?string $message = null,
        array $details = [],
    ): self {
        return new self(
            status: 'unavailable',
            challenger: $challenger,
            message: $message,
            details: $details,
        );
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function rejected(
        string $challenger = 'unknown',
        ?string $message = null,
        ?string $challengedNodeId = null,
        ?string $challengeId = null,
        ?string $challengeNonce = null,
        ?string $respondedAt = null,
        ?string $operationFingerprint = null,
        ?string $confirmationFingerprint = null,
        ?string $proofType = null,
        ?string $proofFingerprint = null,
        array $details = [],
    ): self {
        return new self(
            status: 'rejected',
            challenger: $challenger,
            message: $message,
            challengedNodeId: $challengedNodeId,
            challengeId: $challengeId,
            challengeNonce: $challengeNonce,
            respondedAt: $respondedAt,
            operationFingerprint: $operationFingerprint,
            confirmationFingerprint: $confirmationFingerprint,
            proofType: $proofType,
            proofFingerprint: $proofFingerprint,
            details: $details,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'challenger' => $this->challenger,
            'message' => $this->message,
            'challenged_node_id' => $this->challengedNodeId,
            'challenge_id' => $this->challengeId,
            'challenge_nonce' => $this->challengeNonce,
            'responded_at' => $this->respondedAt,
            'operation_fingerprint' => $this->operationFingerprint,
            'confirmation_fingerprint' => $this->confirmationFingerprint,
            'proof_type' => $this->proofType,
            'proof_fingerprint' => $this->proofFingerprint,
            'details' => $this->details,
        ];
    }
}
