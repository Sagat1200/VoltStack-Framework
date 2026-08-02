<?php

declare(strict_types=1);

namespace Quantum\Controllers\Interceptors;

use Closure;
use Quantum\Controllers\Execution\ControllerExecution;
use Quantum\Controllers\Interceptors\Contracts\ControllerInterceptorChainInterface;
use Quantum\Controllers\Interceptors\Contracts\ControllerInterceptorInterface;
use Quantum\Controllers\Interceptors\ResolvedInterceptorDefinition;
use VoltStack\Framework\Application;

final class ControllerInterceptorChain implements ControllerInterceptorChainInterface
{
    private int $index = 0;

    public function __construct(
        private readonly Application $app,
        private readonly array $interceptors,
        private readonly Closure $terminal,
    ) {}

    public function proceed(ControllerExecution $execution): mixed
    {
        if (! isset($this->interceptors[$this->index])) {
            return ($this->terminal)($execution);
        }

        $definition = $this->interceptors[$this->index];
        $this->index++;

        if (! $definition->matches($execution)) {
            return $this->proceed($execution);
        }

        $interceptor = $this->app->make($definition->interceptorClass, $definition->arguments);

        return $interceptor->intercept($execution, $this);
    }
}