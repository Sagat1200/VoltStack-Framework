<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Exceptions;

class SecurityInfrastructureFailureException extends SecurityException
{
    public function errorCode(): string
    {
        return 'controller.security.infrastructure_failure';
    }
}
