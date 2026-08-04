<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Contracts;

use Quantum\Exceptions\ExceptionHandlingContext;
use Quantum\Exceptions\ExceptionHandlingResult;
use Throwable;

interface ExceptionHandlerInterface
{
    public function handle(Throwable $throwable, ExceptionHandlingContext $context): ExceptionHandlingResult;
}

