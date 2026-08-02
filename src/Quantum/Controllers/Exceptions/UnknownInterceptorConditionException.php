<?php

declare(strict_types=1);

namespace Quantum\Controllers\Exceptions;

final class UnknownInterceptorConditionException extends ControllerException
{
    public function __construct(string $type)
    {
        parent::__construct(sprintf('Unknown controller interceptor condition [%s].', $type));
    }

    public function errorCode(): string
    {
        return 'controller.interceptor_condition_unknown';
    }
}

