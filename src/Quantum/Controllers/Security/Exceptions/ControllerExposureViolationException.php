<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Exceptions;

class ControllerExposureViolationException extends SecurityException
{
    public function errorCode(): string
    {
        return 'controller.security.controller_exposure_violation';
    }
}
