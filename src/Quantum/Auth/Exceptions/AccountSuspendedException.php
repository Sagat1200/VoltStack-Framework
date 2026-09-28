<?php

declare(strict_types=1);

namespace Quantum\Auth\Exceptions;

use Quantum\Auth\Exceptions\AuthenticationException;

class AccountSuspendedException extends AuthenticationException
{
    public function __construct(
        string $message = 'The account is currently suspended and cannot be used for authentication.',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly ?string $suspensionReason = null,
    ) {
        parent::__construct($message, 'auth.account_suspended');
    }
}
