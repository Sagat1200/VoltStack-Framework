<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\ABAC\AttributeConditionEvaluator;
use Quantum\Authorization\ABAC\Condition;
use Quantum\Authorization\Ability\Ability;
use Quantum\Authorization\Context\AuthorizationContext;
use Quantum\Authorization\Core\AuthorizationRequest;
use Quantum\Authorization\Principal\Principal;
use Quantum\Authorization\Subject\SubjectDescriptor;
use Quantum\Authorization\Subject\SubjectType;

final class AttributeConditionEvaluatorTest extends TestCase
{
    public function test_it_accepts_single_flat_condition_against_context_attributes(): void
    {
        $evaluator = new AttributeConditionEvaluator();
        $request = $this->makeRequest([
            'risk' => ['score' => 42],
        ]);

        self::assertTrue($evaluator->evaluate($request, [
            'attribute' => 'risk.score',
            'type' => 'integer',
            'max' => 50,
        ]));
    }

    public function test_it_requires_all_conditions_in_a_list_to_pass(): void
    {
        $evaluator = new AttributeConditionEvaluator();
        $request = $this->makeRequest([
            'department' => 'sales',
            'clearance' => 3,
        ]);

        self::assertFalse($evaluator->evaluate($request, [
            [
                'attribute' => 'department',
                'type' => 'enum',
                'values' => ['sales', 'support'],
            ],
            [
                'attribute' => 'clearance',
                'type' => 'integer',
                'min' => 4,
            ],
        ]));
    }

    public function test_it_can_resolve_subject_dot_paths(): void
    {
        $evaluator = new AttributeConditionEvaluator();
        $request = $this->makeRequest(
            [],
            (object) [
                'owner' => (object) ['id' => 7],
            ],
        );

        self::assertTrue($evaluator->evaluate($request, [
            'attribute' => 'subject.owner.id',
            'type' => 'integer',
            'min' => 7,
            'max' => 7,
        ]));
    }

    public function test_invalid_condition_payload_fails_closed(): void
    {
        $evaluator = new AttributeConditionEvaluator();
        $request = $this->makeRequest([]);

        self::assertFalse($evaluator->evaluate($request, [
            'type' => 'integer',
            'max' => 50,
        ]));
    }

    public function test_it_accepts_condition_objects_and_condition_lists(): void
    {
        $evaluator = new AttributeConditionEvaluator();
        $request = $this->makeRequest([
            'risk' => ['score' => 40],
            'department' => 'legal',
        ]);

        self::assertTrue($evaluator->evaluate($request, Condition::max('risk.score', 50)));
        self::assertTrue($evaluator->evaluate($request, [
            Condition::max('risk.score', 50),
            Condition::enum('department', ['legal', 'finance']),
        ]));
    }

    private function makeRequest(array $contextAttributes, mixed $subject = null): AuthorizationRequest
    {
        return new AuthorizationRequest(
            new Ability('documents.view'),
            new Principal('user-1'),
            new SubjectDescriptor(SubjectType::Object, $subject, $subject !== null ? $subject::class : null),
            new AuthorizationContext('abac-req', attributes: $contextAttributes),
        );
    }
}
