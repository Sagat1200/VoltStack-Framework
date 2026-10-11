<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Ability\Ability;
use Quantum\Authorization\Authority\InMemoryAuthorityRepository;
use Quantum\Authorization\Authority\Permission;
use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\Context\AuthorizationContext;
use Quantum\Authorization\Core\AuthorizationRequest;
use Quantum\Authorization\Core\Stages\ManifestRequirementsEnforcementStage;
use Quantum\Authorization\Decision\DecisionResult;
use Quantum\Authorization\Principal\Principal;
use Quantum\Authorization\Principal\PrincipalType;
use Quantum\Authorization\Subject\SubjectDescriptor;
use Quantum\Authorization\Subject\SubjectType;

final class AuthorizationDelegationTtlRuntimeTest extends TestCase
{
    private static function buildStage(?InMemoryAuthorityRepository $repository = null): ManifestRequirementsEnforcementStage
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
            evaluateDelegations: true,
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
            'authorization.metadata.fingerprint' => 'fp-unit-010J-ttl',
        ], $contextAttributes));

        return new AuthorizationRequest(
            ability: new Ability($abilityName),
            principal: $principal,
            subject: new SubjectDescriptor(SubjectType::None),
            context: $context,
        );
    }

    public function test_expired_delegation_grant_does_not_authorize_impersonation(): void
    {
        $repository = new InMemoryAuthorityRepository();

        $grantorId = 'grantor_ttl';
        $trusteeId = 'trustee_ttl';

        // Grant de delegation ya expirado.
        $repository->grantDelegation(
            $trusteeId,
            $grantorId,
            Permission::from('post.publish'),
            Scope::GLOBAL,
            '-2 hours',
        );

        $stage = self::buildStage($repository);

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

        $denies = array_filter(
            $results,
            static fn (DecisionResult $r): bool => $r->isDenied() && $r->reasonCode() === 'manifest_requirement_not_granted_by_authority',
        );

        self::assertNotEmpty($denies);
    }

    public function test_active_delegation_grant_authorizes_impersonation(): void
    {
        $repository = new InMemoryAuthorityRepository();

        $grantorId = 'grantor_ttl_active';
        $trusteeId = 'trustee_ttl_active';

        // Grant de delegation vigente.
        $repository->grantDelegation(
            $trusteeId,
            $grantorId,
            Permission::from('post.publish'),
            Scope::GLOBAL,
            '+2 hours',
        );

        $stage = self::buildStage($repository);

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
    }
}
