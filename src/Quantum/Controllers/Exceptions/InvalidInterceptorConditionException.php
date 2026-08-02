<?php

declare(strict_types=1);

namespace Quantum\Controllers\Exceptions;

final class InvalidInterceptorConditionException extends ControllerException
{
    public function __construct()
    {
        parent::__construct('Invalid controller interceptor condition.');
    }

    public function errorCode(): string
    {
        return 'controller.interceptor_condition_invalid';
    }
}

