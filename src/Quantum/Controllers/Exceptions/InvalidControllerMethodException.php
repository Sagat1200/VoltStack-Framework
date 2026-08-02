<?php

declare(strict_types=1);

namespace Quantum\Controllers\Exceptions;

final class InvalidControllerMethodException extends ControllerException
{
    public function __construct()
    {
        parent::__construct('Controller method is invalid.');
    }

    public function errorCode(): string
    {
        return 'controller.method_invalid';
    }
}