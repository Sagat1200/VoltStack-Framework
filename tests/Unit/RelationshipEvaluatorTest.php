<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Ability\Ability;
use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\Context\AuthorizationContext;
use Quantum\Authorization\Core\AuthorizationRequest;
use Quantum\Authorization\Principal\Principal;
use Quantum\Authorization\Relationship\InMemoryRelationshipRepository;
use Quantum\Authorization\Relationship\RelationshipEvaluator;
use Quantum\Authorization\Subject\SubjectDescriptor;
use Quantum\Authorization\Subject\SubjectType;

final class RelationshipEvaluatorTest extends TestCase
{
    public function test_it_returns_true_when_no_relation_is_declared(): void
    {
        $evaluator = new RelationshipEvaluator(new InMemoryRelationshipRepository());
        $request = new AuthorizationRequest(
            new Ability('documents.view'),
            new Principal('user-1'),
            new SubjectDescriptor(SubjectType::Scalar, 'doc-1', 'string'),
            new AuthorizationContext('req-1'),
        );

        self::assertTrue($evaluator->evaluate($request, ['ability' => 'documents.view'], Scope::global()));
    }

    public function test_it_evaluates_declared_relation_against_subject_and_scope(): void
    {
        $document = (object) ['id' => 'doc-1'];
        $repository = new InMemoryRelationshipRepository([
            ['principal_id' => 'user-1', 'relation' => 'owner', 'resource' => $document, 'scope' => 'tenant:acme'],
        ]);
        $evaluator = new RelationshipEvaluator($repository);
        $request = new AuthorizationRequest(
            new Ability('documents.manage'),
            new Principal('user-1'),
            new SubjectDescriptor(SubjectType::Object, $document, \stdClass::class),
            new AuthorizationContext('req-2'),
        );

        self::assertTrue($evaluator->evaluate($request, ['relation' => 'owner'], new Scope('tenant:acme:workspace:red')));
        self::assertFalse($evaluator->evaluate($request, ['relation' => 'editor'], new Scope('tenant:acme:workspace:red')));
    }
}
