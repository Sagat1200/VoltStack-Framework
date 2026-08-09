<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Context;

use Quantum\Controllers\ControllerExecutionContext;
use Quantum\Controllers\Security\Budget\ControllerSecurityBudget;
use Quantum\Controllers\Security\Contracts\ControllerSecurityContextFactoryInterface;
use Quantum\Controllers\Security\Decision\SecurityDecisionCache;
use Quantum\Http\Request;

final class ControllerSecurityContextFactory implements ControllerSecurityContextFactoryInterface
{
    public function __construct(
        private readonly int $defaultMaxEvaluations = 64,
    ) {}

    public function create(
        Request $request,
        ControllerExecutionContext $execution,
    ): ControllerSecurityContext {
        $authHeader = $request->server('Authorization', '');
        if ($authHeader === null || $authHeader === '') {
            $authHeader = $request->server('HTTP_AUTHORIZATION', '');
        }
        if ($authHeader === null || $authHeader === '') {
            $authHeader = $request->header('Authorization', '') ?? '';
        }
        $tenantHeader = $request->server('X-Tenant-Id', '');
        if ($tenantHeader === null || $tenantHeader === '') {
            $tenantHeader = $request->server('HTTP_X_TENANT_ID', '');
        }
        if ($tenantHeader === null || $tenantHeader === '') {
            $tenantHeader = $request->header('X-Tenant-Id', '') ?? '';
        }

        $principal = Principal::anonymous();
        $authStrength = AuthenticationStrength::Anonymous;
        $roles = [];
        $permissions = [];
        $extraClaims = [];

        if (is_string($authHeader) && $authHeader !== '' && str_starts_with(strtolower($authHeader), 'bearer ')) {
            $token = trim(substr($authHeader, 7));
            if ($token !== '') {
                $parts = explode('.', $token, 3);
                $payloadDecoded = null;
                if (count($parts) === 3) {
                    try {
                        $payloadJson = base64_decode(strtr($parts[1], '-_', '+/'), true);
                        if ($payloadJson !== false) {
                            $payloadDecoded = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
                        }
                    } catch (\Throwable) {
                        $payloadDecoded = null;
                    }
                }

                if (is_array($payloadDecoded)) {
                    $sub = $payloadDecoded['sub'] ?? ('api-' . substr(hash('xxh128', $token), 0, 10));
                    $type = PrincipalType::tryFrom((string)($payloadDecoded['type'] ?? 'api_client')) ?? PrincipalType::ApiClient;
                    $roles = array_values(array_unique(array_map('strval', (array)($payloadDecoded['roles'] ?? []))));
                    $permissions = array_values(array_unique(array_map('strval', (array)($payloadDecoded['permissions'] ?? []))));
                    $mfa = isset($payloadDecoded['amr']) && in_array('mfa', (array)$payloadDecoded['amr'], true);
                    $authStrength = match (true) {
                        $mfa => AuthenticationStrength::MultiFactor,
                        $roles !== [] || isset($payloadDecoded['email']) => AuthenticationStrength::Token,
                        default => AuthenticationStrength::Password,
                    };
                    $extraClaims = $payloadDecoded;
                    $principal = new Principal(
                        id: is_string($sub) && $sub !== '' ? $sub : ('api-' . substr(hash('xxh128', $token), 0, 10)),
                        type: $type,
                        authenticated: true,
                        claims: array_merge([
                            'roles' => $roles,
                            'permissions' => $permissions,
                        ], array_diff_key($extraClaims, array_flip(['roles', 'permissions', 'sub']))),
                    );
                } else {
                    $principal = new Principal(
                        id: 'token-' . substr(hash('xxh128', $token), 0, 10),
                        type: PrincipalType::ApiClient,
                        authenticated: true,
                        claims: ['token_prefix' => substr($token, 0, 8)],
                    );
                    $authStrength = AuthenticationStrength::Token;
                }
            }
        }

        $tenant = null;
        if (is_string($tenantHeader) && trim($tenantHeader) !== '') {
            $tenantId = trim($tenantHeader);
            $tenant = new TenantIdentity(
                id: $tenantId,
                source: 'http_header:x-tenant-id',
                verified: true,
            );
        }

        $scopes = [];
        try {
            $scopeHeader = $request->server('X-Scopes', null);
            if ($scopeHeader === null || $scopeHeader === '') {
                $scopeHeader = $request->server('HTTP_X_SCOPES', null);
            }
            if ($scopeHeader === null || $scopeHeader === '') {
                $scopeHeader = $request->header('X-Scopes', null);
            }
            if (is_string($scopeHeader) && $scopeHeader !== '') {
                $scopes = array_values(array_filter(array_map('trim', explode(',', $scopeHeader))));
            }
        } catch (\Throwable) {
        }

        $attributes = new SecurityAttributes(array_merge([
            'scopes' => $scopes,
            'tenant_id' => $tenant?->id,
        ], $extraClaims !== [] ? ['token_claims' => $extraClaims] : []));

        $decisions = new SecurityDecisionCache(maxItems: $this->defaultMaxEvaluations);
        $executionId = $execution->execution?->id() ?? ('exec-' . bin2hex(random_bytes(6)));
        $budget = new ControllerSecurityBudget(maxPolicyEvaluations: $this->defaultMaxEvaluations);

        return new ControllerSecurityContext(
            principal: $principal,
            tenant: $tenant,
            authenticationStrength: $authStrength,
            attributes: $attributes,
            decisions: $decisions,
            executionId: $executionId,
            budget: $budget,
            version: 1,
        );
    }
}