<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Exceptions;

class ControllerExposureViolationException extends SecurityException
{
    public function __construct(
        public readonly string $reasonCode = 'exposure_violation',
        public readonly string $targetSignature = '',
        public readonly array $safeContext = [],
        string $message = 'Controller exposure violation',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function errorCode(): string
    {
        return 'controller.security.controller_exposure_violation';
    }
}
