<?php

declare(strict_types=1);

namespace Quantum\Auth\Devices;

final readonly class DeviceInventorySummary
{
    /**
     * @param list<string> $sessionPublicIds
     * @param list<string> $managementActorScopes
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
        public bool $managementActorGoverned = false,
        public bool $managementActorAuthorized = false,
        public ?string $managementActorAuthorizationMode = null,
        public ?string $managementActorAuthorizationReasonCode = null,
        public string $managementActorAuthority = 'session_owner',
        public string $managementActorOwnershipProof = 'current_session',
        public string $managementActorClaimsSource = 'self_service_defaults',
        public string $managementActorPrivilegeLevel = 'self_service',
        public array $managementActorScopes = [],
        public bool $managementActorCanManageSessions = false,
        public ?string $managementActorSessionAuthorizationMode = null,
        public ?string $managementActorSessionAuthorizationReasonCode = null,
        public bool $managementActorCanManageTrustedDevices = false,
        public ?string $managementActorTrustedDeviceAuthorizationMode = null,
        public ?string $managementActorTrustedDeviceAuthorizationReasonCode = null,
        public string $managementActorTargetRelation = 'self',
        public string $managementActorTargetReasonCode = 'current_identity_target',
        public ?string $managementTargetIdentity = null,
        public ?string $managementTargetType = null,
        public bool $managementTargetMatchesCurrentIdentity = true,
        public ?string $label = null,
        public ?string $clientFamily = null,
        public ?string $clientPlatform = null,
        public ?string $deviceKind = null,
    ) {}
}
