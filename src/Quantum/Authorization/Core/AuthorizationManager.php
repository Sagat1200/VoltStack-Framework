<?php

declare(strict_types=1);

namespace Quantum\Authorization\Core;

use Quantum\Authorization\Ability\Ability;
use Quantum\Authorization\Contracts\AuthorizationManagerInterface;
use Quantum\Authorization\Context\AuthorizationContext;
use Quantum\Authorization\Decision\DecisionManager;
use Quantum\Authorization\Decision\DecisionResult;
use Quantum\Authorization\Exceptions\AuthorizationChallengeException;
use Quantum\Authorization\Exceptions\AuthorizationDeniedException;
use Quantum\Authorization\Exceptions\AuthorizationEvaluationException;
use Quantum\Authorization\Gate\GateRegistry;
use Quantum\Authorization\Policy\PolicyDispatcher;
use Quantum\Authorization\Policy\PolicyRegistry;

final class AuthorizationManager implements AuthorizationManagerInterface
{
    public function __construct(
        private readonly AuthorizationRequestFactory $requests,
        private readonly GateRegistry $gates,
        private readonly PolicyRegistry $policies,
        private readonly PolicyDispatcher $policyDispatcher,
        private readonly DecisionManager $decisions,
    ) {}

    public function for(mixed $principal): BoundAuthorization
    {
        return new BoundAuthorization($this, $principal);
    }

    public function check(
        string|Ability $ability,
        mixed $subject = null,
        ?AuthorizationContext $context = null,
        mixed $principal = null,
    ): bool {
        return $this->decide($ability, $subject, $context, $principal)->isAllowed();
    }

    public function cannot(
        string|Ability $ability,
        mixed $subject = null,
        ?AuthorizationContext $context = null,
        mixed $principal = null,
    ): bool {
        return ! $this->check($ability, $subject, $context, $principal);
    }

    public function decide(
        string|Ability $ability,
        mixed $subject = null,
        ?AuthorizationContext $context = null,
        mixed $principal = null,
    ): DecisionResult {
        $request = $this->requests->create($ability, $subject, $context, $principal);

        try {
            $results = [];
            $gate = $this->gates->get($request->ability());

            if ($gate !== null) {
                $results[] = $this->normalizeCallableResult(
                    $this->invokeGate($gate, $request),
                    'gate:' . $request->ability()->name(),
                );
            }

            foreach ($this->policies->resolveAll($request->subject()->className()) as $policy) {
                $results[] = $this->policyDispatcher->dispatch($policy, $request);
            }

            return $this->decisions->finalize($results);
        } catch (\Throwable $exception) {
            return DecisionResult::failure(
                source: 'authorization',
                reasonCode: 'authorization_evaluation_failed',
                metadata: ['exception' => $exception::class, 'message' => $exception->getMessage()],
            );
        }
    }

    public function authorize(
        string|Ability $ability,
        mixed $subject = null,
        ?AuthorizationContext $context = null,
        mixed $principal = null,
    ): DecisionResult {
        $result = $this->decide($ability, $subject, $context, $principal);

        if ($result->isAllowed()) {
            return $result;
        }

        if ($result->isChallenge()) {
            throw AuthorizationChallengeException::fromDecision($result);
        }

        if ($result->isFailure()) {
            throw AuthorizationEvaluationException::fromDecision($result);
        }

        throw AuthorizationDeniedException::fromDecision($result);
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
}
