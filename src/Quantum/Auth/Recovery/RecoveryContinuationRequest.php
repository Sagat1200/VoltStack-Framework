<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

final readonly class RecoveryContinuationRequest
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public RecoveryPurpose $purpose,
        public string $token,
        public string $newPassword,
        public string $transport = 'internal',
        public array $attributes = [],
    ) {}
}
