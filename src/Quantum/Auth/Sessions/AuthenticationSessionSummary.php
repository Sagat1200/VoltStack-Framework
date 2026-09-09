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
        public ?int $lastActivityAt,
        public bool $current,
        public ?string $label = null,
        public ?string $clientFamily = null,
        public ?string $clientPlatform = null,
        public ?string $deviceKind = null,
        public ?string $ipPrefix = null,
        public bool $canRevoke = true,
        public bool $requiresReauthentication = false,
    ) {}
}
