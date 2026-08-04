<?php

declare(strict_types=1);

namespace Quantum\Controllers;

use Quantum\Controllers\ControllerContext;
use Quantum\Controllers\ControllerDefinition;
use Quantum\Controllers\ControllerExecutionContext;
use Quantum\Controllers\ControllerInvoker;
use Quantum\Controllers\ControllerResolver;
use Quantum\Controllers\Execution\ControllerExecution;
use Quantum\Controllers\Interceptors\ControllerInterceptorPipeline;
use Quantum\Controllers\ParameterResolutionEngine;
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
        $execution->setAttribute('controller.lifecycle.started_at', microtime(true));

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
            $this->evaluateTimeout($execution);
            $execution->recordException($exception);
            $execution->setState(ControllerExecutionState::Failed);
            throw $exception;
        }

        if (! $execution->wasInvoked()) {
            $execution->markShortCircuited($result, ControllerShortCircuitOrigin::Interceptor);
        }

        $this->evaluateTimeout($execution);
        $execution->setState(ControllerExecutionState::Succeeded);

        return $this->normalizer->normalize($result);
    }

    private function evaluateTimeout(ControllerExecution $execution): void
    {
        $options = $execution->runtimeOptions();

        if (! $options?->timeoutsEnabled) {
            return;
        }

        $timeoutSeconds = $options->timeoutDefaultSeconds;

        if ($timeoutSeconds === null) {
            return;
        }

        $startedAt = $execution->getAttribute('controller.lifecycle.started_at');

        if (! is_float($startedAt) && ! is_int($startedAt)) {
            return;
        }

        $elapsedSeconds = microtime(true) - (float) $startedAt;

        $execution->setAttribute('controller.lifecycle.duration_seconds', $elapsedSeconds);
        $execution->setAttribute('controller.lifecycle.timeout_seconds', $timeoutSeconds);

        if ($elapsedSeconds > $timeoutSeconds) {
            $execution->setAttribute('controller.lifecycle.timeout_exceeded', true);
        }
    }
}
