<?php

declare(strict_types=1);

namespace Quantum\Auth\Devices;

final readonly class TrustedDeviceSummary
{
    public function __construct(
        public string $publicId,
        public string $deviceReference,
        public string $trustState,
        public int $issuedAt,
        public ?int $expiresAt,
        public ?int $lastUsedAt,
        public bool $current,
        public bool $canForget = true,
        public bool $requiresReauthentication = false,
        public string $revocationScope = 'current',
        public string $revocationMode = 'direct',
        public ?string $label = null,
        public ?string $clientFamily = null,
        public ?string $clientPlatform = null,
        public ?string $deviceKind = null,
    ) {}
}
