<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

final readonly class RecoveryResult
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public bool $completed,
        public RecoveryPurpose $purpose,
        public string $identityType,
        public string $identityId,
        public int $sessionsRevoked,
        public int $tokensRevoked,
        public int $trustedDevicesRevoked,
        public int $passkeysRevoked,
        public int $completedAt,
        public array $metadata = [],
    ) {}
}
