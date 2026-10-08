<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

final readonly class RecoveryStartResult
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public bool $accepted,
        public RecoveryPurpose $purpose,
        public bool $issued,
        public ?int $expiresAt = null,
        public ?string $delivery = null,
        public ?string $token = null,
        public array $metadata = [],
    ) {}
}
