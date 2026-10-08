<?php

declare(strict_types=1);

namespace Quantum\Authorization\Context;

use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\Contracts\TenantScopeResolverInterface;
use Quantum\Controllers\Security\Context\ControllerSecurityContext;
use Quantum\Http\Request;
use Quantum\Routing\RouteMatch;
use VoltStack\Runtime\Context\RuntimeContext;

final readonly class TenantScopeResolver implements TenantScopeResolverInterface
{
    /**
     * @param list<string> $scopeAttributeKeys
     * @param list<string> $tenantAttributeKeys
     * @param list<string> $routeParameterKeys
     * @param list<string> $requestHeaderKeys
     */
    public function __construct(
        private array $scopeAttributeKeys = ['authorization.scope', 'scope'],
        private array $tenantAttributeKeys = ['tenant.id', 'tenant_id'],
        private array $routeParameterKeys = ['tenant', 'tenant_id', 'tenantId'],
        private array $requestHeaderKeys = ['X-Tenant-Id'],
        private bool $deriveFromTenantId = true,
        private string $tenantScopePrefix = 'tenant:',
    ) {}

    public function normalize(AuthorizationContext $context): AuthorizationContext
    {
        $attributes = $context->attributes();
        $tenantId = $this->resolveTenantId($context);
        $hasScopeSignal = $this->explicitScopeValue($context) !== null;
        $updates = [];

        if ($tenantId !== null) {
            $updates['tenant.id'] = $tenantId;
            $updates['tenant_id'] = $tenantId;
        }

        if ($hasScopeSignal || ($tenantId !== null && $this->deriveFromTenantId)) {
            $scope = $this->resolveScope($context);
            $updates['authorization.scope'] = $scope;
            $updates['scope'] = (string) $scope;
        }

        if ($updates === []) {
            return $context;
        }

        $normalizedTenantId = $tenantId;
        if ($normalizedTenantId === null) {
            $existingTenantId = $context->tenantId();
            $normalizedTenantId = is_string($existingTenantId) && trim($existingTenantId) !== '' ? trim($existingTenantId) : null;
        }

        foreach ($updates as $key => $value) {
            if (array_key_exists($key, $attributes) && $attributes[$key] === $value) {
                unset($updates[$key]);
            }
        }

        if ($updates === []) {
            return $context;
        }

        return new AuthorizationContext(
            requestId: $context->requestId(),
            tenantId: $normalizedTenantId,
            channel: $context->channel(),
            attributes: array_replace($attributes, $updates),
        );
    }

    public function resolveScope(?AuthorizationContext $context): Scope
    {
        if ($context === null) {
            return Scope::global();
        }

        $explicit = $this->explicitScopeValue($context);
        if ($explicit instanceof Scope) {
            return $explicit;
        }

        if (is_string($explicit)) {
            $normalized = trim($explicit);
            if ($normalized !== '') {
                try {
                    return new Scope($normalized);
                } catch (\Throwable) {
                    return Scope::global();
                }
            }
        }

        $tenantId = $this->resolveTenantId($context);
        if ($tenantId === null || ! $this->deriveFromTenantId) {
            return Scope::global();
        }

        try {
            return new Scope($this->tenantIdToScope($tenantId));
        } catch (\Throwable) {
            return Scope::global();
        }
    }

    private function explicitScopeValue(AuthorizationContext $context): mixed
    {
        foreach ($this->scopeAttributeKeys as $key) {
            $value = $context->attribute($key);

            if ($value instanceof Scope) {
                return $value;
            }

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    private function resolveTenantId(AuthorizationContext $context): ?string
    {
        $tenantId = $context->tenantId();

        if (is_string($tenantId) && trim($tenantId) !== '') {
            return trim($tenantId);
        }

        foreach ($this->tenantAttributeKeys as $key) {
            $value = $context->attribute($key);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $runtimeContext = $this->runtimeContextFrom($context);
        if ($runtimeContext !== null) {
            foreach (['tenant.id', 'tenant_id', 'runtime.tenant.id', 'runtime.tenant_id'] as $key) {
                $value = $runtimeContext->get($key, null);
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        $securityContext = $context->attribute('controller.security.context');
        if ($securityContext instanceof ControllerSecurityContext) {
            $tenantId = $securityContext->tenant?->id;
            if (is_string($tenantId) && trim($tenantId) !== '') {
                return trim($tenantId);
            }
        }

        $request = $context->attribute('request');
        if ($request instanceof Request) {
            foreach ($this->requestHeaderKeys as $headerKey) {
                $headerValue = $request->header($headerKey, null);
                if (is_string($headerValue) && trim($headerValue) !== '') {
                    return trim($headerValue);
                }

                $serverValue = $request->server($headerKey, null);
                if (is_string($serverValue) && trim($serverValue) !== '') {
                    return trim($serverValue);
                }
            }

            foreach ($this->routeParameterKeys as $parameterKey) {
                $parameterValue = $request->routeParameter($parameterKey, null);
                if (is_string($parameterValue) && trim($parameterValue) !== '') {
                    return trim($parameterValue);
                }
            }
        }

        $routeMatch = $context->attribute('route_match');
        if ($routeMatch instanceof RouteMatch) {
            $parameters = $routeMatch->parameters();
            foreach ($this->routeParameterKeys as $parameterKey) {
                $parameterValue = $parameters[$parameterKey] ?? null;
                if (is_string($parameterValue) && trim($parameterValue) !== '') {
                    return trim($parameterValue);
                }
            }
        }

        $resolvedArguments = $context->attribute('resolved_arguments');
        if (is_array($resolvedArguments)) {
            foreach ($this->routeParameterKeys as $parameterKey) {
                $parameterValue = $resolvedArguments[$parameterKey] ?? null;
                if (is_string($parameterValue) && trim($parameterValue) !== '') {
                    return trim($parameterValue);
                }
            }
        }

        if ($runtimeContext !== null) {
            $request = $runtimeContext->request();

            foreach ($this->requestHeaderKeys as $headerKey) {
                $headerValue = $request->header($headerKey, null);
                if (is_string($headerValue) && trim($headerValue) !== '') {
                    return trim($headerValue);
                }

                $serverValue = $request->server($headerKey, null);
                if (is_string($serverValue) && trim($serverValue) !== '') {
                    return trim($serverValue);
                }
            }

            foreach ($this->routeParameterKeys as $parameterKey) {
                $parameterValue = $request->routeParameter($parameterKey, null);
                if (is_string($parameterValue) && trim($parameterValue) !== '') {
                    return trim($parameterValue);
                }
            }
        }

        return null;
    }

    private function runtimeContextFrom(AuthorizationContext $context): ?RuntimeContext
    {
        $runtimeContext = $context->attribute('runtime_context');
        if ($runtimeContext instanceof RuntimeContext) {
            return $runtimeContext;
        }

        $runtimeContext = $context->attribute('runtime.context');
        if ($runtimeContext instanceof RuntimeContext) {
            return $runtimeContext;
        }

        return null;
    }

    private function tenantIdToScope(string $tenantId): string
    {
        $normalized = trim($tenantId);
        if ($normalized === '') {
            return Scope::GLOBAL;
        }

        if ($normalized === Scope::GLOBAL || $normalized === '*' || str_contains($normalized, ':')) {
            return $normalized;
        }

        $prefix = trim($this->tenantScopePrefix);

        return $prefix !== '' ? $prefix . $normalized : $normalized;
    }
}
