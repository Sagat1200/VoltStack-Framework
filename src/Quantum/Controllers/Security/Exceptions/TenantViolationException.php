<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Exceptions;

class TenantViolationException extends SecurityException
{
    public function errorCode(): string
    {
        return 'controller.security.tenant_violation';
    }
}
