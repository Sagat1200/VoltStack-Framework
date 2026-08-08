<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Policy\Composition;

use Quantum\Controllers\Security\Contracts\ControllerSecurityPolicyInterface;
use Quantum\Controllers\Security\Decision\SecurityDecision;
use Quantum\Controllers\Security\Decision\SecurityDecisionEffect;
use Quantum\Controllers\Security\Decision\SecurityEvaluationRequest;

/**
 * Simple matcher policy used by expression parser: e.g. "role:admin" → check metadata match.
 * Expects metadata "roles" array / "permissions" array / custom key.
 */
final class ExpressionTermPolicy implements ControllerSecurityPolicyInterface
{
    public function __construct(
        private readonly string $policyId,
        private readonly string $term,
    ) {}

    public function id(): string
    {
        return $this->policyId;
    }

    public function evaluate(SecurityEvaluationRequest $request): SecurityDecision
    {
        $term = $this->term;
        $metadata = $request->metadata;
        $security = $request->security;

        $principal = $security->principal;
        $claims = $principal->claims();
        $roles = (array)($claims['roles'] ?? []);
        $permissions = (array)($claims['permissions'] ?? []);
        $attrs = $security->attributes->attributes;
        $isAuthenticated = $principal->authenticated();
        $tenant = $security->tenant;

        if (str_starts_with($term, 'role:')) {
            $role = substr($term, 5);
            if (in_array($role, $roles, true)) {
                return SecurityDecision::allow($this->policyId, 'term_role_match', ['matched_term' => $term]);
            }
            return SecurityDecision::deny($this->policyId, 'term_role_mismatch', ['requested_role' => $role, 'actual_roles' => $roles]);
        }
        if (str_starts_with($term, 'permission:') || str_starts_with($term, 'perm:')) {
            $key = str_starts_with($term, 'perm:') ? 'perm:' : 'permission:';
            $perm = substr($term, strlen($key));
            if (in_array($perm, $permissions, true)) {
                return SecurityDecision::allow($this->policyId, 'term_permission_match', ['matched_term' => $term]);
            }
            return SecurityDecision::deny($this->policyId, 'term_permission_mismatch', ['requested_permission' => $perm]);
        }
        if (str_starts_with($term, 'scope:')) {
            $scope = substr($term, 6);
            $scopes = (array)($attrs['scopes'] ?? []);
            if (in_array($scope, $scopes, true)) {
                return SecurityDecision::allow($this->policyId, 'term_scope_match', ['matched_term' => $term]);
            }
            return SecurityDecision::deny($this->policyId, 'term_scope_mismatch', ['requested_scope' => $scope, 'actual_scopes' => $scopes]);
        }
        if (str_starts_with($term, 'tenant:') || str_starts_with($term, 'tenant_id:')) {
            $k = str_starts_with($term, 'tenant:') ? 'tenant:' : 'tenant_id:';
            $expected = substr($term, strlen($k));
            $actual = $tenant?->id;
            if ($actual === $expected) {
                return SecurityDecision::allow($this->policyId, 'term_tenant_match', ['matched_term' => $term]);
            }
            return SecurityDecision::deny($this->policyId, 'term_tenant_mismatch', ['expected' => $expected, 'actual' => $actual ?? 'null']);
        }
        if ($term === 'authenticated') {
            if ($isAuthenticated) {
                return SecurityDecision::allow($this->policyId, 'term_authenticated');
            }
            return SecurityDecision::deny($this->policyId, 'term_not_authenticated');
        }
        if ($term === 'guest') {
            if (!$isAuthenticated) {
                return SecurityDecision::allow($this->policyId, 'term_guest');
            }
            return SecurityDecision::deny($this->policyId, 'term_not_guest');
        }
        if (str_contains($term, ':')) {
            [$k, $v] = explode(':', $term, 2);
            $actual = $attrs[$k] ?? null;
            if (is_array($actual)) {
                if (in_array($v, $actual, true)) {
                    return SecurityDecision::allow($this->policyId, 'term_attr_array_match', ['matched_term' => $term]);
                }
            } elseif ((string)($actual ?? '') === $v) {
                return SecurityDecision::allow($this->policyId, 'term_attr_match', ['matched_term' => $term]);
            }
            return SecurityDecision::deny($this->policyId, 'term_attr_mismatch', ['key' => $k, 'expected' => $v, 'actual' => var_export($actual, true)]);
        }
        if (isset($attrs[$term]) && $attrs[$term] === true) {
            return SecurityDecision::allow($this->policyId, 'term_flag_match', ['matched_term' => $term]);
        }
        if (in_array($term, $permissions, true) || in_array($term, $roles, true)) {
            return SecurityDecision::allow($this->policyId, 'term_perm_or_role_match');
        }
        return SecurityDecision::deny($this->policyId, 'term_unknown_mismatch', ['term' => $term]);
    }
}
