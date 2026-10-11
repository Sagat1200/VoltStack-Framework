<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Ability\Ability;
use Quantum\Authorization\Context\AuthorizationContext;
use Quantum\Authorization\Contracts\AuthorizationEvaluationStageInterface;
use Quantum\Authorization\Contracts\AuthorizationRequestEnricherInterface;
use Quantum\Authorization\Core\AuthorizationBudget;
use Quantum\Authorization\Core\AuthorizationPlanner;
use Quantum\Authorization\Core\AuthorizationRequest;
use Quantum\Authorization\Decision\Decision;
use Quantum\Authorization\Decision\DecisionManager;
use Quantum\Authorization\Decision\DecisionResult;
use Quantum\Authorization\Principal\Principal;
use Quantum\Authorization\Subject\SubjectDescriptor;
use Quantum\Authorization\Subject\SubjectType;

final class AuthorizationPipelineBudgetTest extends TestCase
{
    public function test_budget_max_stages_denies_when_exceeded(): void
    {
        $stageA = $this->makeStage('a', fn (): array => [DecisionResult::abstain(source: 'a', reasonCode: 'abstain')]);
        $stageB = $this->makeStage('b', fn (): array => [DecisionResult::abstain(source: 'b', reasonCode: 'abstain')]);

        $planner = new AuthorizationPlanner(
            enrichers: [$this->noopEnricher()],
            stages: [$stageA, $stageB],
            decisions: new DecisionManager('deny'),
            failClosed: true,
            budget: new AuthorizationBudget(maxStages: 1),
        );

        $result = $planner->evaluate($this->buildRequest());

        self::assertSame(Decision::Deny, $result->decision());
        self::assertSame('auth.budget_exceeded', $result->reasonCode());
        self::assertSame('max_stages', $result->metadata()['budget_field'] ?? null);
    }

    public function test_budget_not_exceeded_continues_pipeline(): void
    {
        $stageA = $this->makeStage('a', fn (): array => [
            DecisionResult::allow(source: 'a', reasonCode: 'allowed'),
        ]);

        $planner = new AuthorizationPlanner(
            enrichers: [$this->noopEnricher()],
            stages: [$stageA],
            decisions: new DecisionManager('deny'),
            failClosed: true,
            budget: new AuthorizationBudget(maxStages: 5),
        );

        $result = $planner->evaluate($this->buildRequest());

        self::assertSame(Decision::Allow, $result->decision());
        self::assertSame('allowed', $result->reasonCode());
    }

    public function test_decision_plan_includes_duration_ms_per_stage(): void
    {
        $stageA = $this->makeStage('a', fn (): array => [
            DecisionResult::abstain(source: 'a', reasonCode: 'abstain'),
        ]);

        $planner = new AuthorizationPlanner(
            enrichers: [$this->noopEnricher()],
            stages: [$stageA],
            decisions: new DecisionManager('deny'),
            failClosed: true,
            budget: null,
        );

        $plan = $planner->planAsDecisionPlan($this->buildRequest());
        $explained = $plan->explain();

        self::assertArrayHasKey('total_duration_ms', $explained);
        self::assertNotNull($explained['total_duration_ms']);
        self::assertArrayHasKey('duration_ms', $explained['stages'][0]);
        self::assertIsInt($explained['stages'][0]['duration_ms']);
    }

    public function test_null_budget_does_not_alter_pipeline(): void
    {
        $stageA = $this->makeStage('a', fn (): array => [
            DecisionResult::allow(source: 'a', reasonCode: 'allowed'),
        ]);

        $planner = new AuthorizationPlanner(
            enrichers: [$this->noopEnricher()],
            stages: [$stageA],
            decisions: new DecisionManager('deny'),
            failClosed: true,
            budget: null,
        );

        $result = $planner->evaluate($this->buildRequest());

        self::assertSame(Decision::Allow, $result->decision());
    }

    private function makeStage(string $name, callable $evaluate): AuthorizationEvaluationStageInterface
    {
        $closure = \Closure::fromCallable($evaluate);

        return new class ($name, $closure) implements AuthorizationEvaluationStageInterface {
            public function __construct(
                private string $stageName,
                private \Closure $evaluate,
            ) {}

            public function name(): string
            {
                return $this->stageName;
            }

            public function evaluate(AuthorizationRequest $request): array
            {
                return ($this->evaluate)();
            }
        };
    }

    private function noopEnricher(): AuthorizationRequestEnricherInterface
    {
        return new class implements AuthorizationRequestEnricherInterface {
            public function enrich(AuthorizationRequest $request): AuthorizationRequest
            {
                return $request;
            }
        };
    }

    private function buildRequest(): AuthorizationRequest
    {
        $context = new AuthorizationContext('test-req', attributes: []);

        return new AuthorizationRequest(
            new Ability('posts.view'),
            new Principal('42'),
            new SubjectDescriptor(SubjectType::Scalar, 'resource-1', 'string'),
            $context,
        );
    }
}
