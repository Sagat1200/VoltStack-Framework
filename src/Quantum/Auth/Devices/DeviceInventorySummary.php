<?php

declare(strict_types=1);

namespace Quantum\Auth\Devices;

final readonly class DeviceInventorySummary
{
    /**
     * @param list<string> $sessionPublicIds
     */
    public function __construct(
        public string $deviceReference,
        public string $trustState,
        public int $sessionCount,
        public int $currentSessionCount,
        public bool $current,
        public bool $hasTrustedDevice,
        public ?string $trustedDevicePublicId,
        public array $sessionPublicIds,
        public ?int $lastSeenAt,
        public bool $canRevokeSessions = true,
        public bool $canForgetTrustedDevice = false,
        public bool $requiresReauthentication = false,
        public string $managementScope = 'current',
        public string $managementMode = 'direct',
        public string $managementAuthority = 'session_owner',
        public string $managementOwnershipProof = 'current_session',
        public string $managementSensitivity = 'standard',
        public string $managementReasonCode = 'current_device_management',
        public ?string $label = null,
        public ?string $clientFamily = null,
        public ?string $clientPlatform = null,
        public ?string $deviceKind = null,
    ) {}
}
