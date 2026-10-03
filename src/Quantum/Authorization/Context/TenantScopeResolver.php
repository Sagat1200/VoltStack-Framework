<?php

declare(strict_types=1);

namespace Quantum\Authorization\Context;

use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\Contracts\TenantScopeResolverInterface;

final readonly class TenantScopeResolver implements TenantScopeResolverInterface
{
    /**
     * @param list<string> $scopeAttributeKeys
     * @param list<string> $tenantAttributeKeys
     */
    public function __construct(
        private array $scopeAttributeKeys = ['authorization.scope', 'scope'],
        private array $tenantAttributeKeys = ['tenant.id', 'tenant_id'],
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

        foreach ($updates as $key => $value) {
            if (array_key_exists($key, $attributes) && $attributes[$key] === $value) {
                unset($updates[$key]);
            }
        }

        if ($updates === []) {
            return $context;
        }

        return $context->mergeAttributes($updates);
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
