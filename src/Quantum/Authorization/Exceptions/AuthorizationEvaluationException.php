<?php

declare(strict_types=1);

namespace Quantum\Authorization\Exceptions;

use Quantum\Authorization\Decision\DecisionResult;

final class AuthorizationEvaluationException extends AuthorizationException
{
    public static function fromDecision(DecisionResult $result, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('Authorization evaluation failed [%s] in [%s].', $result->reasonCode(), $result->source()),
            $result,
            previous: $previous,
        );
    }
}
