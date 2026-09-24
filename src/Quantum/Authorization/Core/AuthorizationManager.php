<?php

declare(strict_types=1);

namespace Quantum\Authorization\Core;

use Quantum\Authorization\Ability\Ability;
use Quantum\Authorization\Contracts\AuthorizationPlannerInterface;
use Quantum\Authorization\Contracts\AuthorizationManagerInterface;
use Quantum\Authorization\Context\AuthorizationContext;
use Quantum\Authorization\Decision\DecisionResult;
use Quantum\Authorization\Exceptions\AuthorizationChallengeException;
use Quantum\Authorization\Exceptions\AuthorizationDeniedException;
use Quantum\Authorization\Exceptions\AuthorizationEvaluationException;

final class AuthorizationManager implements AuthorizationManagerInterface
{
    public function __construct(
        private readonly AuthorizationRequestFactory $requests,
        private readonly AuthorizationPlannerInterface $planner,
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

        return $this->planner->evaluate($request);
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
}
