<?php

declare(strict_types=1);

namespace Quantum\Auth\Exceptions;

use Quantum\Auth\Identity\IdentitySecurityState;

final class IdentityNotEligibleException extends AuthenticationException
{
    public function __construct(
        public readonly IdentitySecurityState $state,
        string $message = 'Identity is not eligible for authentication.',
    ) {
        parent::__construct($message, 'auth.identity_not_eligible');
    }
}
