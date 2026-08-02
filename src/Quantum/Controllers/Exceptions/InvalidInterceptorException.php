<?php

declare(strict_types=1);

namespace Quantum\Controllers\Exceptions;

final class InvalidInterceptorException extends ControllerException
{
    public function __construct(string $interceptor)
    {
        parent::__construct(sprintf('Invalid controller interceptor [%s].', $interceptor));
    }

    public function errorCode(): string
    {
        return 'controller.interceptor_invalid';
    }
}

