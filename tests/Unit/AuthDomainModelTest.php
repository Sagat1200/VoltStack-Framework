<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Quantum\Auth\Context\AuthenticationContext;
use Quantum\Auth\Context\AuthenticationRequest;
use Quantum\Auth\Decisions\AuthenticationDecision;
use Quantum\Auth\Decisions\AuthenticationDecisionStatus;
use Quantum\Auth\Identity\GenericIdentity;
use Quantum\Auth\Identity\IdentityIdentifier;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Controllers\Security\Context\AuthenticationStrength;

final class AuthDomainModelTest extends TestCase
{
    public function test_identity_identifier_rejects_empty_value(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new IdentityIdentifier('   ');
    }

    public function test_authentication_request_exposes_attributes(): void
    {
        $request = new AuthenticationRequest(
            requestId: 'req-1',
            transport: 'http',
            attributes: ['tenant' => 'acme'],
        );

        self::assertSame('acme', $request->attribute('tenant'));
        self::assertSame('fallback', $request->attribute('missing', 'fallback'));
    }

    public function test_authenticated_decision_contains_context(): void
    {
        $identity = new GenericIdentity(
            identifier: new IdentityIdentifier('42'),
            type: 'user',
            attributes: ['name' => 'Volt'],
        );

        $context = new AuthenticationContext(
            identity: $identity,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            requestId: 'req-1',
            method: 'password',
            attributes: ['fresh' => true],
        );

        $decision = AuthenticationDecision::authenticated($context, ['source' => 'test']);

        self::assertTrue($decision->isAuthenticated());
        self::assertSame(AuthenticationDecisionStatus::Authenticated, $decision->status);
        self::assertSame('42', (string) $decision->context?->reference->identifier);
        self::assertTrue($decision->context?->attribute('fresh'));
        self::assertSame('test', $decision->metadata['source'] ?? null);
    }

    public function test_authentication_context_exposes_explicit_strength_and_assurance_profile(): void
    {
        $identity = new GenericIdentity(
            identifier: new IdentityIdentifier('84'),
            type: 'user',
            attributes: ['name' => 'Volt MFA'],
        );

        $context = new AuthenticationContext(
            identity: $identity,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            requestId: 'req-2',
            method: 'password',
            attributes: [
                'authentication_strength' => 'MultiFactor',
                'authentication_assurance_profile' => 'multi_factor',
            ],
        );

        self::assertSame(AuthenticationStrength::MultiFactor, $context->authenticationStrength());
        self::assertSame('multi_factor', $context->authenticationAssuranceProfile());
    }

    public function test_authentication_context_exposes_safe_session_public_identifier(): void
    {
        $identity = new GenericIdentity(
            identifier: new IdentityIdentifier('85'),
            type: 'user',
            attributes: ['name' => 'Volt Session'],
        );

        $context = new AuthenticationContext(
            identity: $identity,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            requestId: 'req-3',
            method: 'password',
            attributes: [
                'session_id' => 'raw-secret-session-id',
                'session_public_id' => 'sess_pub_1234567890ab',
            ],
        );

        self::assertSame('sess_pub_1234567890ab', $context->sessionPublicId());
        self::assertSame('raw-secret-session-id', $context->attribute('session_id'));
    }

    public function test_authentication_context_exposes_fresh_authentication_timestamp(): void
    {
        $identity = new GenericIdentity(
            identifier: new IdentityIdentifier('86'),
            type: 'user',
            attributes: ['name' => 'Volt Fresh'],
        );

        $context = new AuthenticationContext(
            identity: $identity,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            requestId: 'req-4',
            method: 'password',
            attributes: [
                'authentication_fresh_at' => '1700000000',
            ],
        );

        self::assertSame(1700000000, $context->freshAuthenticationAt());
    }

    public function test_authentication_context_exposes_device_reference_and_trust_state(): void
    {
        $identity = new GenericIdentity(
            identifier: new IdentityIdentifier('87'),
            type: 'user',
            attributes: ['name' => 'Volt Device'],
        );

        $context = new AuthenticationContext(
            identity: $identity,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            requestId: 'req-5',
            method: 'password',
            attributes: [
                'session_device_reference' => 'devref_1234567890abcdef',
                'session_device_trust_state' => 'unknown',
            ],
        );

        self::assertSame('devref_1234567890abcdef', $context->deviceReference());
        self::assertSame('unknown', $context->deviceTrustState());
    }

    public function test_authentication_context_exposes_trusted_device_credential_state(): void
    {
        $identity = new GenericIdentity(
            identifier: new IdentityIdentifier('88'),
            type: 'user',
            attributes: ['name' => 'Volt Trusted Device'],
        );

        $context = new AuthenticationContext(
            identity: $identity,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            requestId: 'req-6',
            method: 'password',
            attributes: [
                'trusted_device_credential_present' => true,
                'trusted_device_public_id' => 'tdv_1234567890ab',
            ],
        );

        self::assertTrue($context->trustedDeviceCredentialPresent());
        self::assertSame('tdv_1234567890ab', $context->trustedDevicePublicId());
    }

    public function test_authentication_context_exposes_default_management_claims_for_self_service_operations(): void
    {
        $identity = new GenericIdentity(
            identifier: new IdentityIdentifier('89'),
            type: 'user',
            attributes: ['name' => 'Volt Management'],
        );

        $context = new AuthenticationContext(
            identity: $identity,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            requestId: 'req-7',
            method: 'password',
            attributes: [],
        );

        self::assertSame('session_owner', $context->managementAuthority());
        self::assertSame('current_session', $context->managementOwnershipProof());
        self::assertSame([
            'current_session_management',
            'current_device_management',
            'identity_session_management',
            'identity_device_management',
        ], $context->managementScopes());
        self::assertSame('self_service_defaults', $context->managementClaimsSource());
        self::assertSame('self_service', $context->managementPrivilegeLevel());
        self::assertFalse($context->hasGovernedManagementClaims());
        self::assertFalse($context->canAdministrativelyManageDevices());
        self::assertNull($context->managementAuthorizationMode());
        self::assertSame('not_administrative_actor', $context->managementAuthorizationReasonCode());
    }

    public function test_authentication_context_exposes_explicit_management_claim_overrides(): void
    {
        $identity = new GenericIdentity(
            identifier: new IdentityIdentifier('90'),
            type: 'user',
            attributes: ['name' => 'Volt Delegated Management'],
        );

        $context = new AuthenticationContext(
            identity: $identity,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            requestId: 'req-8',
            method: 'password',
            attributes: [
                'auth_management_authority' => 'identity_owner',
                'auth_management_ownership_proof' => 'fresh_auth_session',
                'auth_management_scopes' => [
                    'identity_device_management',
                    'identity_session_management',
                ],
                'auth_management_claims_source' => 'runtime_override',
                'auth_management_privilege_level' => 'delegated_support',
            ],
        );

        self::assertSame('identity_owner', $context->managementAuthority());
        self::assertSame('fresh_auth_session', $context->managementOwnershipProof());
        self::assertSame([
            'identity_device_management',
            'identity_session_management',
        ], $context->managementScopes());
        self::assertSame('runtime_override', $context->managementClaimsSource());
        self::assertSame('delegated_support', $context->managementPrivilegeLevel());
        self::assertFalse($context->hasGovernedManagementClaims());
        self::assertFalse($context->canAdministrativelyManageDevices());
        self::assertNull($context->managementAuthorizationMode());
        self::assertSame('not_administrative_actor', $context->managementAuthorizationReasonCode());
    }

    public function test_authentication_context_falls_back_to_identity_attributes_for_privileged_management_claims(): void
    {
        $identity = new GenericIdentity(
            identifier: new IdentityIdentifier('91'),
            type: 'user',
            attributes: [
                'name' => 'Ops Admin',
                'auth_management_authority' => 'administrative_actor',
                'auth_management_ownership_proof' => 'privileged_session',
                'auth_management_scopes' => [
                    'security_center_export',
                    'admin_device_management',
                ],
                'auth_management_claims_source' => 'identity_attributes',
                'auth_management_privilege_level' => 'privileged_admin',
            ],
        );

        $context = new AuthenticationContext(
            identity: $identity,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            requestId: 'req-9',
            method: 'password',
            attributes: [],
        );

        self::assertSame('administrative_actor', $context->managementAuthority());
        self::assertSame('privileged_session', $context->managementOwnershipProof());
        self::assertSame([
            'security_center_export',
            'admin_device_management',
        ], $context->managementScopes());
        self::assertSame('identity_attributes', $context->managementClaimsSource());
        self::assertSame('privileged_admin', $context->managementPrivilegeLevel());
        self::assertTrue($context->hasGovernedManagementClaims());
        self::assertTrue($context->canAdministrativelyManageDevices());
        self::assertSame('direct_admin', $context->managementAuthorizationMode());
        self::assertNull($context->managementAuthorizationReasonCode());
    }

    public function test_authentication_context_authorizes_delegated_admin_device_management_from_identity_attributes(): void
    {
        $identity = new GenericIdentity(
            identifier: new IdentityIdentifier('92'),
            type: 'user',
            attributes: [
                'name' => 'Delegated Support',
                'auth_management_authority' => 'administrative_actor',
                'auth_management_ownership_proof' => 'delegated_session',
                'auth_management_scopes' => [
                    'admin_device_management',
                ],
                'auth_management_claims_source' => 'identity_attributes',
                'auth_management_privilege_level' => 'delegated_support',
            ],
        );

        $context = new AuthenticationContext(
            identity: $identity,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            requestId: 'req-10',
            method: 'password',
            attributes: [],
        );

        self::assertTrue($context->hasGovernedManagementClaims());
        self::assertTrue($context->canAdministrativelyManageDevices());
        self::assertTrue($context->canAdministrativelyManageDeviceSessions());
        self::assertTrue($context->canAdministrativelyManageTrustedDevices());
        self::assertSame('delegated_admin', $context->managementAuthorizationMode());
        self::assertSame('delegated_admin', $context->managementSessionAuthorizationMode());
        self::assertSame('delegated_admin', $context->managementTrustedDeviceAuthorizationMode());
        self::assertNull($context->managementAuthorizationReasonCode());
        self::assertSame('delegated_administrative_target', $context->managementActorTargetRelation('120', 'user'));
        self::assertSame('delegated_administrative_target', $context->managementActorTargetReasonCode('120', 'user'));
        self::assertSame('delegated_admin_full_scope_target', $context->managementActorTargetScopeRelation('120', 'user'));
        self::assertSame('delegated_admin_full_scope_target', $context->managementActorTargetScopeReasonCode('120', 'user'));
    }

    public function test_authentication_context_supports_delegated_session_only_management_scope(): void
    {
        $identity = new GenericIdentity(
            identifier: new IdentityIdentifier('94'),
            type: 'user',
            attributes: [
                'name' => 'Delegated Sessions',
                'auth_management_authority' => 'administrative_actor',
                'auth_management_ownership_proof' => 'delegated_session',
                'auth_management_scopes' => [
                    'admin_session_management',
                ],
                'auth_management_claims_source' => 'identity_attributes',
                'auth_management_privilege_level' => 'delegated_support',
            ],
        );

        $context = new AuthenticationContext(
            identity: $identity,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            requestId: 'req-10c',
            method: 'password',
            attributes: [],
        );

        self::assertTrue($context->canAdministrativelyManageDevices());
        self::assertTrue($context->canAdministrativelyManageDeviceSessions());
        self::assertFalse($context->canAdministrativelyManageTrustedDevices());
        self::assertSame('delegated_admin', $context->managementAuthorizationMode());
        self::assertSame('delegated_admin', $context->managementSessionAuthorizationMode());
        self::assertNull($context->managementSessionAuthorizationReasonCode());
        self::assertNull($context->managementTrustedDeviceAuthorizationMode());
        self::assertSame('missing_admin_trusted_device_management_scope', $context->managementTrustedDeviceAuthorizationReasonCode());
        self::assertSame('delegated_admin_sessions_scope_target', $context->managementActorTargetScopeRelation('120', 'user'));
        self::assertSame('delegated_admin_sessions_scope_target', $context->managementActorTargetScopeReasonCode('120', 'user'));
    }

    public function test_authentication_context_supports_delegated_trusted_device_only_management_scope(): void
    {
        $identity = new GenericIdentity(
            identifier: new IdentityIdentifier('95'),
            type: 'user',
            attributes: [
                'name' => 'Delegated Trusted Devices',
                'auth_management_authority' => 'administrative_actor',
                'auth_management_ownership_proof' => 'delegated_session',
                'auth_management_scopes' => [
                    'admin_trusted_device_management',
                ],
                'auth_management_claims_source' => 'identity_attributes',
                'auth_management_privilege_level' => 'delegated_support',
            ],
        );

        $context = new AuthenticationContext(
            identity: $identity,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            requestId: 'req-10d',
            method: 'password',
            attributes: [],
        );

        self::assertTrue($context->canAdministrativelyManageDevices());
        self::assertFalse($context->canAdministrativelyManageDeviceSessions());
        self::assertTrue($context->canAdministrativelyManageTrustedDevices());
        self::assertSame('delegated_admin', $context->managementAuthorizationMode());
        self::assertNull($context->managementSessionAuthorizationMode());
        self::assertSame('missing_admin_session_management_scope', $context->managementSessionAuthorizationReasonCode());
        self::assertSame('delegated_admin', $context->managementTrustedDeviceAuthorizationMode());
        self::assertNull($context->managementTrustedDeviceAuthorizationReasonCode());
        self::assertSame('delegated_admin_trusted_devices_scope_target', $context->managementActorTargetScopeRelation('120', 'user'));
        self::assertSame('delegated_admin_trusted_devices_scope_target', $context->managementActorTargetScopeReasonCode('120', 'user'));
    }

    public function test_authentication_context_exposes_direct_admin_and_self_governed_target_relations(): void
    {
        $identity = new GenericIdentity(
            identifier: new IdentityIdentifier('91'),
            type: 'user',
            attributes: [
                'name' => 'Privileged Admin',
                'auth_management_authority' => 'administrative_actor',
                'auth_management_ownership_proof' => 'privileged_session',
                'auth_management_scopes' => [
                    'security_center_export',
                    'admin_device_management',
                ],
                'auth_management_claims_source' => 'identity_attributes',
                'auth_management_privilege_level' => 'privileged_admin',
            ],
        );

        $context = new AuthenticationContext(
            identity: $identity,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            requestId: 'req-10b',
            method: 'password',
            attributes: [],
        );

        self::assertSame('self_governed', $context->managementActorTargetRelation('91', 'user'));
        self::assertSame('governed_current_identity_target', $context->managementActorTargetReasonCode('91', 'user'));
        self::assertSame('self_governed_full_scope_target', $context->managementActorTargetScopeRelation('91', 'user'));
        self::assertSame('governed_current_identity_full_scope_target', $context->managementActorTargetScopeReasonCode('91', 'user'));
        self::assertSame('direct_administrative_target', $context->managementActorTargetRelation('120', 'user'));
        self::assertSame('direct_administrative_target', $context->managementActorTargetReasonCode('120', 'user'));
        self::assertSame('direct_admin_full_scope_target', $context->managementActorTargetScopeRelation('120', 'user'));
        self::assertSame('direct_admin_full_scope_target', $context->managementActorTargetScopeReasonCode('120', 'user'));
    }

    public function test_authentication_context_exposes_reason_for_governed_actor_rejected_without_device_management_scope(): void
    {
        $identity = new GenericIdentity(
            identifier: new IdentityIdentifier('93'),
            type: 'user',
            attributes: [
                'name' => 'Report Only Admin',
                'auth_management_authority' => 'administrative_actor',
                'auth_management_ownership_proof' => 'privileged_session',
                'auth_management_scopes' => [
                    'security_center_export',
                ],
                'auth_management_claims_source' => 'identity_attributes',
                'auth_management_privilege_level' => 'privileged_admin',
            ],
        );

        $context = new AuthenticationContext(
            identity: $identity,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            requestId: 'req-11',
            method: 'password',
            attributes: [],
        );

        self::assertTrue($context->hasGovernedManagementClaims());
        self::assertFalse($context->canAdministrativelyManageDevices());
        self::assertNull($context->managementAuthorizationMode());
        self::assertSame('missing_admin_device_management_scope', $context->managementAuthorizationReasonCode());
    }


    public function test_unauthenticated_decision_has_no_context(): void
    {
        $decision = AuthenticationDecision::unauthenticated(['source' => 'none']);

        self::assertFalse($decision->isAuthenticated());
        self::assertSame(AuthenticationDecisionStatus::Unauthenticated, $decision->status);
        self::assertNull($decision->context);
    }
}
