<?php

declare(strict_types=1);

namespace Quantum\Controllers\Interceptors;

use Closure;
use Quantum\Controllers\Execution\ControllerExecution;
use Quantum\Controllers\Interceptors\ControllerInterceptorChain;
use Quantum\Controllers\Interceptors\ControllerInterceptorResolver;
use VoltStack\Framework\Application;

final class ControllerInterceptorPipeline
{
    public function __construct(
        private readonly Application $app,
        private readonly ControllerInterceptorResolver $resolver,
    ) {}

    public function handle(ControllerExecution $execution, Closure $terminal): mixed
    {
        $plan = $this->resolver->resolve($execution);

        if ($plan->isEmpty()) {
            return $terminal($execution);
        }

        $chain = new ControllerInterceptorChain($this->app, $plan->interceptors, $terminal);

        return $chain->proceed($execution);
    }
}
