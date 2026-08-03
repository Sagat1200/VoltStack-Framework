<?php

declare(strict_types=1);

namespace Quantum\Controllers\Exceptions;

final class ControllerAlreadyInvokedException extends ControllerException
{
    public function __construct()
    {
        parent::__construct('Controller was already invoked for the current execution.');
    }

    public function errorCode(): string
    {
        return 'controller.already_invoked';
    }
}