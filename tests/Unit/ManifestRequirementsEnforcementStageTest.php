<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Ability\Ability;
use Quantum\Authorization\Authority\InMemoryAuthorityRepository;
use Quantum\Authorization\Context\AuthorizationContext;
use Quantum\Authorization\Core\AuthorizationRequest;
use Quantum\Authorization\Core\Stages\ManifestRequirementsEnforcementStage;
use Quantum\Authorization\Decision\Decision;
use Quantum\Authorization\Principal\Principal;
use Quantum\Authorization\Subject\SubjectDescriptor;
use Quantum\Authorization\Subject\SubjectType;

final class ManifestRequirementsEnforcementStageTest extends TestCase
{
    public function test_stage_name_is_manifest_requirements(): void
    {
        $stage = new ManifestRequirementsEnforcementStage();

        self::assertSame('manifest_requirements', $stage->name());
    }

    public function test_public_access_returns_allow_with_fingerprint(): void
    {
        $stage = new ManifestRequirementsEnforcementStage();
        $context = new AuthorizationContext('test-req', attributes: [
            'authorization.metadata.public' => true,
            'authorization.metadata.fingerprint' => 'fp_manifest_123',
        ]);
        $request = new AuthorizationRequest(
            new Ability('documents.view'),
            new Principal('42'),
            new SubjectDescriptor(SubjectType::Scalar, 'doc-1', 'string'),
            $context,
        );

        $results = $stage->evaluate($request);

        self::assertCount(1, $results);
        self::assertSame(Decision::Allow, $results[0]->decision());
        self::assertSame('authorization.stage:manifest_requirements', $results[0]->source());
        self::assertSame('manifest_declared_public_access', $results[0]->reasonCode());
        self::assertSame('fp_manifest_123', $results[0]->metadataFingerprint());
        self::assertSame('fp_manifest_123', $results[0]->metadata()['metadata_fingerprint']);
    }

    public function test_no_requirements_returns_empty_results(): void
    {
        $stage = new ManifestRequirementsEnforcementStage();
        $context = new AuthorizationContext('test-req', attributes: [
            'authorization.metadata.public' => false,
            'authorization.metadata.requirements' => [],
        ]);
        $request = new AuthorizationRequest(
            new Ability('documents.view'),
            new Principal('42'),
            new SubjectDescriptor(SubjectType::Scalar, 'doc-1', 'string'),
            $context,
        );

        self::assertCount(0, $stage->evaluate($request));
    }

    public function test_ability_not_in_requirements_returns_deny_fail_closed(): void
    {
        $stage = new ManifestRequirementsEnforcementStage(failClosed: true);
        $context = new AuthorizationContext('test-req', attributes: [
            'authorization.metadata.public' => false,
            'authorization.metadata.requirements' => [
                ['ability' => 'documents.list', 'subject' => null, 'source' => 'attribute'],
                ['ability' => 'documents.create', 'subject' => null, 'source' => 'attribute'],
            ],
            'authorization.metadata.matched_requirements' => [],
            'authorization.metadata.fingerprint' => 'fp_documents_route',
        ]);
        $request = new AuthorizationRequest(
            new Ability('documents.delete'),
            new Principal('42'),
            new SubjectDescriptor(SubjectType::Scalar, 'doc-1', 'string'),
            $context,
        );

        $results = $stage->evaluate($request);

        self::assertCount(1, $results);
        self::assertSame(Decision::Deny, $results[0]->decision());
        self::assertSame('manifest_ability_not_declared_in_requirements', $results[0]->reasonCode());
        self::assertSame('fp_documents_route', $results[0]->metadataFingerprint());
        self::assertSame('documents.delete', $results[0]->metadata()['requested_ability']);
        self::assertSame(['documents.list', 'documents.create'], $results[0]->metadata()['declared_requirements']);
    }

    public function test_ability_not_in_requirements_returns_abstain_fail_open(): void
    {
        $stage = new ManifestRequirementsEnforcementStage(failClosed: false);
        $context = new AuthorizationContext('test-req', attributes: [
            'authorization.metadata.public' => false,
            'authorization.metadata.requirements' => [
                ['ability' => 'documents.list', 'subject' => null, 'source' => 'attribute'],
            ],
            'authorization.metadata.matched_requirements' => [],
        ]);
        $request = new AuthorizationRequest(
            new Ability('documents.delete'),
            new Principal('42'),
            new SubjectDescriptor(SubjectType::Scalar, 'doc-1', 'string'),
            $context,
        );

        $results = $stage->evaluate($request);

        self::assertCount(1, $results);
        self::assertSame(Decision::Abstain, $results[0]->decision());
        self::assertSame('manifest_ability_not_declared_fail_open', $results[0]->reasonCode());
    }

    public function test_ability_matched_in_requirements_returns_empty_for_downstream(): void
    {
        $stage = new ManifestRequirementsEnforcementStage();
        $context = new AuthorizationContext('test-req', attributes: [
            'authorization.metadata.public' => false,
            'authorization.metadata.requirements' => [
                ['ability' => 'documents.view', 'subject' => 'document', 'source' => 'method'],
                ['ability' => 'documents.list', 'subject' => null, 'source' => 'class'],
            ],
            'authorization.metadata.matched_requirements' => [
                ['ability' => 'documents.view', 'subject' => 'document', 'source' => 'method'],
            ],
        ]);
        $request = new AuthorizationRequest(
            new Ability('documents.view'),
            new Principal('42'),
            new SubjectDescriptor(SubjectType::Scalar, 'doc-1', 'string'),
            $context,
        );

        self::assertCount(0, $stage->evaluate($request));
    }

    public function test_requirements_missing_from_context_are_skipped(): void
    {
        $stage = new ManifestRequirementsEnforcementStage();
        $context = new AuthorizationContext('test-req', attributes: [
            'authorization.metadata.public' => false,
        ]);
        $request = new AuthorizationRequest(
            new Ability('documents.view'),
            new Principal('42'),
            new SubjectDescriptor(SubjectType::Scalar, 'doc-1', 'string'),
            $context,
        );

        self::assertCount(0, $stage->evaluate($request));
    }

    public function test_requirements_with_invalid_entries_are_filtered(): void
    {
        $stage = new ManifestRequirementsEnforcementStage();
        $context = new AuthorizationContext('test-req', attributes: [
            'authorization.metadata.public' => false,
            'authorization.metadata.requirements' => [
                'not_array',
                123,
                ['ability' => null, 'subject' => null, 'source' => 'attribute'],
                ['ability' => '', 'subject' => null, 'source' => 'attribute'],
            ],
            'authorization.metadata.matched_requirements' => [],
        ]);
        $request = new AuthorizationRequest(
            new Ability('documents.view'),
            new Principal('42'),
            new SubjectDescriptor(SubjectType::Scalar, 'doc-1', 'string'),
            $context,
        );

        self::assertCount(0, $stage->evaluate($request));
    }

    public function test_matched_requirement_granted_by_authority_emits_allow(): void
    {
        $authority = new InMemoryAuthorityRepository([
            [
                'principal_id' => 'user-1',
                'permissions' => ['view:posts'],
            ],
        ]);
        $stage = new ManifestRequirementsEnforcementStage(
            failClosed: true,
            authorityRepository: $authority,
            evaluateRequirementsConcretely: true,
        );
        $context = new AuthorizationContext('req-auth', attributes: [
            'authorization.metadata.public' => false,
            'authorization.metadata.requirements' => [
                ['ability' => 'view:posts'],
                ['ability' => 'edit:posts'],
            ],
            'authorization.metadata.matched_requirements' => [
                ['ability' => 'view:posts'],
            ],
            'authorization.metadata.fingerprint' => 'fp_manifest_123',
        ]);
        $request = new AuthorizationRequest(
            new Ability('view:posts'),
            new Principal('user-1'),
            new SubjectDescriptor(SubjectType::Scalar, 'post-1', 'string'),
            $context,
        );

        $results = $stage->evaluate($request);

        self::assertNotEmpty($results);
        $allow = $results[0] ?? null;
        self::assertNotNull($allow);
        self::assertSame(\Quantum\Authorization\Decision\Decision::Allow, $allow->decision());
        self::assertSame('manifest_requirement_granted_by_authority', $allow->reasonCode());
        self::assertSame('fp_manifest_123', $allow->metadataFingerprint());
    }

    public function test_matched_requirement_not_granted_by_authority_emits_deny_on_fail_closed(): void
    {
        $authority = new InMemoryAuthorityRepository([
            [
                'principal_id' => 'user-1',
                'permissions' => ['view:posts'],
            ],
        ]);
        $stage = new ManifestRequirementsEnforcementStage(
            failClosed: true,
            authorityRepository: $authority,
            evaluateRequirementsConcretely: true,
        );
        $context = new AuthorizationContext('req-auth', attributes: [
            'authorization.metadata.public' => false,
            'authorization.metadata.requirements' => [
                ['ability' => 'edit:posts'],
            ],
            'authorization.metadata.matched_requirements' => [
                ['ability' => 'edit:posts'],
            ],
        ]);
        $request = new AuthorizationRequest(
            new Ability('edit:posts'),
            new Principal('user-1'),
            new SubjectDescriptor(SubjectType::Scalar, 'post-1', 'string'),
            $context,
        );

        $results = $stage->evaluate($request);
        self::assertNotEmpty($results);
        $deny = $results[0] ?? null;
        self::assertNotNull($deny);
        self::assertSame(\Quantum\Authorization\Decision\Decision::Deny, $deny->decision());
        self::assertSame('manifest_requirement_not_granted_by_authority', $deny->reasonCode());
        $metadata = $deny->metadata();
        self::assertSame('edit:posts', $metadata['ability'] ?? null);
    }

    public function test_explicit_deny_requirement_wins_over_authority_grants(): void
    {
        $authority = new InMemoryAuthorityRepository([
            ['principal_id' => 'user-1', 'permissions' => ['delete:posts']],
        ]);
        $stage = new ManifestRequirementsEnforcementStage(
            failClosed: true,
            authorityRepository: $authority,
            evaluateRequirementsConcretely: true,
        );
        $context = new AuthorizationContext('req-auth', attributes: [
            'authorization.metadata.public' => false,
            'authorization.metadata.requirements' => [
                ['ability' => 'delete:posts', 'effect' => 'deny'],
            ],
            'authorization.metadata.matched_requirements' => [
                ['ability' => 'delete:posts', 'effect' => 'deny'],
            ],
        ]);
        $request = new AuthorizationRequest(
            new Ability('delete:posts'),
            new Principal('user-1'),
            new SubjectDescriptor(SubjectType::Scalar, 'post-1', 'string'),
            $context,
        );

        $results = $stage->evaluate($request);
        $deny = $results[0] ?? null;
        self::assertNotNull($deny);
        self::assertSame(\Quantum\Authorization\Decision\Decision::Deny, $deny->decision());
        self::assertSame('manifest_requirement_explicit_deny', $deny->reasonCode());
    }

    public function test_evaluate_requirements_concretely_disabled_delegates_downstream(): void
    {
        $authority = new InMemoryAuthorityRepository([]);
        $stage = new ManifestRequirementsEnforcementStage(
            failClosed: true,
            authorityRepository: $authority,
            evaluateRequirementsConcretely: false,
        );
        $context = new AuthorizationContext('req-auth', attributes: [
            'authorization.metadata.public' => false,
            'authorization.metadata.requirements' => [
                ['ability' => 'edit:posts'],
            ],
            'authorization.metadata.matched_requirements' => [
                ['ability' => 'edit:posts'],
            ],
        ]);
        $request = new AuthorizationRequest(
            new Ability('edit:posts'),
            new Principal('user-1'),
            new SubjectDescriptor(SubjectType::Scalar, 'post-1', 'string'),
            $context,
        );

        // Si evaluate_requirements_concretely=false, la whitelist deja pasar a downstream.
        self::assertCount(0, $stage->evaluate($request));
    }

    public function test_matched_requirement_with_attribute_conditions_allows_only_when_conditions_are_satisfied(): void
    {
        $authority = new InMemoryAuthorityRepository([
            ['principal_id' => 'user-1', 'permissions' => ['approve:invoice']],
        ]);
        $stage = new ManifestRequirementsEnforcementStage(
            failClosed: true,
            authorityRepository: $authority,
            evaluateRequirementsConcretely: true,
            evaluateAttributeConditions: true,
        );
        $context = new AuthorizationContext('req-auth', attributes: [
            'risk' => ['score' => 40],
            'department' => 'finance',
            'authorization.metadata.public' => false,
            'authorization.metadata.requirements' => [
                ['ability' => 'approve:invoice'],
            ],
            'authorization.metadata.matched_requirements' => [
                [
                    'ability' => 'approve:invoice',
                    'condition' => [
                        ['attribute' => 'risk.score', 'type' => 'integer', 'max' => 50],
                        ['attribute' => 'department', 'type' => 'enum', 'values' => ['finance']],
                    ],
                ],
            ],
        ]);
        $request = new AuthorizationRequest(
            new Ability('approve:invoice'),
            new Principal('user-1'),
            new SubjectDescriptor(SubjectType::Scalar, 'invoice-1', 'string'),
            $context,
        );

        $results = $stage->evaluate($request);
        $allow = $results[0] ?? null;

        self::assertNotNull($allow);
        self::assertSame(\Quantum\Authorization\Decision\Decision::Allow, $allow->decision());
        self::assertSame('manifest_requirement_granted_by_authority', $allow->reasonCode());
        self::assertTrue($allow->metadata()['abac_conditions_evaluated'] ?? false);
    }

    public function test_attribute_condition_failure_denies_when_runtime_abac_is_enabled(): void
    {
        $authority = new InMemoryAuthorityRepository([
            ['principal_id' => 'user-1', 'permissions' => ['approve:invoice']],
        ]);
        $stage = new ManifestRequirementsEnforcementStage(
            failClosed: true,
            authorityRepository: $authority,
            evaluateRequirementsConcretely: true,
            evaluateAttributeConditions: true,
        );
        $context = new AuthorizationContext('req-auth', attributes: [
            'risk' => ['score' => 90],
            'authorization.metadata.public' => false,
            'authorization.metadata.requirements' => [
                ['ability' => 'approve:invoice'],
            ],
            'authorization.metadata.matched_requirements' => [
                [
                    'ability' => 'approve:invoice',
                    'condition' => [
                        'attribute' => 'risk.score',
                        'type' => 'integer',
                        'max' => 50,
                    ],
                ],
            ],
        ]);
        $request = new AuthorizationRequest(
            new Ability('approve:invoice'),
            new Principal('user-1'),
            new SubjectDescriptor(SubjectType::Scalar, 'invoice-1', 'string'),
            $context,
        );

        $results = $stage->evaluate($request);
        $deny = $results[0] ?? null;

        self::assertNotNull($deny);
        self::assertSame(\Quantum\Authorization\Decision\Decision::Deny, $deny->decision());
        self::assertSame('manifest_requirement_attribute_conditions_not_satisfied', $deny->reasonCode());
        self::assertSame(['risk.score'], $deny->metadata()['abac_condition_attributes'] ?? null);
    }
}
