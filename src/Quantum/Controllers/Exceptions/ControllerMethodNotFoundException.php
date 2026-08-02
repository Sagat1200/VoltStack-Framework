<?php

declare(strict_types=1);

namespace Quantum\Controllers\Exceptions;

final class ControllerMethodNotFoundException extends ControllerException
{
    public function __construct(string $method)
    {
        parent::__construct(sprintf('Controller method [%s] does not exist.', $method));
    }

    public function errorCode(): string
    {
        return 'controller.method_not_found';
    }
}

