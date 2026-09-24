<?php

declare(strict_types=1);

namespace Quantum\Authorization\Core\Stages;

use Quantum\Authorization\Contracts\AuthorizationEvaluationStageInterface;
use Quantum\Authorization\Core\AuthorizationRequest;
use Quantum\Authorization\Decision\DecisionResult;
use Quantum\Authorization\Policy\PolicyDispatcher;
use Quantum\Authorization\Policy\PolicyRegistry;

final class PolicyAuthorizationStage implements AuthorizationEvaluationStageInterface
{
    public function __construct(
        private readonly PolicyRegistry $policies,
        private readonly PolicyDispatcher $policyDispatcher,
        private readonly bool $failClosed = true,
    ) {}

    public function name(): string
    {
        return 'policies';
    }

    public function evaluate(AuthorizationRequest $request): array
    {
        try {
            $policies = $this->policies->resolveAll($request->subject()->className());
        } catch (\Throwable $exception) {
            return [
                $this->evaluatorFailure('authorization:policy_registry', $exception),
            ];
        }

        $results = [];

        foreach ($policies as $policy) {
            try {
                $results[] = $this->policyDispatcher->dispatch($policy, $request);
            } catch (\Throwable $exception) {
                $results[] = $this->evaluatorFailure($policy::class, $exception);
            }
        }

        return $results;
    }

    private function evaluatorFailure(string $source, \Throwable $exception): DecisionResult
    {
        if ($this->failClosed) {
            return DecisionResult::failure(
                source: $source,
                reasonCode: 'authorization_evaluation_failed_fail_closed',
                metadata: ['exception' => $exception::class, 'message' => $exception->getMessage()],
            );
        }

        return DecisionResult::abstain(
            source: $source,
            reasonCode: 'authorization_evaluation_failed_fail_open',
            metadata: ['exception' => $exception::class, 'message' => $exception->getMessage()],
        );
    }
}
