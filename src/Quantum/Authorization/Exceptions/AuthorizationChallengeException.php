<?php

declare(strict_types=1);

namespace Quantum\Authorization\Exceptions;

use Quantum\Authorization\Decision\DecisionResult;

final class AuthorizationChallengeException extends AuthorizationException
{
    public static function fromDecision(DecisionResult $result): self
    {
        return new self(
            sprintf('Authorization challenge required [%s] by [%s].', $result->reasonCode(), $result->source()),
            $result,
        );
    }
}
