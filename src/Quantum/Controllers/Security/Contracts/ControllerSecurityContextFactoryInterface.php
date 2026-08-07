<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Contracts;

use Quantum\Http\Request;
use Quantum\Controllers\ControllerExecutionContext;
use Quantum\Controllers\Security\Context\ControllerSecurityContext;

interface ControllerSecurityContextFactoryInterface
{
    public function create(
        Request $request,
        ControllerExecutionContext $execution,
    ): ControllerSecurityContext;
}
