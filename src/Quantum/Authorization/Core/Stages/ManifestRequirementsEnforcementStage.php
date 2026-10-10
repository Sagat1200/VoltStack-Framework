<?php

declare(strict_types=1);

namespace Quantum\Authorization\Core\Stages;

use Quantum\Authorization\ABAC\AttributeConditionEvaluator;
use Quantum\Authorization\Ability\Ability;
use Quantum\Authorization\Authority\Permission;
use Quantum\Authorization\Authority\Role;
use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\Contracts\AuthorityRepositoryInterface;
use Quantum\Authorization\Contracts\AuthorizationEvaluationStageInterface;
use Quantum\Authorization\Contracts\DelegationAdministrationInterface;
use Quantum\Authorization\Contracts\RelationshipRepositoryInterface;
use Quantum\Authorization\Contracts\TenantScopeResolverInterface;
use Quantum\Authorization\Core\AuthorizationRequest;
use Quantum\Authorization\Decision\DecisionResult;
use Quantum\Authorization\Principal\PrincipalType;
use Quantum\Authorization\Relationship\RelationshipEvaluator;

final class ManifestRequirementsEnforcementStage implements AuthorizationEvaluationStageInterface
{
    public function __construct(
        private readonly bool $failClosed = true,
        private readonly ?AuthorityRepositoryInterface $authorityRepository = null,
        private readonly bool $evaluateRequirementsConcretely = false,
        private readonly bool $evaluateAttributeConditions = false,
        private readonly bool $evaluateRelationships = false,
        private readonly ?AttributeConditionEvaluator $attributeConditionEvaluator = null,
        private readonly ?RelationshipRepositoryInterface $relationshipRepository = null,
        private readonly ?RelationshipEvaluator $relationshipEvaluator = null,
        private readonly ?TenantScopeResolverInterface $tenantScopeResolver = null,
        private readonly ?DelegationAdministrationInterface $delegationAdministration = null,
        private readonly bool $evaluateDelegations = false,
    ) {}

    public function name(): string
    {
        return 'manifest_requirements';
    }

    public function evaluate(AuthorizationRequest $request): array
    {
        $context = $request->context();
        $isPublic = $context->attribute('authorization.metadata.public');
        $requirements = $context->attribute('authorization.metadata.requirements');
        $matched = $context->attribute('authorization.metadata.matched_requirements');
        $fingerprint = $context->attribute('authorization.metadata.fingerprint');
        $scope = $this->tenantScopeResolver?->resolveScope($context)
            ?? $context->attribute('scope')
            ?? $context->attribute('tenant.id')
            ?? Scope::GLOBAL;
        $principalId = $request->principal()->id();

        $metadata = [];
        if (is_string($fingerprint) && $fingerprint !== '') {
            $metadata['metadata_fingerprint'] = $fingerprint;
        }

        if ($isPublic === true) {
            return [
                DecisionResult::allow(
                    source: 'authorization.stage:manifest_requirements',
                    reasonCode: 'manifest_declared_public_access',
                    metadata: $metadata,
                ),
            ];
        }

        if (! is_array($requirements) || count($requirements) === 0) {
            return [];
        }

        $requestedAbility = $request->ability()->name();
        $requirementAbilities = [];
        $matchedRequirements = $this->normalizedMatchedRequirements($matched, $request->ability());

        foreach ($requirements as $requirement) {
            if (! is_array($requirement) || ! isset($requirement['ability']) || ! is_string($requirement['ability'])) {
                continue;
            }

            $reqAbility = trim($requirement['ability']);

            if ($reqAbility === '') {
                continue;
            }

            $requirementAbilities[] = $reqAbility;
        }

        if (count($requirementAbilities) === 0) {
            return [];
        }

        $currentAbilityMatched = count($matchedRequirements) > 0;

        if (! $currentAbilityMatched) {
            if ($this->failClosed) {
                return [
                    DecisionResult::deny(
                        source: 'authorization.stage:manifest_requirements',
                        reasonCode: 'manifest_ability_not_declared_in_requirements',
                        metadata: [
                            ...$metadata,
                            'requested_ability' => $requestedAbility,
                            'declared_requirements' => $requirementAbilities,
                        ],
                    ),
                ];
            }

            return [
                DecisionResult::abstain(
                    source: 'authorization.stage:manifest_requirements',
                    reasonCode: 'manifest_ability_not_declared_fail_open',
                    metadata: [
                        ...$metadata,
                        'requested_ability' => $requestedAbility,
                        'declared_requirements' => $requirementAbilities,
                    ],
                ),
            ];
        }

        if (! $this->evaluateRequirementsConcretely) {
            return [];
        }

        return $this->evaluateConcretelyEachMatchedRequirement(
            request: $request,
            matchedRequirements: $matchedRequirements,
            scope: $scope,
            principalId: $principalId,
            baseMetadata: $metadata,
        );
    }

    /**
     * @return array{enabled:bool,trustee_id:?string,grantor_id:?string}
     */
    private function impersonationContext(AuthorizationRequest $request): array
    {
        if ($request->principal()->type() !== PrincipalType::ImpersonatedUser) {
            return ['enabled' => false, 'trustee_id' => null, 'grantor_id' => null];
        }

        $context = $request->context();
        $trusteeId = $context->attribute('authorization.impersonation.originator_id');
        $grantorId = $context->attribute('authorization.impersonation.target_id');

        if (! is_string($trusteeId) || trim($trusteeId) === '' || ! is_string($grantorId) || trim($grantorId) === '') {
            return ['enabled' => false, 'trustee_id' => null, 'grantor_id' => null];
        }

        return [
            'enabled' => true,
            'trustee_id' => trim($trusteeId),
            'grantor_id' => trim($grantorId),
        ];
    }

    /**
     * @param Scope $scope
     */
    private function delegationGrantsPermission(
        string $trusteeId,
        string $grantorId,
        Permission $permission,
        Scope $scope,
    ): bool {
        if (! $this->evaluateDelegations || $this->delegationAdministration === null) {
            return false;
        }

        $grants = $this->delegationAdministration->listDelegations([
            'trustee_id' => $trusteeId,
            'grantor_id' => $grantorId,
            'scope' => $scope,
        ]);

        if ($grants === []) {
            return false;
        }

        $roleNames = [];
        $permissionNames = [];
        foreach ($grants as $grant) {
            if (($grant['type'] ?? '') === 'role') {
                $roleNames[] = (string) ($grant['value'] ?? '');
            } elseif (($grant['type'] ?? '') === 'permission') {
                $permissionNames[] = (string) ($grant['value'] ?? '');
            }
        }

        if (in_array($permission->name, $permissionNames, true)) {
            return true;
        }

        // Regla semántica: si existe una delegación (role o permission) desde grantor a trustee,
        // y el grantor posee el permiso pedido en el scope pedido a través de su authority normal,
        // la delegación endosa ese authority al trustee.
        if ($this->authorityRepository !== null) {
            foreach ($grants as $grant) {
                $type = (string) ($grant['type'] ?? '');
                if ($type === 'role' || $type === 'permission') {
                    if ($this->authorityRepository->hasPermission($grantorId, $permission, $scope)) {
                        return true;
                    }

                    break; // Sólo una comprobación basta (el par trustee/grantor/scope es único)
                }
            }
        }

        foreach ($roleNames as $roleName) {
            if ($roleName === '') {
                continue;
            }

            try {
                $role = new Role($roleName);
            } catch (\Throwable) {
                continue;
            }

            foreach ($role->permissions as $rolePermission) {
                if ($rolePermission->equals($permission) || $rolePermission->matches($permission)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param mixed $matched
     * @return list<array{ability:string,scope?:string|null,principal?:string|null,condition?:mixed,effect?:string,relation?:string|null}>
     */
    private function normalizedMatchedRequirements(mixed $matched, Ability $requestedAbility): array
    {
        if (! is_array($matched) || count($matched) === 0) {
            return [];
        }

        $normalized = [];

        foreach ($matched as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $ability = isset($entry['ability']) && is_string($entry['ability'])
                ? trim($entry['ability'])
                : '';

            if ($ability === '') {
                continue;
            }

            $entryAbility = new Ability($ability);

            if (! $entryAbility->equals($requestedAbility)) {
                continue;
            }

            $normalized[] = [
                'ability' => $ability,
                'scope' => isset($entry['scope']) && (is_string($entry['scope']) || $entry['scope'] === null)
                    ? $entry['scope']
                    : null,
                'principal' => isset($entry['principal']) && (is_string($entry['principal']) || $entry['principal'] === null)
                    ? $entry['principal']
                    : null,
                'condition' => $entry['condition'] ?? null,
                'relation' => isset($entry['relation']) && is_string($entry['relation']) && trim($entry['relation']) !== ''
                    ? trim($entry['relation'])
                    : null,
                'effect' => isset($entry['effect']) && is_string($entry['effect']) ? strtolower($entry['effect']) : 'permit',
            ];
        }

        return $normalized;
    }

    /**
     * @param list<array{ability:string,scope?:string|null,principal?:string|null,condition?:mixed,effect?:string,relation?:string|null}> $matchedRequirements
     * @param array<string, mixed> $baseMetadata
     * @return list<DecisionResult>
     */
    private function evaluateConcretelyEachMatchedRequirement(
        AuthorizationRequest $request,
        array $matchedRequirements,
        mixed $scope,
        string $principalId,
        array $baseMetadata,
    ): array {
        if ($this->authorityRepository === null) {
            return [];
        }

        $impersonation = $this->impersonationContext($request);
        $scopeObject = $scope instanceof Scope ? $scope : (is_string($scope) ? new Scope($scope) : Scope::global());
        $results = [];

        foreach ($matchedRequirements as $requirement) {
            $effect = $requirement['effect'] ?? 'permit';
            $requirementScope = is_string($requirement['scope'] ?? null)
                ? new Scope($requirement['scope'])
                : $scopeObject;

            $permission = Permission::from($requirement['ability']);

            if (in_array($effect, ['deny', 'forbid', 'explicit_deny'], true)) {
                $results[] = DecisionResult::deny(
                    source: 'authorization.stage:manifest_requirements',
                    reasonCode: 'manifest_requirement_explicit_deny',
                    metadata: [
                        ...$baseMetadata,
                        'ability' => $requirement['ability'],
                        'scope' => (string) $requirementScope,
                        'effect' => $effect,
                    ],
                );

                continue;
            }

            if ($this->evaluateAttributeConditions && ! $this->conditionSatisfied($request, $requirement['condition'] ?? null)) {
                $conditionMetadata = [
                    ...$baseMetadata,
                    'ability' => $requirement['ability'],
                    'scope' => (string) $requirementScope,
                    'abac_condition_attributes' => $this->conditionAttributeNames($requirement['condition'] ?? null),
                ];

                if ($this->failClosed) {
                    $results[] = DecisionResult::deny(
                        source: 'authorization.stage:manifest_requirements',
                        reasonCode: 'manifest_requirement_attribute_conditions_not_satisfied',
                        metadata: $conditionMetadata,
                    );
                } else {
                    $results[] = DecisionResult::abstain(
                        source: 'authorization.stage:manifest_requirements',
                        reasonCode: 'manifest_requirement_attribute_conditions_not_satisfied_fail_open',
                        metadata: $conditionMetadata,
                    );
                }

                continue;
            }

            if ($this->evaluateRelationships && ! $this->relationshipSatisfied($request, $requirement, $requirementScope)) {
                $relationMetadata = [
                    ...$baseMetadata,
                    'ability' => $requirement['ability'],
                    'scope' => (string) $requirementScope,
                    'relation' => $requirement['relation'] ?? null,
                    'principal_id' => $principalId,
                ];

                if ($this->failClosed) {
                    $results[] = DecisionResult::deny(
                        source: 'authorization.stage:manifest_requirements',
                        reasonCode: 'manifest_requirement_relationship_not_satisfied',
                        metadata: $relationMetadata,
                    );
                } else {
                    $results[] = DecisionResult::abstain(
                        source: 'authorization.stage:manifest_requirements',
                        reasonCode: 'manifest_requirement_relationship_not_satisfied_fail_open',
                        metadata: $relationMetadata,
                    );
                }

                continue;
            }

            $hasPermission = $this->authorityRepository->hasPermission(
                principalId: $principalId,
                permission: $permission,
                scope: $requirementScope,
            );

            if (! $hasPermission && $impersonation['enabled'] && is_string($impersonation['trustee_id']) && is_string($impersonation['grantor_id'])) {
                $hasPermission = $this->delegationGrantsPermission(
                    trusteeId: $impersonation['trustee_id'],
                    grantorId: $impersonation['grantor_id'],
                    permission: $permission,
                    scope: $requirementScope,
                );

                if ($hasPermission) {
                    $baseMetadata['delegation_granted'] = true;
                    $baseMetadata['delegation_trustee_id'] = $impersonation['trustee_id'];
                    $baseMetadata['delegation_grantor_id'] = $impersonation['grantor_id'];
                }
            }

            if ($impersonation['enabled'] && is_string($impersonation['trustee_id']) && is_string($impersonation['grantor_id'])) {
                $baseMetadata['originator_principal_id'] = $impersonation['trustee_id'];
                $baseMetadata['target_principal_id'] = $impersonation['grantor_id'];
                $baseMetadata['impersonation_scope'] = (string) $requirementScope;
            }

            if ($hasPermission) {
                $results[] = DecisionResult::allow(
                    source: 'authorization.stage:manifest_requirements',
                    reasonCode: 'manifest_requirement_granted_by_authority',
                    metadata: [
                        ...$baseMetadata,
                        'ability' => $requirement['ability'],
                        'scope' => (string) $requirementScope,
                        'repository' => $this->authorityRepository::class,
                        'abac_conditions_evaluated' => $this->evaluateAttributeConditions && ($requirement['condition'] ?? null) !== null,
                        'relationship_evaluated' => $this->evaluateRelationships && ($requirement['relation'] ?? null) !== null,
                        'relation' => $requirement['relation'] ?? null,
                    ],
                );
            } else {
                if ($this->failClosed) {
                    $results[] = DecisionResult::deny(
                        source: 'authorization.stage:manifest_requirements',
                        reasonCode: 'manifest_requirement_not_granted_by_authority',
                        metadata: [
                            ...$baseMetadata,
                            'ability' => $requirement['ability'],
                            'scope' => (string) $requirementScope,
                            'principal_id' => $principalId,
                        ],
                    );
                }
            }
        }

        return $results;
    }

    /**
     * @param array{relation?:string|null} $requirement
     */
    private function relationshipSatisfied(AuthorizationRequest $request, array $requirement, Scope $scope): bool
    {
        $relation = $requirement['relation'] ?? null;

        if (! is_string($relation) || trim($relation) === '') {
            return true;
        }

        if (! $this->evaluateRelationships || $this->relationshipRepository === null) {
            return true;
        }

        $evaluator = $this->relationshipEvaluator ?? new RelationshipEvaluator($this->relationshipRepository);

        return $evaluator->evaluate($request, $requirement, $scope);
    }

    private function conditionSatisfied(AuthorizationRequest $request, mixed $condition): bool
    {
        if ($condition === null) {
            return true;
        }

        if (! $this->evaluateAttributeConditions) {
            return true;
        }

        $evaluator = $this->attributeConditionEvaluator ?? new AttributeConditionEvaluator();

        return $evaluator->evaluate($request, $condition);
    }

    /**
     * @return list<string>
     */
    private function conditionAttributeNames(mixed $condition): array
    {
        $evaluator = $this->attributeConditionEvaluator ?? new AttributeConditionEvaluator();
        $names = [];

        foreach ($evaluator->definitions($condition) as $definition) {
            $names[] = $definition->name;
        }

        return $names;
    }
}
