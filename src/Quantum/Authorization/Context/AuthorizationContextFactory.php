<?php

declare(strict_types=1);

namespace Quantum\Authorization\Context;

use Quantum\Auth\Contracts\AuthenticationManagerInterface;
use Quantum\Authorization\Contracts\AuthorizationContextFactoryInterface;
use Quantum\Authorization\Contracts\TenantScopeResolverInterface;
use Quantum\Http\Request;
use VoltStack\Runtime\Context\RuntimeContext;

final class AuthorizationContextFactory implements AuthorizationContextFactoryInterface
{
    public function __construct(
        private readonly ?AuthenticationManagerInterface $auth = null,
        private readonly ?TenantScopeResolverInterface $tenantScopeResolver = null,
        private readonly ?Request $request = null,
        private readonly ?RuntimeContext $runtimeContext = null,
    ) {}

    public function create(?AuthorizationContext $context = null): AuthorizationContext
    {
        if ($context instanceof AuthorizationContext) {
            return $this->tenantScopeResolver?->normalize($context) ?? $context;
        }

        $authContext = null;

        try {
            $authContext = $this->auth?->context();
        } catch (\Throwable) {
            $authContext = null;
        }

        if ($authContext !== null) {
            $riskScore = $authContext->attribute('risk_score');
            $riskLevel = $authContext->attribute('risk_level');

            $context = new AuthorizationContext(
                requestId: $authContext->requestId,
                tenantId: is_string($authContext->attribute('tenant_id')) ? $authContext->attribute('tenant_id') : null,
                channel: is_string($authContext->attribute('channel')) ? $authContext->attribute('channel') : $authContext->method,
                attributes: array_filter([
                    'authentication_method' => $authContext->method,
                    'authentication_assurance_profile' => $authContext->authenticationAssuranceProfile(),
                    'auth_assurance_profile' => $authContext->authenticationAssuranceProfile(),
                    'session_public_id' => $authContext->sessionPublicId(),
                    'device_reference' => $authContext->deviceReference(),
                    'auth_risk_score' => is_int($riskScore) ? $riskScore : (is_string($riskScore) && preg_match('/^-?\d+$/', trim($riskScore)) === 1 ? (int) trim($riskScore) : null),
                    'auth_risk_level' => is_string($riskLevel) && trim($riskLevel) !== '' ? trim($riskLevel) : null,
                ], static fn (mixed $value): bool => $value !== null) + $authContext->attributes,
            );

            return $this->tenantScopeResolver?->normalize($context) ?? $context;
        }

        $runtimeContext = $this->runtimeContext ?? RuntimeContext::current();
        $request = $this->request ?? $runtimeContext?->request();

        if ($runtimeContext === null && $request === null) {
            return AuthorizationContext::empty();
        }

        $runtimeMetadata = $runtimeContext?->metadata() ?? [];
        $tenantId = $this->extractRuntimeTenantId($runtimeMetadata);
        $channel = $this->extractRuntimeChannel($runtimeMetadata);

        $context = new AuthorizationContext(
            requestId: $runtimeContext?->requestId() ?? 'authz-' . bin2hex(random_bytes(8)),
            tenantId: $tenantId,
            channel: $channel,
            attributes: array_filter([
                'request' => $request,
                'runtime_context' => $runtimeContext,
                'runtime.context' => $runtimeContext,
            ], static fn (mixed $value): bool => $value !== null) + $runtimeMetadata,
        );

        return $this->tenantScopeResolver?->normalize($context) ?? $context;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function extractRuntimeTenantId(array $metadata): ?string
    {
        foreach (['tenant.id', 'tenant_id', 'runtime.tenant.id', 'runtime.tenant_id'] as $key) {
            $value = $metadata[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function extractRuntimeChannel(array $metadata): ?string
    {
        foreach (['runtime.channel', 'channel'] as $key) {
            $value = $metadata[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
