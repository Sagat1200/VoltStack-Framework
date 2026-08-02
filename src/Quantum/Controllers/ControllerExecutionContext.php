<?php

declare(strict_types=1);

namespace Quantum\Controllers;

use Quantum\Http\Request;
use Quantum\Routing\RouteMatch;

final class ControllerExecutionContext
{
    public function __construct(
        private readonly Request $request,
        private readonly RouteMatch $match,
    ) {}

    public function request(): Request
    {
        return $this->request;
    }

    public function match(): RouteMatch
    {
        return $this->match;
    }
}

