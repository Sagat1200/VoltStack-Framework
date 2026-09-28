<?php

declare(strict_types=1);

namespace Quantum\Auth\Passwords;

final readonly class PasswordRotationReceipt
{
    public function __construct(
        public string $identityId,
        public string $previousHash,
        public string $newHash,
        public int $rotatedAt,
        public string $rotatedByActorSessionPublicId,
        public ?string $reason = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            identityId: (string) ($data['identity_id'] ?? ''),
            previousHash: (string) ($data['previous_hash'] ?? ''),
            newHash: (string) ($data['new_hash'] ?? ''),
            rotatedAt: isset($data['rotated_at']) && is_int($data['rotated_at']) ? (int) $data['rotated_at'] : 0,
            rotatedByActorSessionPublicId: (string) ($data['rotated_by_actor_session_public_id'] ?? ''),
            reason: isset($data['reason']) && is_string($data['reason']) && trim($data['reason']) !== '' ? trim($data['reason']) : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'identity_id' => $this->identityId,
            'previous_hash' => $this->previousHash,
            'new_hash' => $this->newHash,
            'rotated_at' => $this->rotatedAt,
            'rotated_by_actor_session_public_id' => $this->rotatedByActorSessionPublicId,
            'reason' => $this->reason,
        ];
    }
}
