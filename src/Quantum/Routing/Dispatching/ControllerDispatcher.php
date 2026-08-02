<?php

declare(strict_types=1);

namespace Quantum\Routing\Dispatching;

use Quantum\Http\Request;
use Quantum\Controllers\ControllerEngine;
use Quantum\Routing\Dispatching\Contracts\DispatcherInterface;
use Quantum\Routing\RouteMatch;

final class ControllerDispatcher implements DispatcherInterface
{
    public function __construct(
        private readonly ControllerEngine $engine,
    ) {}

    public function dispatch(RouteMatch $match, Request $request): mixed
    {
        return $this->engine->handle($match, $request);
    }
}
