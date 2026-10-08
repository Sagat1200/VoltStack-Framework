<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

final readonly class RecoveryRequest
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public RecoveryPurpose $purpose,
        public string $identifier,
        public string $transport = 'internal',
        public array $attributes = [],
    ) {}
}
