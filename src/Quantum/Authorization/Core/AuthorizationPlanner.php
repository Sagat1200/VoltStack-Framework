<?php

declare(strict_types=1);

namespace Quantum\Authorization\Core;

use Quantum\Authorization\Contracts\AuthorizationEvaluationStageInterface;
use Quantum\Authorization\Contracts\AuthorizationPlannerInterface;
use Quantum\Authorization\Contracts\AuthorizationRequestEnricherInterface;
use Quantum\Authorization\Decision\DecisionManager;
use Quantum\Authorization\Decision\DecisionResult;

final class AuthorizationPlanner implements AuthorizationPlannerInterface
{
    /**
     * @param list<AuthorizationRequestEnricherInterface> $enrichers
     * @param list<AuthorizationEvaluationStageInterface> $stages
     */
    public function __construct(
        private readonly array $enrichers,
        private readonly array $stages,
        private readonly DecisionManager $decisions,
        private readonly bool $failClosed = true,
    ) {}

    public function plan(AuthorizationRequest $request): array
    {
        $results = [];
        $request = $this->enrich($request);

        foreach ($this->stages as $stage) {
            try {
                foreach ($stage->evaluate($request) as $result) {
                    $results[] = $result;
                }
            } catch (\Throwable $exception) {
                $results[] = $this->stageFailure($stage->name(), $exception);
            }
        }

        return $results;
    }

    public function evaluate(AuthorizationRequest $request): DecisionResult
    {
        return $this->decisions->finalize($this->plan($request));
    }

    private function enrich(AuthorizationRequest $request): AuthorizationRequest
    {
        foreach ($this->enrichers as $enricher) {
            $request = $enricher->enrich($request);
        }

        return $request;
    }

    private function stageFailure(string $stage, \Throwable $exception): DecisionResult
    {
        if ($this->failClosed) {
            return DecisionResult::failure(
                source: 'authorization.stage:' . $stage,
                reasonCode: 'authorization_evaluation_failed_fail_closed',
                metadata: ['exception' => $exception::class, 'message' => $exception->getMessage()],
            );
        }

        return DecisionResult::abstain(
            source: 'authorization.stage:' . $stage,
            reasonCode: 'authorization_evaluation_failed_fail_open',
            metadata: ['exception' => $exception::class, 'message' => $exception->getMessage()],
        );
    }
}
