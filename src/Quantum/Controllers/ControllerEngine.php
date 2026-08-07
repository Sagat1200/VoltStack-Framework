<?php

declare(strict_types=1);

namespace Quantum\Controllers;

use Quantum\Compilation\CompiledControllerFactory;
use Quantum\Compilation\Contracts\CompiledControllerFactoryInterface;
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
use Quantum\Controllers\Security\Contracts\ControllerSecurityManagerInterface;
use Quantum\Controllers\Security\ControllerTarget;
use Quantum\Controllers\Security\Decision\SecurityEvaluationRequest;
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
    private readonly bool $compilationGloballyEnabled;

    private readonly bool $pinBuildPerExecution;

    private readonly bool $securityGloballyEnabled;

    private bool $pinActive = false;

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
        private readonly ?CompiledControllerFactoryInterface $compiledFactory = null,
        private readonly ?ControllerSecurityManagerInterface $securityManager = null,
    ) {
        $enabled = $this->app->config('controller_compilation.enabled', false);
        $this->compilationGloballyEnabled = is_bool($enabled) ? $enabled : (bool) $enabled;

        $pin = $this->app->config('controller_compilation.deployment.pin_build_per_execution', false);
        $this->pinBuildPerExecution = is_bool($pin) ? $pin : (bool) $pin;

        $sec = $this->app->config('controller_security.enabled', false);
        $this->securityGloballyEnabled = is_bool($sec) ? $sec : (bool) $sec;
    }

    public function handle(RouteMatch $match, Request $request): Response
    {
        $definition = new ControllerDefinition($match->route()->action());
        $context = new ControllerContext($this->app, $match, $request);

        $resolved = null;
        $compiled = null;
        $compilationSource = 'dynamic';
        $compilationMissKey = null;
        $compilationHitContext = null;
        $compilationMaterializeError = null;
        $securityContext = null;

        try {
            if ($this->compilationGloballyEnabled && $this->compiledFactory !== null) {
                $this->beginPinIfNeeded();

                $key = $this->compiledFactory->makeKey($definition);
                $compiled = $this->compiledFactory->load($key);

                if ($compiled !== null) {
                    try {
                        $resolved = $this->compiledFactory->materialize($compiled, $this->app);
                        $compilationSource = 'compiled';
                        $compilationHitContext = [
                            'artifact_key' => $key,
                            'build_id' => $compiled->buildId,
                            'class' => $compiled->class,
                            'method' => $compiled->method,
                        ];
                    } catch (Throwable $e) {
                        $compilationMaterializeError = [
                            'artifact_key' => $key,
                            'error_class' => $e::class,
                            'error_message' => $e->getMessage(),
                        ];
                        $resolved = null;
                        $compiled = null;
                    }
                } else {
                    $reportMiss = $this->app->config('controller_compilation.fallback.report_miss', true);
                    if ($reportMiss) {
                        $compilationMissKey = $key;
                    }
                }
            }

            if ($resolved === null) {
                $fallbackMode = $this->app->config('controller_compilation.fallback.mode', 'dynamic');
                if ($fallbackMode === 'fail' && $this->compilationGloballyEnabled) {
                    $failClosed = $this->app->config(
                        'controller_compilation_security.environment.fail_closed',
                        false,
                    );
                    if ($failClosed) {
                        throw new \Quantum\Controllers\Exceptions\UnsupportedControllerActionException(sprintf(
                            'Compiled artifact not found and fail-closed mode is enabled. Action: %s',
                            is_string($definition->action()) ? $definition->action() : json_encode($definition->action()),
                        ));
                    }
                }

                $resolved = $this->resolver->resolve($definition, $context);
                $compilationSource = 'dynamic';
            }
        } catch (Throwable $resolveError) {
            $this->endPinIfNeeded();
            throw $resolveError;
        }

        try {
            $arguments = $this->parameters->resolve($resolved, $context);
        } catch (MissingRouteBindingException $exception) {
            $this->endPinIfNeeded();
            return $this->normalizer->normalize($this->missing->handle($match, $request, $exception));
        }

        try {
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
            $execution->setAttribute('controller.compilation.source', $compilationSource);
            $execution->setAttribute('controller.compilation.artifact', $compiled);
            if ($compiled !== null) {
                $execution->setAttribute('controller.compilation.build_id', $compiled->buildId);
                $execution->setAttribute('controller.compilation.artifact_key', $compiled->key);
            }
            $execution->setState(ControllerExecutionState::Created);
            $execution->setAttribute('controller.lifecycle.started_at', microtime(true));
            $this->observability->emit('controllers.execution.created', $execution);

            if ($compilationHitContext !== null) {
                $this->observability->emit('controllers.compilation.hit', $execution, $compilationHitContext);
            }
            if ($compilationMissKey !== null) {
                $this->observability->emit('controllers.compilation.miss', $execution, [
                    'artifact_key' => $compilationMissKey,
                ]);
            }
            if ($compilationMaterializeError !== null) {
                $this->observability->emit('controllers.compilation.materialize_failed', $execution, $compilationMaterializeError);
            }

            if ($this->securityGloballyEnabled && $this->securityManager !== null) {
                $securityContext = $this->securityManager->initialize($request, $executionContext);
                $executionContext->setSecurityContext($securityContext);
                $execution->setAttribute('controller.security.context', $securityContext);
                $execution->setAttribute('controller.security.principal_type', $securityContext->principal->type()->value);
                $execution->setAttribute('controller.security.tenant', $securityContext->tenant?->id);
                $this->observability->emit('controllers.security.context.created', $execution, [
                    'principal_type' => $securityContext->principal->type()->value,
                    'authenticated' => $securityContext->principal->authenticated(),
                    'has_tenant' => $securityContext->hasTenant(),
                    'execution_id' => $securityContext->executionId,
                ]);

                $target = ControllerTarget::fromDefinition($definition);
                $action = $target->method ?? '__invoke';
                $resource = ['definition' => $target->signature, 'compilation_source' => $compilationSource];
                $securityMetadata = $this->extractSecurityMetadata($match);
                $secRequest = new SecurityEvaluationRequest(
                    security: $securityContext,
                    target: $target,
                    action: $action,
                    resource: $resource,
                    metadata: $securityMetadata,
                );

                $this->observability->emit('controllers.security.authorization.evaluating', $execution, [
                    'target_signature' => $target->signature,
                    'action' => $action,
                    'policies' => $securityMetadata['policies'] ?? [],
                    'permissions' => $securityMetadata['permissions'] ?? [],
                ]);

                try {
                    $this->securityManager->assertAuthorized($secRequest);
                    $this->observability->emit('controllers.security.authorization.allowed', $execution, [
                        'target_signature' => $target->signature,
                        'action' => $action,
                    ]);
                } catch (\Quantum\Controllers\Security\Exceptions\AuthenticationRequiredException $authE) {
                    $this->observability->emit('controllers.security.authentication.failed', $execution, [
                        'target_signature' => $target->signature,
                        'reason_code' => $authE->errorCode(),
                    ]);
                    throw $authE;
                } catch (\Quantum\Controllers\Security\Exceptions\AuthorizationDeniedException $denyE) {
                    $this->observability->emit('controllers.security.authorization.denied', $execution, [
                        'target_signature' => $target->signature,
                        'reason_code' => $denyE->reasonCode,
                        'policy_context' => $denyE->safeContext,
                    ]);
                    throw $denyE;
                }
            }

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
                    'compilation_source' => $compilationSource,
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
                'compilation_source' => $compilationSource,
            ]);

            return $this->normalizer->normalize($result);
        } finally {
            if ($securityContext !== null && $this->securityManager !== null) {
                try {
                    $this->securityManager->finalize($securityContext);
                } catch (Throwable) {
                }
            }
            $this->endPinIfNeeded();
        }
    }

    /** @return array{policies?: string[], permissions?: string[], authentication_required?: bool, tenant_required?: bool} */
    private function extractSecurityMetadata(RouteMatch $match): array
    {
        $meta = [];
        try {
            $routeMeta = $match->route()->metadata();
            if (method_exists($routeMeta, 'raw')) {
                $raw = $routeMeta->raw();
            } elseif (method_exists($routeMeta, 'all')) {
                $raw = $routeMeta->all();
            } else {
                $raw = [];
            }
            if (! is_array($raw)) {
                return $meta;
            }

            foreach (['policies', 'permissions', 'authentication_required', 'tenant_required'] as $key) {
                if (array_key_exists($key, $raw)) {
                    $meta[$key] = $raw[$key];
                }
            }

            if (isset($raw['security']) && is_array($raw['security'])) {
                foreach (['policies', 'permissions', 'authentication_required', 'tenant_required'] as $key) {
                    if (array_key_exists($key, $raw['security'])) {
                        $meta[$key] = $raw['security'][$key];
                    }
                }
            }

            if (isset($raw['attributes']) && is_array($raw['attributes'])) {
                foreach ($raw['attributes'] as $attr) {
                    if (is_array($attr) && isset($attr['security']) && is_array($attr['security'])) {
                        foreach (['policies', 'permissions', 'authentication_required', 'tenant_required'] as $key) {
                            if (array_key_exists($key, $attr['security'])) {
                                $meta[$key] = $attr['security'][$key];
                            }
                        }
                    }
                }
            }
        } catch (Throwable) {
        }

        return $meta;
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

    private function beginPinIfNeeded(): void
    {
        if (! $this->pinBuildPerExecution || $this->pinActive) {
            return;
        }

        if ($this->compiledFactory instanceof CompiledControllerFactory) {
            $this->compiledFactory->beginPinnedExecution();
            $this->pinActive = true;
        }
    }

    private function endPinIfNeeded(): void
    {
        if (! $this->pinActive) {
            return;
        }

        if ($this->compiledFactory instanceof CompiledControllerFactory) {
            $this->compiledFactory->endPinnedExecution();
        }

        $this->pinActive = false;
    }
}
