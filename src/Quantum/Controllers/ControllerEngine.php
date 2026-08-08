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
use Quantum\Controllers\Security\Attributes\AuthenticationRequired;
use Quantum\Controllers\Security\Attributes\Expose;
use Quantum\Controllers\Security\Attributes\Permissions;
use Quantum\Controllers\Security\Attributes\Policies;
use Quantum\Controllers\Security\Attributes\TenantRequired;
use Quantum\Controllers\Security\Contracts\ControllerSecurityManagerInterface;
use Quantum\Controllers\Security\ControllerTarget;
use Quantum\Controllers\Security\Decision\SecurityEvaluationRequest;
use Quantum\Controllers\Security\Exceptions\ControllerExposureViolationException;
use Quantum\Http\Request;
use Quantum\Http\Response;
use Quantum\Routing\Dispatching\MissingRouteHandler;
use Quantum\Routing\Dispatching\ResponseNormalizer;
use Quantum\Routing\Exceptions\MissingRouteBindingException;
use Quantum\Routing\RouteMatch;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
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

                $securityMetadata = $this->extractSecurityMetadata($match, $definition);
                $target = ControllerTarget::fromDefinition($definition);
                if (array_key_exists('exposed', $securityMetadata)) {
                    $exposedRaw = $securityMetadata['exposed'];
                    $exposedBool = $exposedRaw === true || $exposedRaw === 'true' || $exposedRaw === 1 || $exposedRaw === '1';
                    $target = $target->withExposed($exposedBool);
                }
                $action = $target->method ?? '__invoke';
                $resource = ['definition' => $target->signature, 'compilation_source' => $compilationSource];
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
                    $this->assertExposure($target, $securityMetadata);
                    $this->securityManager->assertAuthorized($secRequest);
                    $this->observability->emit('controllers.security.authorization.allowed', $execution, [
                        'target_signature' => $target->signature,
                        'action' => $action,
                    ]);
                } catch (ControllerExposureViolationException $expE) {
                    $this->observability->emit('controllers.security.authorization.denied', $execution, [
                        'target_signature' => $target->signature,
                        'reason_code' => $expE->getMessage(),
                        'policy_context' => [
                            'exposure_source' => $expE->safeContext['exposure_source'] ?? 'unknown',
                        ],
                    ]);
                    throw $expE;
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
    private function extractSecurityMetadata(RouteMatch $match, ?ControllerDefinition $definition = null): array
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
            if (is_array($raw)) {
                foreach (['policies', 'permissions', 'authentication_required', 'tenant_required', 'exposed'] as $key) {
                    if (array_key_exists($key, $raw)) {
                        $meta[$key] = $raw[$key];
                    }
                }

                if (isset($raw['security']) && is_array($raw['security'])) {
                    foreach (['policies', 'permissions', 'authentication_required', 'tenant_required', 'exposed'] as $key) {
                        if (array_key_exists($key, $raw['security'])) {
                            $meta[$key] = $raw['security'][$key];
                        }
                    }
                }

                if (isset($raw['attributes']) && is_array($raw['attributes'])) {
                    foreach ($raw['attributes'] as $attr) {
                        if (is_array($attr) && isset($attr['security']) && is_array($attr['security'])) {
                            foreach (['policies', 'permissions', 'authentication_required', 'tenant_required', 'exposed'] as $key) {
                                if (array_key_exists($key, $attr['security'])) {
                                    $meta[$key] = $attr['security'][$key];
                                }
                            }
                        }
                    }
                }
            }
        } catch (Throwable) {
        }

        if ($definition === null) {
            return $meta;
        }

        try {
            $action = $definition->action();
            if (is_string($action) && str_contains($action, '@')) {
                [$controllerClass, $method] = explode('@', $action, 2);
            } elseif (is_string($action)) {
                $controllerClass = $action;
                $method = '__invoke';
            } elseif (is_array($action) && isset($action[0]) && is_string($action[0] ?? null) && isset($action[1])) {
                $controllerClass = is_object($action[0]) ? get_class($action[0]) : (string) $action[0];
                $method = (string) $action[1];
            } elseif (is_object($action) && ! $action instanceof \Closure) {
                $controllerClass = get_class($action);
                $method = method_exists($action, '__invoke') ? '__invoke' : null;
            } else {
                return $meta;
            }

            if (! $controllerClass || ! class_exists($controllerClass)) {
                return $meta;
            }

            $classReflection = new ReflectionClass($controllerClass);
            $classAttrs = $this->collectSecurityAttributes($classReflection);

            $methodAttrs = [];
            if ($method !== null && method_exists($controllerClass, $method)) {
                $methodReflection = new ReflectionMethod($controllerClass, $method);
                $methodAttrs = $this->collectSecurityAttributes($methodReflection);
            }

            $merged = $this->mergeAttributeLayers($classAttrs, $methodAttrs);

            if (array_key_exists('exposed', $merged)) {
                $meta['exposed'] = $merged['exposed'];
            }
            if (array_key_exists('authentication_required', $merged)) {
                $meta['authentication_required'] = $merged['authentication_required'];
            }
            if (array_key_exists('tenant_required', $merged)) {
                $meta['tenant_required'] = $merged['tenant_required'];
            }
            if (array_key_exists('policies', $merged)) {
                $existingPolicies = isset($meta['policies']) && is_array($meta['policies']) ? $meta['policies'] : [];
                $meta['policies'] = array_values(array_unique(array_merge($existingPolicies, $merged['policies']), SORT_REGULAR));
            }
            if (array_key_exists('permissions', $merged)) {
                $existingPerms = isset($meta['permissions']) && is_array($meta['permissions']) ? $meta['permissions'] : [];
                $meta['permissions'] = array_values(array_unique(array_merge($existingPerms, $merged['permissions']), SORT_REGULAR));
            }
        } catch (Throwable) {
        }

        return $meta;
    }

    /**
     * @param ReflectionClass|ReflectionMethod $reflection
     * @return array<string, mixed>
     */
    private function collectSecurityAttributes(ReflectionClass|ReflectionMethod $reflection): array
    {
        $collected = [
            'policies' => [],
            'permissions' => [],
        ];

        $exposes = $reflection->getAttributes(Expose::class, ReflectionAttribute::IS_INSTANCEOF);
        if ($exposes !== []) {
            $inst = $exposes[0]->newInstance();
            $collected['exposed'] = $inst->exposed;
        }

        $authReqs = $reflection->getAttributes(AuthenticationRequired::class, ReflectionAttribute::IS_INSTANCEOF);
        if ($authReqs !== []) {
            $inst = $authReqs[0]->newInstance();
            $collected['authentication_required'] = [
                'minimum_strength' => $inst->minimumStrength->name,
                'minimum_strength_value' => $inst->minimumStrength->value,
                'require_any' => $inst->requireAny,
            ];
        }

        $tenantReqs = $reflection->getAttributes(TenantRequired::class, ReflectionAttribute::IS_INSTANCEOF);
        if ($tenantReqs !== []) {
            $inst = $tenantReqs[0]->newInstance();
            $collected['tenant_required'] = array_filter([
                'verified' => $inst->verified,
                'allowed_tenants' => $inst->allowedTenants,
            ], static fn ($v) => $v !== null);
        }

        foreach ($reflection->getAttributes(Policies::class, ReflectionAttribute::IS_INSTANCEOF) as $attr) {
            $inst = $attr->newInstance();
            foreach ($inst->policies as $p) {
                $collected['policies'][] = $p;
            }
        }

        foreach ($reflection->getAttributes(Permissions::class, ReflectionAttribute::IS_INSTANCEOF) as $attr) {
            $inst = $attr->newInstance();
            foreach ($inst->permissions as $p) {
                $collected['permissions'][] = $p;
            }
        }

        return $collected;
    }

    /**
     * @param array<string, mixed> $classAttrs
     * @param array<string, mixed> $methodAttrs
     * @return array<string, mixed>
     */
    private function mergeAttributeLayers(array $classAttrs, array $methodAttrs): array
    {
        $merged = $classAttrs;

        if (array_key_exists('exposed', $methodAttrs)) {
            $merged['exposed'] = $methodAttrs['exposed'];
        }
        if (array_key_exists('authentication_required', $methodAttrs)) {
            $merged['authentication_required'] = $methodAttrs['authentication_required'];
        }
        if (array_key_exists('tenant_required', $methodAttrs)) {
            $merged['tenant_required'] = $methodAttrs['tenant_required'];
        }
        if (isset($methodAttrs['policies']) && $methodAttrs['policies'] !== []) {
            $existing = isset($merged['policies']) && is_array($merged['policies']) ? $merged['policies'] : [];
            $merged['policies'] = array_values(array_unique(array_merge($existing, $methodAttrs['policies']), SORT_REGULAR));
        }
        if (isset($methodAttrs['permissions']) && $methodAttrs['permissions'] !== []) {
            $existing = isset($merged['permissions']) && is_array($merged['permissions']) ? $merged['permissions'] : [];
            $merged['permissions'] = array_values(array_unique(array_merge($existing, $methodAttrs['permissions']), SORT_REGULAR));
        }

        return $merged;
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

    private function assertExposure(ControllerTarget $target, array $securityMetadata): void
    {
        $explicitExposure = $this->app->config('controller_security.controllers.explicit_exposure', false);
        if (! $explicitExposure) {
            return;
        }

        if ($target->exposed === true) {
            return;
        }

        if ($target->exposed === false) {
            throw new ControllerExposureViolationException(
                reasonCode: 'metadata_explicit_unexposed',
                targetSignature: $target->signature,
                safeContext: [
                    'target_signature' => $target->signature,
                    'target_identifier' => $target->identifier,
                    'target_type' => $target->type->value,
                    'target_method' => $target->method,
                    'exposure_source' => 'route_metadata_exposed_false',
                ],
                message: 'Controller [' . $target->signature . '] is explicitly marked non-exposed via route metadata `security.exposed=false`.',
            );
        }

        $allowlist = $this->app->config('controller_security.controllers.allowlist', []);
        if (! is_array($allowlist)) {
            $allowlist = [];
        }

        $normalized = [];
        foreach ($allowlist as $entry) {
            if (! is_string($entry)) {
                continue;
            }
            $trimmed = trim($entry);
            if ($trimmed === '') {
                continue;
            }
            if (str_contains($trimmed, '@') !== false) {
                $normalized[] = str_replace('@', '::', $trimmed);
            } else {
                $normalized[] = $trimmed;
            }
            if (str_contains($trimmed, '@') === false && str_contains($trimmed, '::') === false) {
                $normalized[] = $trimmed . '::__invoke';
            }
        }

        $checks = [$target->signature];
        if ($target->identifier !== '' && $target->identifier !== '0') {
            $checks[] = $target->identifier;
            if (str_contains($target->identifier, '@') === false && str_contains($target->identifier, '::') === false) {
                $checks[] = $target->identifier . '::__invoke';
            }
        }

        foreach ($checks as $check) {
            if (in_array($check, $normalized, true)) {
                return;
            }
        }

        throw new ControllerExposureViolationException(
            reasonCode: 'not_in_allowlist_metadata_missing',
            targetSignature: $target->signature,
            safeContext: [
                'target_signature' => $target->signature,
                'target_identifier' => $target->identifier,
                'target_type' => $target->type->value,
                'target_method' => $target->method,
                'target_exposed_flag' => var_export($target->exposed, true),
                'exposure_source' => 'not_in_allowlist',
                'allowlist_size' => count($normalized),
                'checked_signatures' => $checks,
            ],
            message: 'Controller [' . $target->signature . '] is not present in security allowlist and does not have route metadata `security.exposed=true`. Enable allowlist bypass by adding the controller signature to `controller_security.controllers.allowlist` or set `security.exposed=true` in route definition metadata.',
        );
    }
}
