<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Policy;

use Quantum\Controllers\Security\Contracts\ControllerSecurityDecisionEngineInterface;
use Quantum\Controllers\Security\Contracts\ControllerSecurityPolicyRegistryInterface;
use Quantum\Controllers\Security\Context\AuthenticationStrength;
use Quantum\Controllers\Security\Decision\SecurityDecision;
use Quantum\Controllers\Security\Decision\SecurityDecisionEffect;
use Quantum\Controllers\Security\Decision\SecurityDecisionKey;
use Quantum\Controllers\Security\Decision\SecurityEvaluationRequest;
use Quantum\Controllers\Security\Exceptions\SecurityInfrastructureFailureException;
use VoltStack\Framework\Application;

final class ControllerSecurityDecisionEngine implements ControllerSecurityDecisionEngineInterface
{
    public function __construct(
        private readonly ControllerSecurityPolicyRegistryInterface $registry,
        private readonly ?Application $app = null,
    ) {}

    public function decide(SecurityEvaluationRequest $request): SecurityDecision
    {
        $budget = $request->security->budget;
        $decisionsSoFar = $request->security->decisions->count();

        if ($decisionsSoFar >= $budget->maxPolicyEvaluations) {
            return SecurityDecision::deny(
                policyId: 'security.budget_exceeded',
                reasonCode: 'max_policy_evaluations_exceeded',
            );
        }

        $metadata = $request->metadata;
        $explicitPolicyIds = $metadata['policies'] ?? null;
        $explicitPermissions = array_key_exists('permissions', $metadata);
        $permissions = $metadata['permissions'] ?? null;
        $authRequired = $metadata['authentication_required'] ?? null;
        $tenantRequired = $metadata['tenant_required'] ?? null;
        $defaults = $this->app?->config('controller_security.defaults', []) ?: [];
        $denyByDefault = $defaults['deny_by_default'] ?? true;
        $abstainAsDeny = $this->app?->config('controller_security.authorization.abstain_as_deny', true) ?? true;
        $failClosed = $defaults['fail_closed'] ?? true;

        $isAuthRequired = false;
        $minAuthStrength = 0;
        if (is_bool($authRequired)) {
            $isAuthRequired = $authRequired;
            $minAuthStrength = $isAuthRequired ? AuthenticationStrength::Password->value : 0;
        } elseif (is_array($authRequired)) {
            $isAuthRequired = true;
            $minAuthStrength = (int) ($authRequired['minimum_strength_value'] ?? AuthenticationStrength::Password->value);
        } elseif ($authRequired !== null && $authRequired !== '' && $authRequired !== 'false' && $authRequired !== '0') {
            $isAuthRequired = true;
            $minAuthStrength = AuthenticationStrength::Password->value;
        }

        if ($isAuthRequired) {
            $obligations = [
                'required_strength_value' => $minAuthStrength,
            ];
            if (! $request->security->principal->authenticated()) {
                return SecurityDecision::challenge(
                    'security.authentication_required',
                    'authentication_required',
                    $obligations,
                );
            }
            if ($request->security->authenticationStrength->value < $minAuthStrength) {
                $obligations['current_strength_value'] = $request->security->authenticationStrength->value;
                return SecurityDecision::challenge(
                    'security.authentication_required',
                    'authentication_strength_insufficient',
                    $obligations,
                );
            }
        }

        $isTenantRequired = false;
        $requireTenantVerified = null;
        $allowedTenants = null;
        if (is_bool($tenantRequired)) {
            $isTenantRequired = $tenantRequired;
            if ($tenantRequired) {
                $requireTenantVerified = null;
            }
        } elseif (is_array($tenantRequired)) {
            $isTenantRequired = true;
            $requireTenantVerified = array_key_exists('verified', $tenantRequired) ? (bool) $tenantRequired['verified'] : null;
            if (isset($tenantRequired['allowed_tenants']) && is_array($tenantRequired['allowed_tenants'])) {
                $allowedTenants = $tenantRequired['allowed_tenants'];
            }
        } elseif ($tenantRequired !== null && $tenantRequired !== '' && $tenantRequired !== 'false' && $tenantRequired !== '0') {
            $isTenantRequired = true;
        }

        if ($isTenantRequired) {
            if (! $request->security->hasTenant()) {
                return SecurityDecision::deny('security.tenant_required', 'tenant_required', [
                    'tenant_required' => true,
                ]);
            }
            if ($requireTenantVerified === true && ! $request->security->tenant->verified) {
                return SecurityDecision::deny('security.tenant_required', 'tenant_verification_required');
            }
            if ($allowedTenants !== null && ! in_array($request->security->tenant->id, $allowedTenants, true)) {
                return SecurityDecision::deny('security.tenant_required', 'tenant_not_in_allowed_list');
            }
        }

        $permits = 0;
        $denials = [];
        $challenges = [];

        try {
            $candidates = is_array($explicitPolicyIds) && count($explicitPolicyIds) > 0
                ? array_map(fn(string $id) => $this->registry->resolve($id), $explicitPolicyIds)
                : iterator_to_array($this->registry->all(), false);
        } catch (SecurityInfrastructureFailureException $e) {
            return SecurityDecision::deny(
                policyId: 'security.infrastructure',
                reasonCode: $failClosed ? 'policy_resolution_failed_fail_closed' : 'policy_resolution_failed_fail_open',
            );
        }

        if (count($candidates) === 0 && ! is_array($permissions) && $denyByDefault) {
            return SecurityDecision::deny('security.deny_by_default', 'no_policy_registered_deny_by_default');
        }

        foreach ($candidates as $policy) {
            $cacheKey = new SecurityDecisionKey(
                principalId: $request->security->principal->id(),
                tenantId: $request->security->tenant?->id ?? '',
                policyId: $policy->id(),
                action: $request->action,
                resourceIdentity: is_object($request->resource) ? spl_object_hash($request->resource) : (string) $request->resource,
                securityContextVersion: $request->security->version,
            );
            $cached = $request->security->decisions->get($cacheKey);
            if ($cached !== null) {
                $decision = $cached;
            } else {
                try {
                    $decision = $policy->evaluate($request);
                } catch (\Throwable $e) {
                    if (! $failClosed) {
                        $decision = SecurityDecision::abstain($policy->id(), 'policy_evaluation_error_fail_open');
                    } else {
                        return SecurityDecision::deny(
                            policyId: 'security.infrastructure',
                            reasonCode: 'policy_evaluation_exception_fail_closed',
                            obligations: ['policy_id' => $policy->id()],
                        );
                    }
                }
                $request->security->decisions->put($cacheKey, $decision);
            }

            switch ($decision->effect) {
                case SecurityDecisionEffect::Deny:
                    $denials[] = $decision;
                    break 2;
                case SecurityDecisionEffect::Challenge:
                    $challenges[] = $decision;
                    break;
                case SecurityDecisionEffect::Allow:
                    $permits++;
                    break;
                case SecurityDecisionEffect::Abstain:
                default:
                    break;
            }
        }

        if (count($denials) > 0) {
            return $denials[0];
        }
        if (count($challenges) > 0) {
            return $challenges[0];
        }

        if (count($candidates) === 0) {
            if ($explicitPermissions && is_array($permissions) && count($permissions) === 0 && ! $authRequired && ! $tenantRequired && ! $denyByDefault) {
                return SecurityDecision::abstain('security.open_action', 'no_policy_no_requirements');
            }

            return $denyByDefault
                ? SecurityDecision::deny('security.deny_by_default', 'no_explicit_decision_deny_by_default')
                : SecurityDecision::allow('security.fail_open', 'no_explicit_decision_allow_by_default');
        }

        if ($permits === 0) {
            return $abstainAsDeny
                ? SecurityDecision::deny('security.abstain_as_deny', 'all_policies_abstained')
                : SecurityDecision::allow('security.all_abstained', 'all_policies_abstained');
        }

        return SecurityDecision::allow('security.granted', 'at_least_one_policy_allowed', array_reduce(
            $candidates,
            fn(array $acc, $p) => array_merge($acc, $this->lastObligations($p, $request)),
            [],
        ));
    }

    private function lastObligations(object $policy, SecurityEvaluationRequest $request): array
    {
        if (! $policy instanceof ControllerSecurityPolicy) {
            return [];
        }
        try {
            $d = $policy->evaluate($request);

            return $d->obligations;
        } catch (\Throwable) {
            return [];
        }
    }
}
