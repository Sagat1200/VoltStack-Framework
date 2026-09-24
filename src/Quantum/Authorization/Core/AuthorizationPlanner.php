<?php

declare(strict_types=1);

namespace Quantum\Authorization\Core;

use Quantum\Authorization\Contracts\AuthorizationPlannerInterface;
use Quantum\Authorization\Decision\DecisionManager;
use Quantum\Authorization\Decision\DecisionResult;
use Quantum\Authorization\Gate\GateRegistry;
use Quantum\Authorization\Policy\PolicyDispatcher;
use Quantum\Authorization\Policy\PolicyRegistry;

final class AuthorizationPlanner implements AuthorizationPlannerInterface
{
    public function __construct(
        private readonly GateRegistry $gates,
        private readonly PolicyRegistry $policies,
        private readonly PolicyDispatcher $policyDispatcher,
        private readonly DecisionManager $decisions,
        private readonly bool $failClosed = true,
    ) {}

    public function plan(AuthorizationRequest $request): array
    {
        $results = [];
        $gate = $this->gates->get($request->ability());

        if ($gate !== null) {
            try {
                $results[] = $this->normalizeCallableResult(
                    $this->invokeGate($gate, $request),
                    'gate:' . $request->ability()->name(),
                );
            } catch (\Throwable $exception) {
                $results[] = $this->evaluatorFailure(
                    'gate:' . $request->ability()->name(),
                    $exception,
                );
            }
        }

        try {
            $policies = $this->policies->resolveAll($request->subject()->className());
        } catch (\Throwable $exception) {
            return [
                $this->evaluatorFailure('authorization:policy_registry', $exception),
            ];
        }

        foreach ($policies as $policy) {
            try {
                $results[] = $this->policyDispatcher->dispatch($policy, $request);
            } catch (\Throwable $exception) {
                $results[] = $this->evaluatorFailure($policy::class, $exception);
            }
        }

        return $results;
    }

    public function evaluate(AuthorizationRequest $request): DecisionResult
    {
        return $this->decisions->finalize($this->plan($request));
    }

    private function normalizeCallableResult(mixed $value, string $source): DecisionResult
    {
        if ($value instanceof DecisionResult) {
            return $value;
        }

        if ($value === null) {
            return DecisionResult::abstain($source);
        }

        if (is_bool($value)) {
            return $value
                ? DecisionResult::allow($source, 'explicit_allow')
                : DecisionResult::deny($source, 'explicit_deny');
        }

        throw new \UnexpectedValueException(sprintf(
            'Authorization gate [%s] returned an unsupported result of type [%s].',
            $source,
            get_debug_type($value),
        ));
    }

    private function invokeGate(callable $gate, AuthorizationRequest $request): mixed
    {
        $reflection = new \ReflectionFunction(\Closure::fromCallable($gate));
        $argumentPool = [
            $request->principal(),
            $request->subject()->value(),
            $request->context(),
            $request,
        ];

        return $gate(...array_slice($argumentPool, 0, $reflection->getNumberOfParameters()));
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
