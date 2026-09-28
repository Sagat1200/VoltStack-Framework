<?php

declare(strict_types=1);

namespace Quantum\Authorization\Core\Stages;

use Quantum\Authorization\Ability\Ability;
use Quantum\Authorization\Authority\Permission;
use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\Contracts\AuthorityRepositoryInterface;
use Quantum\Authorization\Contracts\AuthorizationEvaluationStageInterface;
use Quantum\Authorization\Core\AuthorizationRequest;
use Quantum\Authorization\Decision\DecisionResult;

final class ManifestRequirementsEnforcementStage implements AuthorizationEvaluationStageInterface
{
    public function __construct(
        private readonly bool $failClosed = true,
        private readonly ?AuthorityRepositoryInterface $authorityRepository = null,
        private readonly bool $evaluateRequirementsConcretely = false,
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
        $scope = $context->attribute('scope') ?? $context->attribute('tenant.id') ?? Scope::GLOBAL;
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
     * @param mixed $matched
     * @return list<array{ability:string,scope?:string|null,principal?:string|null,condition?:mixed,effect?:string}>
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
                'effect' => isset($entry['effect']) && is_string($entry['effect']) ? strtolower($entry['effect']) : 'permit',
            ];
        }

        return $normalized;
    }

    /**
     * @param list<array{ability:string,scope?:string|null,principal?:string|null,condition?:mixed,effect?:string}> $matchedRequirements
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

            $hasPermission = $this->authorityRepository->hasPermission(
                principalId: $principalId,
                permission: $permission,
                scope: $requirementScope,
            );

            if ($hasPermission) {
                $results[] = DecisionResult::allow(
                    source: 'authorization.stage:manifest_requirements',
                    reasonCode: 'manifest_requirement_granted_by_authority',
                    metadata: [
                        ...$baseMetadata,
                        'ability' => $requirement['ability'],
                        'scope' => (string) $requirementScope,
                        'repository' => $this->authorityRepository::class,
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
}
