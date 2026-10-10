<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

use Quantum\Auth\Identity\IdentityReference;

final readonly class RecoveryAuditEvent
{
    public const ACTION_REQUESTED = 'recovery_requested';
    public const ACTION_NOT_FOUND = 'recovery_requested_identity_not_found';
    public const ACTION_UNSUPPORTED_PURPOSE = 'recovery_requested_unsupported_purpose';
    public const ACTION_REQUEST_EMPTY = 'recovery_requested_empty_identifier';
    public const ACTION_REQUEST_INVALID_PASSWORD_BASELINE = 'recovery_requested_invalid_password_baseline';
    public const ACTION_TOKEN_INVALID = 'recovery_token_invalid';
    public const ACTION_TOKEN_EXPIRED = 'recovery_token_expired';
    public const ACTION_TOKEN_CONSUMED = 'recovery_token_already_consumed';
    public const ACTION_PASSWORD_REJECTED = 'recovery_password_rejected';
    public const ACTION_PASSWORD_REUSE = 'recovery_password_reuse_detected';
    public const ACTION_EVIDENCE_REJECTED = 'recovery_evidence_rejected';
    public const ACTION_COMPLETED = 'recovery_completed';

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $eventId,
        public string $action,
        public RecoveryPurpose $purpose,
        public int $occurredAt,
        public ?IdentityReference $identity = null,
        public ?string $recoveryTokenId = null,
        public ?string $destination = null,
        public ?string $transport = null,
        public ?string $result = null,
        public array $payload = [],
        public ?string $correlationId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $identity = $this->identity instanceof IdentityReference
            ? [
                'type' => $this->identity->type,
                'identifier' => $this->identity->identifier->value,
            ]
            : null;

        return [
            'event_id' => $this->eventId,
            'action' => $this->action,
            'purpose' => $this->purpose->value,
            'occurred_at' => $this->occurredAt,
            'identity' => $identity,
            'recovery_token_id' => $this->recoveryTokenId,
            'destination' => $this->destination,
            'transport' => $this->transport,
            'result' => $this->result,
            'payload' => $this->payload,
            'correlation_id' => $this->correlationId,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $purpose = isset($data['purpose']) && is_string($data['purpose'])
            ? RecoveryPurpose::tryFrom($data['purpose'])
            : null;

        if (! $purpose instanceof RecoveryPurpose) {
            $purpose = RecoveryPurpose::PasswordReset;
        }

        $identity = null;
        if (isset($data['identity']) && is_array($data['identity'])) {
            $type = is_string($data['identity']['type'] ?? null) ? $data['identity']['type'] : '';
            $identifier = is_string($data['identity']['identifier'] ?? null) ? $data['identity']['identifier'] : '';

            if ($type !== '' && $identifier !== '') {
                $identity = new IdentityReference(
                    new \Quantum\Auth\Identity\IdentityIdentifier($identifier),
                    $type,
                );
            }
        }

        $payload = isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : [];

        $occurredAt = isset($data['occurred_at']) && is_numeric($data['occurred_at'])
            ? (int) $data['occurred_at']
            : time();

        $eventId = is_string($data['event_id'] ?? null) && trim((string) $data['event_id']) !== ''
            ? trim((string) $data['event_id'])
            : (bin2hex(random_bytes(12)));

        $action = is_string($data['action'] ?? null) && trim((string) $data['action']) !== ''
            ? trim((string) $data['action'])
            : self::ACTION_TOKEN_INVALID;

        return new self(
            eventId: $eventId,
            action: $action,
            purpose: $purpose,
            occurredAt: $occurredAt,
            identity: $identity,
            recoveryTokenId: is_string($data['recovery_token_id'] ?? null) ? $data['recovery_token_id'] : null,
            destination: is_string($data['destination'] ?? null) ? $data['destination'] : null,
            transport: is_string($data['transport'] ?? null) ? $data['transport'] : null,
            result: is_string($data['result'] ?? null) ? $data['result'] : null,
            payload: $payload,
            correlationId: is_string($data['correlation_id'] ?? null) ? $data['correlation_id'] : null,
        );
    }
}
