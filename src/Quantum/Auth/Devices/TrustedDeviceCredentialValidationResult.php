<?php

declare(strict_types=1);

namespace Quantum\Auth\Devices;

final readonly class TrustedDeviceCredentialValidationResult
{
    public function __construct(
        public ?TrustedDevice $device = null,
        public bool $credentialPresented = false,
        public bool $invalid = false,
        public bool $replayed = false,
    ) {}

    public function isValid(): bool
    {
        return $this->device !== null;
    }
}
