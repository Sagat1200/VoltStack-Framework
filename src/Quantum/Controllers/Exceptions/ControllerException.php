<?php

declare(strict_types=1);

namespace Quantum\Controllers\Exceptions;

abstract class ControllerException extends \RuntimeException
{
    public function errorCode(): string
    {
        return 'controller.error';
    }
}