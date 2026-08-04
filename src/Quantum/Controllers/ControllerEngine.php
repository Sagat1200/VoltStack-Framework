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
use Quantum\Controllers\Observability\Contracts\ControllerObservabilityManagerInterface;
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
        private readonly ControllerObservabilityManagerInterface $observability,
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

        $execution->setAttribute('controller.execution.id', $this->generateExecutionId());
        $execution->setAttribute('controller.runtime', $this->runtime->resolve($execution));
        $execution->setState(ControllerExecutionState::Created);
        $execution->setAttribute('controller.lifecycle.started_at', microtime(true));
        $this->observability->emit('controllers.execution.created', $execution);

        $execution->setState(ControllerExecutionState::Running);
        $this->observability->emit('controllers.execution.started', $execution);

        try {
            $result = $this->interceptors->handle($execution, function (ControllerExecution $execution): mixed {
                $this->observability->emit('controllers.invocation.started', $execution);
                $execution->markInvoked();

                try {
                    $result = $this->invoker->invoke(
                        $execution->controller(),
                        $execution->arguments(),
                        $execution->executionContext(),
                    );
                } catch (Throwable $exception) {
                    $this->observability->emit('controllers.invocation.failed', $execution, [
                        'exception_class' => $exception::class,
                    ]);
                    throw $exception;
                }

                $this->observability->emit('controllers.invocation.completed', $execution);

                return $result;
            });
        } catch (Throwable $exception) {
            $this->evaluateTimeout($execution);
            $execution->recordException($exception);
            $execution->setState(ControllerExecutionState::Failed);
            $this->observability->emit('controllers.execution.completed', $execution, [
                'status' => 'failed',
                'exception_class' => $exception::class,
            ]);
            throw $exception;
        }

        if (! $execution->wasInvoked()) {
            $execution->markShortCircuited($result, ControllerShortCircuitOrigin::Interceptor);
            $this->observability->emit('controllers.invocation.skipped', $execution);
            $this->observability->emit('controllers.execution.short_circuited', $execution, [
                'origin' => $execution->shortCircuitOrigin()?->value,
            ]);
        }

        $this->evaluateTimeout($execution);
        $execution->setState(ControllerExecutionState::Succeeded);
        $this->observability->emit('controllers.execution.completed', $execution, [
            'status' => 'succeeded',
            'short_circuited' => $execution->wasShortCircuited(),
            'timeout_exceeded' => $execution->timeoutExceeded(),
        ]);

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

    private function generateExecutionId(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (Throwable) {
            return uniqid('exec_', true);
        }
    }
}
