<?php

declare(strict_types=1);

namespace Quantum\Auth\Sessions;

final readonly class AuthenticationSessionSummary
{
    public function __construct(
        public string $publicId,
        public string $method,
        public int $issuedAt,
        public ?int $expiresAt,
        public bool $current,
        public ?string $label = null,
    ) {
    }
}
