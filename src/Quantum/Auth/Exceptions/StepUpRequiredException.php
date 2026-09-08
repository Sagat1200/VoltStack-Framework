<?php

declare(strict_types=1);

namespace Quantum\Auth\Exceptions;

use Quantum\Controllers\Security\Context\AuthenticationStrength;
use RuntimeException;

final class StepUpRequiredException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode = 'auth.step_up_required',
        public readonly AuthenticationStrength $requiredStrength = AuthenticationStrength::MultiFactor,
        public readonly AuthenticationStrength $currentStrength = AuthenticationStrength::Password,
        string $message = 'Step-up authentication is required for this resource.',
    ) {
        parent::__construct($message);
    }
}
