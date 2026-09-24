<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Ability\Ability;
use Quantum\Authorization\Context\AuthorizationContext;
use Quantum\Authorization\Contracts\AuthorizationEvaluationStageInterface;
use Quantum\Authorization\Core\AuthorizationPlanner;
use Quantum\Authorization\Core\AuthorizationRequest;
use Quantum\Authorization\Decision\DecisionManager;
use Quantum\Authorization\Decision\DecisionResult;
use Quantum\Authorization\Principal\Principal;
use Quantum\Authorization\Subject\SubjectDescriptor;
use Quantum\Authorization\Subject\SubjectType;

final class AuthorizationPlannerTest extends TestCase
{
    public function test_planner_aggregates_results_from_all_stages_in_order(): void
    {
        $planner = new AuthorizationPlanner([
            new StaticAuthorizationStage('first', [
                DecisionResult::abstain('stage:first', 'first_abstain'),
            ]),
            new StaticAuthorizationStage('second', [
                DecisionResult::allow('stage:second', 'second_allow'),
            ]),
        ], new DecisionManager('deny'));

        $planned = $planner->plan($this->request());

        self::assertCount(2, $planned);
        self::assertSame('first_abstain', $planned[0]->reasonCode());
        self::assertSame('second_allow', $planned[1]->reasonCode());
        self::assertTrue($planner->evaluate($this->request())->isAllowed());
    }

    public function test_planner_fails_closed_when_a_stage_throws(): void
    {
        $planner = new AuthorizationPlanner([
            new ThrowingAuthorizationStage('broken'),
        ], new DecisionManager('deny'), true);

        $decision = $planner->evaluate($this->request());

        self::assertTrue($decision->isFailure());
        self::assertSame('authorization.stage:broken', $decision->source());
        self::assertSame('authorization_evaluation_failed_fail_closed', $decision->reasonCode());
    }

    public function test_planner_can_fail_open_when_a_stage_throws(): void
    {
        $planner = new AuthorizationPlanner([
            new ThrowingAuthorizationStage('broken'),
        ], new DecisionManager('allow'), false);

        $decision = $planner->evaluate($this->request());

        self::assertTrue($decision->isAllowed());
        self::assertSame('all_evaluators_abstained_allow_by_default', $decision->reasonCode());
    }

    private function request(): AuthorizationRequest
    {
        return new AuthorizationRequest(
            new Ability('documents.view'),
            new Principal('42'),
            new SubjectDescriptor(SubjectType::Scalar, 'doc-1', 'string'),
            AuthorizationContext::empty(),
        );
    }
}

final readonly class StaticAuthorizationStage implements AuthorizationEvaluationStageInterface
{
    /**
     * @param list<DecisionResult> $results
     */
    public function __construct(
        private string $name,
        private array $results,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function evaluate(AuthorizationRequest $request): array
    {
        return $this->results;
    }
}

final readonly class ThrowingAuthorizationStage implements AuthorizationEvaluationStageInterface
{
    public function __construct(
        private string $name,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function evaluate(AuthorizationRequest $request): array
    {
        throw new \RuntimeException('stage exploded');
    }
}
