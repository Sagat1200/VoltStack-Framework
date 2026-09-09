<?php

declare(strict_types=1);

namespace Quantum\Auth\Devices;

use Quantum\Auth\Identity\IdentityReference;

final readonly class TrustedDevice
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public TrustedDevicePublicId $publicId,
        public IdentityReference $reference,
        public string $deviceReference,
        public int $issuedAt,
        public ?int $expiresAt = null,
        public ?int $lastUsedAt = null,
        public array $attributes = [],
    ) {}

    public function isExpired(?int $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return ($now ?? time()) >= $this->expiresAt;
    }

    public function label(): ?string
    {
        $label = $this->attributes['label'] ?? null;

        return is_string($label) && trim($label) !== ''
            ? trim($label)
            : null;
    }
}
