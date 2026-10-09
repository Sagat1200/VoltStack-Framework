<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

final readonly class RecoveryNotification
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public RecoveryNotificationType $type,
        public RecoveryPurpose $purpose,
        public string $identityType,
        public string $identityId,
        public string $destination,
        public string $channel,
        public string $transport,
        public int $createdAt,
        public array $payload = [],
        public array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'purpose' => $this->purpose->value,
            'identity_type' => $this->identityType,
            'identity_id' => $this->identityId,
            'destination' => $this->destination,
            'channel' => $this->channel,
            'transport' => $this->transport,
            'created_at' => $this->createdAt,
            'payload' => $this->payload,
            'metadata' => $this->metadata,
        ];
    }
}
