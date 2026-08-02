<?php

declare(strict_types=1);

namespace Quantum\Controllers;

use Quantum\Http\Request;
use Quantum\Routing\RouteMatch;
use VoltStack\Framework\Application;

final class ControllerContext
{
    public function __construct(
        private readonly Application $app,
        private readonly RouteMatch $match,
        private readonly Request $request,
    ) {}

    public function app(): Application
    {
        return $this->app;
    }

    public function match(): RouteMatch
    {
        return $this->match;
    }

    public function request(): Request
    {
        return $this->request;
    }
}

