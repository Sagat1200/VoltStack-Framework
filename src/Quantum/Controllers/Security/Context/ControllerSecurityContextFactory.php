<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Context;

use Quantum\Auth\Context\AuthenticationContext;
use Quantum\Auth\Contracts\AuthenticationManagerInterface;
use Quantum\Auth\Identity\GenericIdentity;
use Quantum\Auth\Support\AuthenticationAssurance;
use Quantum\Auth\Tokens\BearerTokenService;
use Quantum\Controllers\ControllerExecutionContext;
use Quantum\Controllers\Security\Budget\ControllerSecurityBudget;
use Quantum\Controllers\Security\Contracts\ControllerSecurityContextFactoryInterface;
use Quantum\Controllers\Security\Decision\SecurityDecisionCache;
use Quantum\Http\Request;

final class ControllerSecurityContextFactory implements ControllerSecurityContextFactoryInterface
{
    public function __construct(
        private readonly int $defaultMaxEvaluations = 64,
        private readonly ?AuthenticationManagerInterface $auth = null,
        private readonly ?BearerTokenService $bearerTokens = null,
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
        $extraAttributes = [];

        if (is_string($authHeader) && $authHeader !== '' && str_starts_with(strtolower($authHeader), 'bearer ')) {
            $token = trim(substr($authHeader, 7));
            if ($token !== '') {
                $jwtClaims = $this->tryDecodeJwtLikeBearerClaims($token);
                if (is_array($jwtClaims)) {
                    [
                        'principal' => $principal,
                        'authenticationStrength' => $authStrength,
                        'roles' => $roles,
                        'permissions' => $permissions,
                        'extraClaims' => $extraClaims,
                        'extraAttributes' => $extraAttributes,
                    ] = $this->securityProjectionFromJwtLikeClaims($jwtClaims, $token);
                } elseif ($this->bearerTokens !== null) {
                    $projection = $this->bearerTokens->describeAccessTokenForSecurityContext($token);
                    if ($projection['active'] === true) {
                        [
                            'principal' => $principal,
                            'authenticationStrength' => $authStrength,
                            'roles' => $roles,
                            'permissions' => $permissions,
                            'extraClaims' => $extraClaims,
                            'extraAttributes' => $extraAttributes,
                        ] = $this->securityProjectionFromOpaqueAccessToken($projection);
                    }
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

        if ($principal->type() === PrincipalType::Anonymous && $this->auth !== null) {
            try {
                $authContext = $this->auth->context();
            } catch (\Throwable) {
                $authContext = null;
            }

            if ($authContext !== null) {
                $principal = $this->principalFromAuthenticationContext($authContext);
                $authStrength = $authContext->authenticationStrength();
                $claims = $principal->claims();
                $roles = array_values(array_unique(array_map('strval', (array) ($claims['roles'] ?? []))));
                $permissions = array_values(array_unique(array_map('strval', (array) ($claims['permissions'] ?? []))));
                $extraClaims = $this->securityAttributesFromAuthenticationContext($authContext);
                $extraAttributes = $extraClaims;
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
        ], $extraAttributes));

        $decisions = new SecurityDecisionCache(maxItems: $this->defaultMaxEvaluations);
        $executionId = $this->buildExecutionId($request, $execution);
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

    private function buildExecutionId(Request $request, ControllerExecutionContext $execution): string
    {
        $routePath = $execution->match()->route()->path();
        $method = $request->method();

        return sprintf(
            'exec-%s',
            substr(hash('xxh128', $method . '|' . $routePath . '|' . spl_object_id($request)), 0, 16),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tryDecodeJwtLikeBearerClaims(string $token): ?array
    {
        $parts = explode('.', $token, 3);
        if (count($parts) !== 3) {
            return null;
        }

        try {
            $payloadJson = base64_decode(strtr($parts[1], '-_', '+/'), true);
            if ($payloadJson === false) {
                return null;
            }

            $decoded = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $payloadDecoded
     * @return array{
     *   principal:Principal,
     *   authenticationStrength:AuthenticationStrength,
     *   roles:array<int,string>,
     *   permissions:array<int,string>,
     *   extraClaims:array<string,mixed>,
     *   extraAttributes:array<string,mixed>
     * }
     */
    private function securityProjectionFromJwtLikeClaims(array $payloadDecoded, string $token): array
    {
        $sub = $payloadDecoded['sub'] ?? ('api-' . substr(hash('xxh128', $token), 0, 10));
        $type = PrincipalType::tryFrom((string) ($payloadDecoded['type'] ?? 'api_client')) ?? PrincipalType::ApiClient;
        $roles = array_values(array_unique(array_map('strval', (array) ($payloadDecoded['roles'] ?? []))));
        $permissions = array_values(array_unique(array_map('strval', (array) ($payloadDecoded['permissions'] ?? []))));
        $mfa = isset($payloadDecoded['amr']) && in_array('mfa', (array) $payloadDecoded['amr'], true);
        $authStrength = match (true) {
            $mfa => AuthenticationStrength::MultiFactor,
            $roles !== [] || isset($payloadDecoded['email']) => AuthenticationStrength::Token,
            default => AuthenticationStrength::Password,
        };
        $extraClaims = $payloadDecoded;
        $extraAttributes = ['token_claims' => $payloadDecoded];
        $principal = new Principal(
            id: is_string($sub) && $sub !== '' ? $sub : ('api-' . substr(hash('xxh128', $token), 0, 10)),
            type: $type,
            authenticated: true,
            claims: array_merge([
                'roles' => $roles,
                'permissions' => $permissions,
            ], array_diff_key($extraClaims, array_flip(['roles', 'permissions', 'sub']))),
        );

        return [
            'principal' => $principal,
            'authenticationStrength' => $authStrength,
            'roles' => $roles,
            'permissions' => $permissions,
            'extraClaims' => $extraClaims,
            'extraAttributes' => $extraAttributes,
        ];
    }

    /**
     * @param array{
     *   active:bool,
     *   found:bool,
     *   reason_code:?string,
     *   token_type:?string,
     *   client_id:?string,
     *   identifier:?string,
     *   identity_type:?string,
     *   scopes:array<int,string>,
     *   issued_at:?int,
     *   expires_at:?int,
     *   family_id:?string,
     *   refresh_token_id:?string,
     *   revoked:bool,
     *   attributes:array<string,mixed>
     * } $projection
     * @return array{
     *   principal:Principal,
     *   authenticationStrength:AuthenticationStrength,
     *   roles:array<int,string>,
     *   permissions:array<int,string>,
     *   extraClaims:array<string,mixed>,
     *   extraAttributes:array<string,mixed>
     * }
     */
    private function securityProjectionFromOpaqueAccessToken(array $projection): array
    {
        $tokenAttributes = $projection['attributes'];
        $roles = array_values(array_unique(array_map('strval', (array) ($tokenAttributes['roles'] ?? []))));
        $permissions = array_values(array_unique(array_map('strval', (array) ($tokenAttributes['permissions'] ?? []))));
        $amr = array_values(array_filter(
            array_map(static fn (mixed $entry): string => trim((string) $entry), (array) ($tokenAttributes['amr'] ?? [])),
            static fn (string $entry): bool => $entry !== '',
        ));

        $authStrength = AuthenticationAssurance::resolveExplicitStrength(
            $tokenAttributes['authentication_strength']
                ?? $tokenAttributes['authentication_strength_name']
                ?? $tokenAttributes['authentication_strength_value']
                ?? null,
        );
        if ($authStrength === null) {
            $authStrength = in_array('hwk', $amr, true) || in_array('hardware_backed', $amr, true)
                ? AuthenticationStrength::HardwareBacked
                : (in_array('mfa', $amr, true) ? AuthenticationStrength::MultiFactor : AuthenticationStrength::Token);
        }

        $extraClaims = array_filter([
            'roles' => $roles,
            'permissions' => $permissions,
            'scopes' => $projection['scopes'],
            'client_id' => $projection['client_id'],
            'family_id' => $projection['family_id'],
            'email' => is_string($tokenAttributes['email'] ?? null) ? $tokenAttributes['email'] : null,
            'name' => is_string($tokenAttributes['name'] ?? null) ? $tokenAttributes['name'] : null,
            'display_name' => is_string($tokenAttributes['display_name'] ?? null) ? $tokenAttributes['display_name'] : null,
            'username' => is_string($tokenAttributes['username'] ?? null) ? $tokenAttributes['username'] : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);

        $extraAttributes = array_filter([
            'auth_bearer_token_type' => $projection['token_type'],
            'auth_bearer_client_id' => $projection['client_id'],
            'auth_bearer_scopes' => $projection['scopes'],
            'auth_bearer_family_id' => $projection['family_id'],
            'auth_bearer_refresh_token_id' => $projection['refresh_token_id'],
            'auth_bearer_issued_at' => $projection['issued_at'],
            'auth_bearer_expires_at' => $projection['expires_at'],
            'auth_bearer_reason_code' => $projection['reason_code'],
            'auth_bearer_active' => $projection['active'],
            'auth_bearer_revoked' => $projection['revoked'],
            'auth_risk_score' => $tokenAttributes['risk_score'] ?? null,
            'auth_risk_level' => $tokenAttributes['risk_level'] ?? null,
            'auth_required_min_assurance' => $tokenAttributes['required_min_assurance'] ?? null,
            'auth_current_assurance' => $tokenAttributes['current_assurance'] ?? null,
            'auth_assurance_profile' => $tokenAttributes['authentication_assurance_profile'] ?? null,
            'amr' => $amr !== [] ? $amr : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);

        $principal = new Principal(
            id: is_string($projection['identifier']) && $projection['identifier'] !== '' ? $projection['identifier'] : 'token-subject',
            type: PrincipalType::tryFrom((string) ($projection['identity_type'] ?? 'user')) ?? PrincipalType::User,
            authenticated: true,
            claims: $extraClaims,
        );

        return [
            'principal' => $principal,
            'authenticationStrength' => $authStrength,
            'roles' => $roles,
            'permissions' => $permissions,
            'extraClaims' => $extraClaims,
            'extraAttributes' => $extraAttributes,
        ];
    }

    private function principalFromAuthenticationContext(AuthenticationContext $context): Principal
    {
        $claims = $this->principalClaimsFromAuthenticationContext($context);

        return new Principal(
            id: (string) $context->identity->identifier(),
            type: $this->principalTypeFromAuthenticationContext($context),
            authenticated: true,
            claims: $claims,
        );
    }

    private function principalTypeFromAuthenticationContext(AuthenticationContext $context): PrincipalType
    {
        $type = trim($context->identity->type());
        $direct = PrincipalType::tryFrom($type);

        if ($direct !== null) {
            return $direct;
        }

        return match (strtolower($type)) {
            'admin', 'administrator', 'member', 'customer' => PrincipalType::User,
            'service_account', 'workload', 'daemon' => PrincipalType::Service,
            'api-key', 'apikey', 'client' => PrincipalType::ApiClient,
            default => PrincipalType::User,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function principalClaimsFromAuthenticationContext(AuthenticationContext $context): array
    {
        $identityAttributes = $this->identityAttributes($context);
        $roles = array_values(array_unique(array_map('strval', (array) ($identityAttributes['roles'] ?? []))));
        $permissions = array_values(array_unique(array_map('strval', (array) ($identityAttributes['permissions'] ?? []))));
        $claims = [
            'roles' => $roles,
            'permissions' => $permissions,
            'management_authority' => $context->managementAuthority(),
            'management_ownership_proof' => $context->managementOwnershipProof(),
            'management_scopes' => $context->managementScopes(),
            'management_claims_source' => $context->managementClaimsSource(),
            'management_privilege_level' => $context->managementPrivilegeLevel(),
        ];

        foreach ([
            'email',
            'name',
            'display_name',
            'username',
        ] as $key) {
            $value = $identityAttributes[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $claims[$key] = trim($value);
            }
        }

        return $claims;
    }

    /**
     * @return array<string, mixed>
     */
    private function securityAttributesFromAuthenticationContext(AuthenticationContext $context): array
    {
        $attributes = array_filter([
            'auth_subject' => (string) $context->identity->identifier(),
            'auth_identity_type' => $context->identity->type(),
            'auth_method' => $context->method,
            'auth_assurance_profile' => $context->authenticationAssuranceProfile(),
            'auth_session_public_id' => $context->sessionPublicId(),
            'auth_device_reference' => $context->deviceReference(),
            'auth_device_trust_state' => $context->deviceTrustState(),
            'auth_trusted_device_public_id' => $context->trustedDevicePublicId(),
            'auth_trusted_device_credential_present' => $context->trustedDeviceCredentialPresent(),
            'auth_management_authority' => $context->managementAuthority(),
            'auth_management_ownership_proof' => $context->managementOwnershipProof(),
            'auth_management_scopes' => $context->managementScopes(),
            'auth_management_claims_source' => $context->managementClaimsSource(),
            'auth_management_privilege_level' => $context->managementPrivilegeLevel(),
            'amr' => $this->stringListAttribute($context, 'amr'),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $claims = $this->principalClaimsFromAuthenticationContext($context);

        if ($claims['roles'] !== []) {
            $attributes['roles'] = $claims['roles'];
        }

        if ($claims['permissions'] !== []) {
            $attributes['permissions'] = $claims['permissions'];
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    private function identityAttributes(AuthenticationContext $context): array
    {
        $identity = $context->identity;

        if ($identity instanceof GenericIdentity) {
            return $identity->attributes;
        }

        return property_exists($identity, 'attributes') && is_array($identity->attributes ?? null)
            ? $identity->attributes
            : [];
    }

    /**
     * @return list<string>|null
     */
    private function stringListAttribute(AuthenticationContext $context, string $key): ?array
    {
        $value = $context->attribute($key);

        if (! is_array($value)) {
            return null;
        }

        return array_values(array_filter(
            array_map(static fn (mixed $entry): string => trim((string) $entry), $value),
            static fn (string $entry): bool => $entry !== '',
        ));
    }
}
