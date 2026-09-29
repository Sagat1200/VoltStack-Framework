<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Core\AuthorizationPlanner;
use Quantum\Authorization\Core\AuthorizationRequest;
use Quantum\Authorization\Core\AuthorizationRequestFactory;
use Quantum\Authorization\Decision\AuthorizationDecisionPlan;
use Quantum\Authorization\Decision\DecisionManager;
use Quantum\Authorization\Decision\DecisionResult;
use Quantum\Authorization\Principal\Principal;
use Quantum\Authorization\Subject\SubjectDescriptor;
use Quantum\Authorization\Subject\SubjectType;

final class AuthorizationDecisionPlanExplanationTest extends TestCase
{
    public function test_explain_shape_contains_fingerprint_stages_final_and_evaluated_at(): void
    {
        $final = DecisionResult::allow('planner.final', 'policy_match');
        $stages = [
            [
                'name' => 'manifest_requirements',
                'results' => [
                    DecisionResult::abstain('manifest:req', 'no_requirements'),
                ],
            ],
            [
                'name' => 'policy',
                'results' => [
                    $final,
                ],
            ],
        ];

        $plan = new AuthorizationDecisionPlan('abc123', $stages, $final, 1_700_000_000);
        $explained = $plan->explain();

        self::assertArrayHasKey('fingerprint', $explained);
        self::assertArrayHasKey('stages', $explained);
        self::assertArrayHasKey('final', $explained);
        self::assertArrayHasKey('evaluated_at', $explained);
        self::assertSame('abc123', $explained['fingerprint']);
        self::assertSame(1_700_000_000, $explained['evaluated_at']);
        self::assertIsArray($explained['final']);
        self::assertSame('policy_match', $explained['final']['reason_code']);
    }

    public function test_each_stage_result_exposes_decision_source_reason_code_metadata_and_fingerprint(): void
    {
        $result = DecisionResult::allow(
            source: 'gate:posts.view',
            reasonCode: 'gate_granted',
            metadata: ['actor' => 'u_42', 'tenant' => 'acme'],
        );
        $stages = [
            ['name' => 'gate', 'results' => [$result]],
        ];

        $plan = new AuthorizationDecisionPlan(null, $stages, $result, 1);
        $serialized = $plan->explain()['stages'];

        self::assertCount(1, $serialized);
        self::assertSame('gate', $serialized[0]['name']);
        self::assertCount(1, $serialized[0]['results']);
        $row = $serialized[0]['results'][0];
        self::assertSame('allow', $row['decision']);
        self::assertSame('gate:posts.view', $row['source']);
        self::assertSame('gate_granted', $row['reason_code']);
        self::assertSame('acme', $row['metadata']['tenant'] ?? null);
        self::assertIsString($row['metadata_fingerprint']);
        self::assertNotEmpty($row['metadata_fingerprint']);
    }

    public function test_vo_methods_final_result_and_stages_raw_return_typed_values(): void
    {
        $final = DecisionResult::deny('stage', 'denied');
        $stages = [
            ['name' => 'gate', 'results' => [$final]],
        ];
        $plan = new AuthorizationDecisionPlan('fp', $stages, $final, 42);

        self::assertSame($final, $plan->finalResult());
        self::assertSame($stages, $plan->stagesRaw());
        self::assertSame('fp', $plan->fingerprint());
        self::assertSame(42, $plan->evaluatedAt());
    }

    public function test_null_fingerprint_kept_null_and_empty_metadata_uses_consistent_fingerprint(): void
    {
        $result = \Quantum\Authorization\Decision\DecisionResult::abstain('stage', 'abstain', ['_empty_marker' => 1]);
        $plan = new AuthorizationDecisionPlan(null, [['name' => 's', 'results' => [$result]]], $result, 1);
        $explained = $plan->explain();

        self::assertNull($explained['fingerprint']);
        self::assertIsString($explained['stages'][0]['results'][0]['metadata_fingerprint']);
        self::assertNotEmpty($explained['stages'][0]['results'][0]['metadata_fingerprint']);
    }

    public function test_planner_plan_as_decision_plan_returns_same_stages_as_plan_method(): void
    {
        $stagesImplementations = [
            new class() implements \Quantum\Authorization\Contracts\AuthorizationEvaluationStageInterface {
                public function name(): string { return 'manifest_requirements'; }
                public function evaluate(AuthorizationRequest $request): array
                {
                    return [
                        \Quantum\Authorization\Decision\DecisionResult::abstain('stage:manifest_requirements', 'no_requirements_found'),
                    ];
                }
            },
            new class() implements \Quantum\Authorization\Contracts\AuthorizationEvaluationStageInterface {
                public function name(): string { return 'gate'; }
                public function evaluate(AuthorizationRequest $request): array
                {
                    return [
                        \Quantum\Authorization\Decision\DecisionResult::allow('stage:gate', 'gate_definition_match'),
                    ];
                }
            },
        ];
        $planner = new AuthorizationPlanner([], $stagesImplementations, new DecisionManager('deny'));

        $factory = new AuthorizationRequestFactory(
            new \Quantum\Authorization\Ability\AbilityNormalizer(),
            new \Quantum\Authorization\Principal\PrincipalResolver(),
            new \Quantum\Authorization\Subject\SubjectResolver(),
            new \Quantum\Authorization\Context\AuthorizationContextFactory(),
        );
        $request = $factory->create('posts.view', (object) ['id' => 7, 'class' => 'Post'], null, new Principal('u_1'));

        $flatResults = $planner->plan($request);
        $decisionPlan = $planner->planAsDecisionPlan($request);

        self::assertCount(count($stagesImplementations), $flatResults);
        $stagesExplained = $decisionPlan->explain()['stages'];
        self::assertCount(count($stagesImplementations), $stagesExplained);
        self::assertSame('manifest_requirements', $stagesExplained[0]['name'] ?? null);
        self::assertSame('gate', $stagesExplained[1]['name'] ?? null);
        self::assertTrue($decisionPlan->finalResult()->isAllowed());
        self::assertGreaterThan(0, $decisionPlan->evaluatedAt());
    }
}
