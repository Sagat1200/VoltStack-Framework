<?php

declare(strict_types=1);

namespace Quantum\Controllers\Exceptions;

final class ControllerMethodNotAllowedException extends ControllerException
{
    public function __construct()
    {
        parent::__construct('Controller method is not allowed.');
    }

    public function errorCode(): string
    {
        return 'controller.method_not_allowed';
    }
}