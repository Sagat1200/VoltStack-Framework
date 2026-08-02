<?php

declare(strict_types=1);

namespace Quantum\Controllers\Exceptions;

abstract class ControllerException extends \Exception
{
    public function errorCode(): string
    {
        return 'controller.error';
    }
}