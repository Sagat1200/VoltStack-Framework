<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Ability\Ability;
use Quantum\Authorization\Authority\Permission;
use Quantum\Authorization\Authority\Role;
use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\Context\AuthorizationContext;
use Quantum\Authorization\Contracts\AuthorizationManagerInterface;
use Quantum\Authorization\Core\Stages\ManifestRequirementsEnforcementStage;
use Quantum\Authorization\Core\AuthorizationRequest;
use Quantum\Authorization\Core\AuthorizationRequestFactory;
use Quantum\Authorization\Decision\DecisionResult;
use Quantum\Authorization\Principal\Principal;
use Quantum\Authorization\Principal\PrincipalType;
use Quantum\Authorization\Subject\SubjectDescriptor;
use Quantum\Authorization\Subject\SubjectType;
use Quantum\Authorization\Authority\InMemoryAuthorityRepository;
use VoltStack\Framework\Application;

final class AuthorizationDelegationRuntimeTest extends TestCase
{
    private static function buildStage(bool $evaluateDelegations, ?InMemoryAuthorityRepository $repository = null): ManifestRequirementsEnforcementStage
    {
        $repo = $repository ?? new InMemoryAuthorityRepository();
        return new ManifestRequirementsEnforcementStage(
            failClosed: true,
            authorityRepository: $repo,
            evaluateRequirementsConcretely: true,
            evaluateAttributeConditions: false,
            evaluateRelationships: false,
            attributeConditionEvaluator: null,
            relationshipRepository: null,
            relationshipEvaluator: null,
            tenantScopeResolver: null,
            delegationAdministration: $repo,
            evaluateDelegations: $evaluateDelegations,
        );
    }

    private static function requestFor(
        string $abilityName,
        Principal $principal,
        array $contextAttributes = [],
    ): AuthorizationRequest {
        $context = AuthorizationContext::empty()->withAttributes(array_merge([
            'authorization.metadata.public' => false,
            'authorization.metadata.requirements' => [
                [
                    'ability' => $abilityName,
                    'scope' => Scope::GLOBAL,
                    'principal' => null,
                    'condition' => null,
                    'effect' => 'permit',
                ],
            ],
            'authorization.metadata.matched_requirements' => [
                [
                    'ability' => $abilityName,
                    'scope' => Scope::GLOBAL,
                    'principal' => null,
                    'condition' => null,
                    'effect' => 'permit',
                ],
            ],
            'authorization.metadata.fingerprint' => 'fp-unit-010I',
        ], $contextAttributes));

        return new AuthorizationRequest(
            ability: new Ability($abilityName),
            principal: $principal,
            subject: new SubjectDescriptor(SubjectType::None),
            context: $context,
        );
    }

    public function test_without_impersonation_and_no_direct_permission_stage_denies(): void
    {
        $repository = new InMemoryAuthorityRepository();
        $repository->grantDelegation(
            trusteeId: 'trustee_x',
            grantorId: 'grantor_y',
            grant: Permission::from('post.publish'),
            scope: Scope::GLOBAL,
        );

        $stage = self::buildStage(true, $repository);

        // Normal User principal (no impersonation)
        $regularPrincipal = new Principal('regular-user', PrincipalType::User, authenticated: true);
        $request = self::requestFor('post.publish', $regularPrincipal);

        $results = $stage->evaluate($request);

        $denies = array_filter(
            $results,
            static fn (DecisionResult $r): bool => $r->isDenied() && $r->reasonCode() === 'manifest_requirement_not_granted_by_authority',
        );

        self::assertNotEmpty($denies);
    }

    public function test_with_impersonation_and_delegation_grant_stage_allows(): void
    {
        $repository = new InMemoryAuthorityRepository();

        $grantorId = 'grantor_9000';
        $trusteeId = 'trustee_1000';

        // IMPORTANTE: NO le damos el permiso al target/grantor directamente.
        // Queremos probar la ruta FALLBACK de delegation: hasPermission directo=false
        // → delegation check activa → ALLOW via delegation_granted.
        // (Solo existe el grant de delegation desde grantor a trustee).

        // Grant the delegation from grantor to trustee
        $repository->grantDelegation($trusteeId, $grantorId, Permission::from('post.publish'), Scope::GLOBAL);

        $stage = self::buildStage(true, $repository);

        // Trustee (impersonator) acts as grantor (target) - ImpersonatedUser: id = target
        $impersonatedPrincipal = new Principal(
            id: $grantorId,
            type: PrincipalType::ImpersonatedUser,
            authenticated: true,
            claims: [
                'originator_principal_id' => $trusteeId,
                'target_principal_id' => $grantorId,
                'acting_as' => $grantorId,
            ],
        );

        $request = self::requestFor('post.publish', $impersonatedPrincipal, [
            'authorization.impersonation.originator_id' => $trusteeId,
            'authorization.impersonation.target_id' => $grantorId,
        ]);

        $results = $stage->evaluate($request);

        $allows = array_filter(
            $results,
            static fn (DecisionResult $r): bool => $r->isAllowed() && $r->reasonCode() === 'manifest_requirement_granted_by_authority',
        );

        self::assertNotEmpty($allows);

        $allowed = reset($allows);
        $metadata = $allowed->metadata();

        // Decision metadata traces delegation info
        self::assertTrue((bool) ($metadata['delegation_granted'] ?? false));
        self::assertSame($trusteeId, $metadata['delegation_trustee_id'] ?? null);
        self::assertSame($grantorId, $metadata['delegation_grantor_id'] ?? null);
        self::assertSame($trusteeId, $metadata['originator_principal_id'] ?? null);
        self::assertSame($grantorId, $metadata['target_principal_id'] ?? null);
    }

    public function test_with_impersonation_but_no_delegation_stage_denies(): void
    {
        $repository = new InMemoryAuthorityRepository();

        $grantorId = 'grantor_9000';
        $trusteeId = 'trustee_1000';

        // No delegation grant!

        $stage = self::buildStage(true, $repository);

        $impersonatedPrincipal = new Principal(
            id: $grantorId,
            type: PrincipalType::ImpersonatedUser,
            authenticated: true,
            claims: [
                'originator_principal_id' => $trusteeId,
                'target_principal_id' => $grantorId,
            ],
        );

        $request = self::requestFor('post.publish', $impersonatedPrincipal, [
            'authorization.impersonation.originator_id' => $trusteeId,
            'authorization.impersonation.target_id' => $grantorId,
        ]);

        $results = $stage->evaluate($request);

        $denies = array_filter(
            $results,
            static fn (DecisionResult $r): bool => $r->isDenied() && $r->reasonCode() === 'manifest_requirement_not_granted_by_authority',
        );

        self::assertNotEmpty($denies);
    }

    public function test_evaluate_delegations_off_does_not_fallback_to_delegation_check(): void
    {
        $repository = new InMemoryAuthorityRepository();

        $grantorId = 'grantor_no_perm';
        $trusteeId = 'trustee_asking';

        // NO le damos el permiso al target directamente, y creamos un delegation grant
        // PERO evaluateDelegations=false: el fallback delegation NO debe ejecutarse → DENY
        $repository->grantDelegation($trusteeId, $grantorId, Permission::from('docs.read'), Scope::GLOBAL);

        $stage = self::buildStage(false, $repository); // evaluateDelegations = false

        $impersonatedPrincipal = new Principal(
            id: $grantorId,
            type: PrincipalType::ImpersonatedUser,
            authenticated: true,
            claims: [
                'originator_principal_id' => $trusteeId,
                'target_principal_id' => $grantorId,
            ],
        );

        $request = self::requestFor('docs.read', $impersonatedPrincipal, [
            'authorization.impersonation.originator_id' => $trusteeId,
            'authorization.impersonation.target_id' => $grantorId,
        ]);

        $results = $stage->evaluate($request);

        $denies = array_filter(
            $results,
            static fn (DecisionResult $r): bool => $r->isDenied() && $r->reasonCode() === 'manifest_requirement_not_granted_by_authority',
        );

        self::assertNotEmpty($denies);
    }

    public function test_delegation_grants_role_which_expands_into_permission(): void
    {
        $repository = new InMemoryAuthorityRepository();

        $grantorId = 'grantor_role_owner';
        $trusteeId = 'trustee_role_user';

        // El grantor SI posee el role (ruta semantica: delegation endosa
        // authority del grantor). Trustee pide usar post.archive → busca
        // la delegation (role:publisher) → check si grantor tiene el permiso.
        $role = new Role('publisher', [Permission::from('post.archive')]);
        $repository->grantRole($grantorId, $role, Scope::GLOBAL);
        $repository->grantDelegation($trusteeId, $grantorId, $role, Scope::GLOBAL);

        $stage = self::buildStage(true, $repository);

        $impersonatedPrincipal = new Principal(
            id: $grantorId,
            type: PrincipalType::ImpersonatedUser,
            authenticated: true,
            claims: [
                'originator_principal_id' => $trusteeId,
                'target_principal_id' => $grantorId,
            ],
        );

        $request = self::requestFor('post.archive', $impersonatedPrincipal, [
            'authorization.impersonation.originator_id' => $trusteeId,
            'authorization.impersonation.target_id' => $grantorId,
        ]);

        $results = $stage->evaluate($request);

        $allows = array_filter(
            $results,
            static fn (DecisionResult $r): bool => $r->isAllowed() && $r->reasonCode() === 'manifest_requirement_granted_by_authority',
        );

        self::assertNotEmpty($allows);
    }
}
