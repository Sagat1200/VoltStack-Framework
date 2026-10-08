<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

use Quantum\Auth\Identity\IdentityIdentifier;
use Quantum\Auth\Identity\IdentityReference;

final readonly class PasswordResetTokenRecord
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public string $id,
        public string $secretHash,
        public IdentityReference $reference,
        public string $identifier,
        public int $issuedAt,
        public int $expiresAt,
        public ?int $consumedAt = null,
        public array $attributes = [],
    ) {}

    public function isConsumed(): bool
    {
        return $this->consumedAt !== null && $this->consumedAt > 0;
    }

    public function isExpired(?int $now = null): bool
    {
        $now ??= time();

        return $this->expiresAt > 0 && $now >= $this->expiresAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'secret_hash' => $this->secretHash,
            'reference_type' => $this->reference->type,
            'reference_identifier' => $this->reference->identifier->value,
            'identifier' => $this->identifier,
            'issued_at' => $this->issuedAt,
            'expires_at' => $this->expiresAt,
            'consumed_at' => $this->consumedAt,
            'attributes' => $this->attributes,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            secretHash: (string) ($data['secret_hash'] ?? ''),
            reference: new IdentityReference(
                new IdentityIdentifier((string) ($data['reference_identifier'] ?? '')),
                (string) ($data['reference_type'] ?? 'user'),
            ),
            identifier: (string) ($data['identifier'] ?? ''),
            issuedAt: isset($data['issued_at']) && is_int($data['issued_at']) ? $data['issued_at'] : 0,
            expiresAt: isset($data['expires_at']) && is_int($data['expires_at']) ? $data['expires_at'] : 0,
            consumedAt: isset($data['consumed_at']) && is_int($data['consumed_at']) && $data['consumed_at'] > 0
                ? $data['consumed_at']
                : null,
            attributes: is_array($data['attributes'] ?? null) ? $data['attributes'] : [],
        );
    }
}
