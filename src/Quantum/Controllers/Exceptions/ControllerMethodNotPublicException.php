<?php

declare(strict_types=1);

namespace Quantum\Controllers\Exceptions;

final class ControllerMethodNotPublicException extends ControllerException
{
    public function __construct()
    {
        parent::__construct('Controller method must be public.');
    }

    public function errorCode(): string
    {
        return 'controller.method_not_public';
    }
}