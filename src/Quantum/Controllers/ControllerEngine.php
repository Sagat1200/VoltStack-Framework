<?php

declare(strict_types=1);

namespace Quantum\Controllers;

use Quantum\Controllers\Execution\ControllerExecution;
use Quantum\Controllers\Interceptors\ControllerInterceptorPipeline;
use Quantum\Controllers\Runtime\ControllerExecutionState;
use Quantum\Controllers\Runtime\ControllerRuntimeResolverInterface;
use Quantum\Controllers\Runtime\ControllerShortCircuitOrigin;
use Quantum\Http\Request;
use Quantum\Http\Response;
use Quantum\Routing\Dispatching\MissingRouteHandler;
use Quantum\Routing\Dispatching\ResponseNormalizer;
use Quantum\Routing\Exceptions\MissingRouteBindingException;
use Quantum\Routing\RouteMatch;
use Throwable;
use VoltStack\Framework\Application;

final class ControllerEngine
{
    public function __construct(
        private readonly Application $app,
        private readonly ControllerResolver $resolver,
        private readonly ParameterResolutionEngine $parameters,
        private readonly MissingRouteHandler $missing,
        private readonly ControllerInvoker $invoker,
        private readonly ControllerInterceptorPipeline $interceptors,
        private readonly ControllerRuntimeResolverInterface $runtime,
        private readonly ResponseNormalizer $normalizer,
    ) {}

    public function handle(RouteMatch $match, Request $request): Response
    {
        $definition = new ControllerDefinition($match->route()->action());
        $context = new ControllerContext($this->app, $match, $request);
        $resolved = $this->resolver->resolve($definition, $context);

        try {
            $arguments = $this->parameters->resolve($resolved, $context);
        } catch (MissingRouteBindingException $exception) {
            return $this->normalizer->normalize($this->missing->handle($match, $request, $exception));
        }

        $executionContext = new ControllerExecutionContext($request, $match);
        $execution = new ControllerExecution(
            $definition,
            $context,
            $resolved,
            $arguments,
            $executionContext,
        );

        $execution->setAttribute('controller.runtime', $this->runtime->resolve($execution));
        $execution->setState(ControllerExecutionState::Created);

        $execution->setState(ControllerExecutionState::Running);

        try {
            $result = $this->interceptors->handle($execution, function (ControllerExecution $execution): mixed {
                $execution->markInvoked();

                return $this->invoker->invoke(
                    $execution->controller(),
                    $execution->arguments(),
                    $execution->executionContext(),
                );
            });
        } catch (Throwable $exception) {
            $execution->recordException($exception);
            $execution->setState(ControllerExecutionState::Failed);
            throw $exception;
        }

        if (! $execution->wasInvoked()) {
            $execution->markShortCircuited($result, ControllerShortCircuitOrigin::Interceptor);
        }

        $execution->setState(ControllerExecutionState::Succeeded);

        return $this->normalizer->normalize($result);
    }
}
