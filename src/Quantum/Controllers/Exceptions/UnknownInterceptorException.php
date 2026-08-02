<?php

declare(strict_types=1);

namespace Quantum\Controllers\Exceptions;

final class UnknownInterceptorException extends ControllerException
{
    public function __construct(string $id)
    {
        parent::__construct(sprintf('Unknown controller interceptor [%s].', $id));
    }

    public function errorCode(): string
    {
        return 'controller.interceptor_unknown';
    }
}

