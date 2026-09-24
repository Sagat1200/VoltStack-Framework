<?php

declare(strict_types=1);

namespace Quantum\Authorization\Exceptions;

use Quantum\Authorization\Decision\DecisionResult;

class AuthorizationException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly DecisionResult $result,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function result(): DecisionResult
    {
        return $this->result;
    }
}
