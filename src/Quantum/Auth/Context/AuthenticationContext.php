<?php

declare(strict_types=1);

namespace Quantum\Auth\Context;

use Quantum\Auth\Identity\IdentityInterface;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Auth\Support\AuthenticationAssurance;
use Quantum\Controllers\Security\Context\AuthenticationStrength;

final readonly class AuthenticationContext
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public IdentityInterface $identity,
        public IdentityReference $reference,
        public string $requestId,
        public string $method = 'manual',
        public array $attributes = [],
    ) {}

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function authenticationStrength(): AuthenticationStrength
    {
        return AuthenticationAssurance::resolveStrength($this->attributes, $this->method);
    }

    public function authenticationAssuranceProfile(): string
    {
        $profile = $this->attribute('authentication_assurance_profile');

        if (is_string($profile) && trim($profile) !== '') {
            return trim($profile);
        }

        return AuthenticationAssurance::profileFor($this->authenticationStrength());
    }

    public function sessionPublicId(): ?string
    {
        $publicId = $this->attribute('session_public_id');

        return is_string($publicId) && trim($publicId) !== ''
            ? trim($publicId)
            : null;
    }

    public function freshAuthenticationAt(): ?int
    {
        $freshAt = $this->attribute('authentication_fresh_at');

        if (is_int($freshAt)) {
            return $freshAt;
        }

        if (is_numeric($freshAt)) {
            return (int) $freshAt;
        }

        return null;
    }

    public function deviceReference(): ?string
    {
        $deviceReference = $this->attribute('session_device_reference');

        return is_string($deviceReference) && trim($deviceReference) !== ''
            ? trim($deviceReference)
            : null;
    }

    public function deviceTrustState(): string
    {
        $trustState = $this->attribute('session_device_trust_state');

        return is_string($trustState) && trim($trustState) !== ''
            ? trim($trustState)
            : 'unknown';
    }

    public function trustedDeviceCredentialPresent(): bool
    {
        return (bool) $this->attribute('trusted_device_credential_present', false);
    }

    public function trustedDevicePublicId(): ?string
    {
        $publicId = $this->attribute('trusted_device_public_id');

        return is_string($publicId) && trim($publicId) !== ''
            ? trim($publicId)
            : null;
    }

    public function managementAuthority(): string
    {
        $authority = $this->managementStringAttribute('auth_management_authority');

        return is_string($authority) && trim($authority) !== ''
            ? trim($authority)
            : 'session_owner';
    }

    public function managementOwnershipProof(): string
    {
        $proof = $this->managementStringAttribute('auth_management_ownership_proof');

        return is_string($proof) && trim($proof) !== ''
            ? trim($proof)
            : 'current_session';
    }

    /**
     * @return list<string>
     */
    public function managementScopes(): array
    {
        $scopes = $this->managementListAttribute('auth_management_scopes');

        if (! is_array($scopes)) {
            return [
                'current_session_management',
                'current_device_management',
                'identity_session_management',
                'identity_device_management',
            ];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $scope): string => trim((string) $scope), $scopes),
            static fn (string $scope): bool => $scope !== '',
        ));
    }

    public function managementClaimsSource(): string
    {
        $source = $this->managementStringAttribute('auth_management_claims_source');

        return is_string($source) && trim($source) !== ''
            ? trim($source)
            : 'self_service_defaults';
    }

    public function managementPrivilegeLevel(): string
    {
        $level = $this->managementStringAttribute('auth_management_privilege_level');

        if (is_string($level) && trim($level) !== '') {
            return trim($level);
        }

        return $this->managementAuthority() === 'administrative_actor'
            ? 'privileged_admin'
            : 'self_service';
    }

    public function hasGovernedManagementClaims(): bool
    {
        return $this->managementAuthority() === 'administrative_actor'
            && $this->managementClaimsSource() === 'identity_attributes';
    }

    public function canAdministrativelyManageDevices(): bool
    {
        return $this->managementDeviceAuthorizationDecision()['authorized'];
    }

    public function managementAuthorizationMode(): ?string
    {
        return $this->managementDeviceAuthorizationDecision()['authorization_mode'];
    }

    public function managementAuthorizationReasonCode(): ?string
    {
        return $this->managementDeviceAuthorizationDecision()['reason_code'];
    }

    public function canAdministrativelyManageDeviceSessions(): bool
    {
        return $this->managementSessionAuthorizationDecision()['authorized'];
    }

    public function managementSessionAuthorizationMode(): ?string
    {
        return $this->managementSessionAuthorizationDecision()['authorization_mode'];
    }

    public function managementSessionAuthorizationReasonCode(): ?string
    {
        return $this->managementSessionAuthorizationDecision()['reason_code'];
    }

    public function canAdministrativelyManageTrustedDevices(): bool
    {
        return $this->managementTrustedDeviceAuthorizationDecision()['authorized'];
    }

    public function managementTrustedDeviceAuthorizationMode(): ?string
    {
        return $this->managementTrustedDeviceAuthorizationDecision()['authorization_mode'];
    }

    public function managementTrustedDeviceAuthorizationReasonCode(): ?string
    {
        return $this->managementTrustedDeviceAuthorizationDecision()['reason_code'];
    }

    public function managementActorTargetRelation(?string $targetIdentity = null, ?string $targetType = null): string
    {
        if ($this->managementTargetMatchesCurrentIdentity($targetIdentity, $targetType)) {
            return $this->hasGovernedManagementClaims()
                ? 'self_governed'
                : 'self';
        }

        return match ($this->managementAuthorizationMode()) {
            'direct_admin' => 'direct_administrative_target',
            'delegated_admin' => 'delegated_administrative_target',
            default => $this->hasGovernedManagementClaims()
                ? 'governed_target'
                : 'unmanaged_target',
        };
    }

    public function managementActorTargetReasonCode(?string $targetIdentity = null, ?string $targetType = null): string
    {
        if ($this->managementTargetMatchesCurrentIdentity($targetIdentity, $targetType)) {
            return $this->hasGovernedManagementClaims()
                ? 'governed_current_identity_target'
                : 'current_identity_target';
        }

        return match ($this->managementAuthorizationMode()) {
            'direct_admin' => 'direct_administrative_target',
            'delegated_admin' => 'delegated_administrative_target',
            default => $this->managementAuthorizationReasonCode() ?? 'unmanaged_target',
        };
    }

    public function managementActorTargetScopeRelation(
        ?string $targetIdentity = null,
        ?string $targetType = null,
        string $scope = 'all',
    ): string {
        $normalizedScope = $this->normalizedManagementScope($scope);

        if ($this->managementTargetMatchesCurrentIdentity($targetIdentity, $targetType)) {
            if (! $this->hasGovernedManagementClaims()) {
                return 'self_service_current_identity_target';
            }

            return match ($normalizedScope) {
                'sessions' => 'self_governed_sessions_scope_target',
                'trusted-devices' => 'self_governed_trusted_devices_scope_target',
                default => 'self_governed_full_scope_target',
            };
        }

        return match ($this->managementAuthorizationModeForScope($normalizedScope)) {
            'direct_admin' => match ($normalizedScope) {
                'sessions' => 'direct_admin_sessions_scope_target',
                'trusted-devices' => 'direct_admin_trusted_devices_scope_target',
                default => 'direct_admin_full_scope_target',
            },
            'delegated_admin' => match ($normalizedScope) {
                'sessions' => 'delegated_admin_sessions_scope_target',
                'trusted-devices' => 'delegated_admin_trusted_devices_scope_target',
                default => 'delegated_admin_full_scope_target',
            },
            default => $this->hasGovernedManagementClaims()
                ? 'governed_unscoped_target'
                : 'unmanaged_target',
        };
    }

    public function managementActorTargetScopeReasonCode(
        ?string $targetIdentity = null,
        ?string $targetType = null,
        string $scope = 'all',
    ): string {
        $normalizedScope = $this->normalizedManagementScope($scope);

        if ($this->managementTargetMatchesCurrentIdentity($targetIdentity, $targetType)) {
            return $this->hasGovernedManagementClaims()
                ? $this->managementActorTargetScopeRelation($targetIdentity, $targetType, $normalizedScope)
                : 'current_identity_target';
        }

        return match ($this->managementAuthorizationModeForScope($normalizedScope)) {
            'direct_admin', 'delegated_admin' => $this->managementActorTargetScopeRelation(
                $targetIdentity,
                $targetType,
                $normalizedScope,
            ),
            default => $this->managementAuthorizationReasonCodeForScope($normalizedScope) ?? 'unmanaged_target',
        };
    }

    /**
     * @return array{authorized: bool, authorization_mode: ?string, reason_code: ?string}
     */
    private function managementDeviceAuthorizationDecision(): array
    {
        $preconditionFailure = $this->managementAuthorizationPreconditionFailure();

        if ($preconditionFailure !== null) {
            return $preconditionFailure;
        }

        if (! $this->hasAnyManagementScope([
            'admin_device_management',
            'admin_session_management',
            'admin_trusted_device_management',
        ])) {
            return [
                'authorized' => false,
                'authorization_mode' => null,
                'reason_code' => 'missing_admin_device_management_scope',
            ];
        }

        $authorizationMode = $this->resolvedManagementAuthorizationMode();

        if ($authorizationMode !== null) {
            return [
                'authorized' => true,
                'authorization_mode' => $authorizationMode,
                'reason_code' => null,
            ];
        }

        return [
            'authorized' => false,
            'authorization_mode' => null,
            'reason_code' => $this->unsupportedManagementAuthorizationReasonCode(),
        ];
    }

    /**
     * @return array{authorized: bool, authorization_mode: ?string, reason_code: ?string}
     */
    private function managementSessionAuthorizationDecision(): array
    {
        return $this->managementScopedAuthorizationDecision(
            ['admin_device_management', 'admin_session_management'],
            'missing_admin_session_management_scope',
        );
    }

    /**
     * @return array{authorized: bool, authorization_mode: ?string, reason_code: ?string}
     */
    private function managementTrustedDeviceAuthorizationDecision(): array
    {
        return $this->managementScopedAuthorizationDecision(
            ['admin_device_management', 'admin_trusted_device_management'],
            'missing_admin_trusted_device_management_scope',
        );
    }

    /**
     * @param list<string> $acceptedScopes
     * @return array{authorized: bool, authorization_mode: ?string, reason_code: ?string}
     */
    private function managementScopedAuthorizationDecision(array $acceptedScopes, string $missingScopeReasonCode): array
    {
        $preconditionFailure = $this->managementAuthorizationPreconditionFailure();

        if ($preconditionFailure !== null) {
            return $preconditionFailure;
        }

        if (! $this->hasAnyManagementScope($acceptedScopes)) {
            return [
                'authorized' => false,
                'authorization_mode' => null,
                'reason_code' => $missingScopeReasonCode,
            ];
        }

        $authorizationMode = $this->resolvedManagementAuthorizationMode();

        if ($authorizationMode !== null) {
            return [
                'authorized' => true,
                'authorization_mode' => $authorizationMode,
                'reason_code' => null,
            ];
        }

        return [
            'authorized' => false,
            'authorization_mode' => null,
            'reason_code' => $this->unsupportedManagementAuthorizationReasonCode(),
        ];
    }

    /**
     * @return array{authorized: bool, authorization_mode: ?string, reason_code: ?string}|null
     */
    private function managementAuthorizationPreconditionFailure(): ?array
    {
        if ($this->managementAuthority() !== 'administrative_actor') {
            return [
                'authorized' => false,
                'authorization_mode' => null,
                'reason_code' => 'not_administrative_actor',
            ];
        }

        if ($this->managementClaimsSource() !== 'identity_attributes') {
            return [
                'authorized' => false,
                'authorization_mode' => null,
                'reason_code' => 'unsupported_management_claims_source',
            ];
        }

        return null;
    }

    private function resolvedManagementAuthorizationMode(): ?string
    {
        return match ($this->managementPrivilegeLevel()) {
            'privileged_admin' => 'direct_admin',
            'delegated_support' => in_array($this->managementOwnershipProof(), [
                'delegated_session',
                'delegated_admin_session',
            ], true)
                ? 'delegated_admin'
                : null,
            default => null,
        };
    }

    private function unsupportedManagementAuthorizationReasonCode(): string
    {
        return $this->managementPrivilegeLevel() === 'delegated_support'
            ? 'invalid_delegated_management_proof'
            : 'unsupported_management_privilege_level';
    }

    /**
     * @param list<string> $acceptedScopes
     */
    private function hasAnyManagementScope(array $acceptedScopes): bool
    {
        foreach ($acceptedScopes as $scope) {
            if (in_array($scope, $this->managementScopes(), true)) {
                return true;
            }
        }

        return false;
    }

    private function managementAuthorizationModeForScope(string $scope): ?string
    {
        return match ($this->normalizedManagementScope($scope)) {
            'sessions' => $this->managementSessionAuthorizationMode(),
            'trusted-devices' => $this->managementTrustedDeviceAuthorizationMode(),
            default => $this->managementAuthorizationMode(),
        };
    }

    private function managementAuthorizationReasonCodeForScope(string $scope): ?string
    {
        return match ($this->normalizedManagementScope($scope)) {
            'sessions' => $this->managementSessionAuthorizationReasonCode(),
            'trusted-devices' => $this->managementTrustedDeviceAuthorizationReasonCode(),
            default => $this->managementAuthorizationReasonCode(),
        };
    }

    private function normalizedManagementScope(string $scope): string
    {
        return in_array($scope, ['all', 'sessions', 'trusted-devices'], true)
            ? $scope
            : 'all';
    }

    private function managementTargetMatchesCurrentIdentity(?string $targetIdentity, ?string $targetType): bool
    {
        return is_string($targetIdentity)
            && trim($targetIdentity) !== ''
            && is_string($targetType)
            && trim($targetType) !== ''
            && $this->reference->identifier->value === trim($targetIdentity)
            && $this->reference->type === trim($targetType);
    }

    private function managementStringAttribute(string $key): ?string
    {
        $contextValue = $this->attribute($key);

        if (is_string($contextValue) && trim($contextValue) !== '') {
            return trim($contextValue);
        }

        $identityAttributes = $this->identityAttributes();
        $identityValue = $identityAttributes[$key] ?? null;

        return is_string($identityValue) && trim($identityValue) !== ''
            ? trim($identityValue)
            : null;
    }

    /**
     * @return list<string>|null
     */
    private function managementListAttribute(string $key): ?array
    {
        $value = $this->attribute($key);

        if (is_array($value)) {
            return $value;
        }

        $identityAttributes = $this->identityAttributes();
        $identityValue = $identityAttributes[$key] ?? null;

        return is_array($identityValue)
            ? $identityValue
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function identityAttributes(): array
    {
        return property_exists($this->identity, 'attributes') && is_array($this->identity->attributes ?? null)
            ? $this->identity->attributes
            : [];
    }
}
