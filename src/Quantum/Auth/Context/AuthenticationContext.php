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
