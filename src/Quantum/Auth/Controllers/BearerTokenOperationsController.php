<?php

declare(strict_types=1);

namespace Quantum\Auth\Controllers;

use Quantum\Auth\Exceptions\AssuranceInsufficientException;
use Quantum\Auth\Exceptions\RiskDeniedException;
use Quantum\Auth\Exceptions\StepUpRequiredException;
use Quantum\Auth\Tokens\BearerTokenService;
use Quantum\Auth\Support\AuthenticationAssurance;
use Quantum\Controllers\Controller;
use Quantum\Controllers\Security\Attributes\AuthenticationRequired;
use Quantum\Controllers\Security\Attributes\Policies;
use Quantum\Controllers\Security\Context\AuthenticationStrength;
use Quantum\Http\Request;
use Quantum\Http\Response;

final class BearerTokenOperationsController extends Controller
{
    public function __construct(
        private readonly BearerTokenService $bearerTokens,
    ) {}

    #[AuthenticationRequired(minimumStrength: AuthenticationStrength::Token)]
    #[Policies(['role:user || permission:dashboard:read'])]
    public function introspect(Request $request): Response
    {
        $security = $this->security();
        $principal = $security?->principal ?? null;
        $claims = $principal?->claims() ?? [];
        $attributes = $security?->attributes->attributes ?? [];
        $token = $this->extractBearerToken($request);
        $projection = $token !== '' ? $this->bearerTokens->describeAccessTokenForSecurityContext($token) : $this->emptyProjection('authorization_header_missing');
        $tokenAttributes = is_array($projection['attributes'] ?? null) ? $projection['attributes'] : [];

        $payload = [
            'endpoint' => $this->responseEndpoint($request),
            'active' => $projection['active'],
            'token_type' => $projection['token_type'],
            'principal_id' => $principal?->id(),
            'principal_type' => $principal?->type()?->value ?? null,
            'authentication_strength' => $security?->authenticationStrength->name ?? null,
            'client_id' => $projection['client_id'],
            'scopes' => $projection['scopes'],
            'roles' => $claims['roles'] ?? [],
            'permissions' => $claims['permissions'] ?? [],
            'family_id' => $projection['family_id'],
            'refresh_token_id' => $projection['refresh_token_id'],
            'issued_at' => $projection['issued_at'],
            'expires_at' => $projection['expires_at'],
            'risk' => [
                'score' => $attributes['auth_risk_score'] ?? ($tokenAttributes['risk_score'] ?? null),
                'level' => $attributes['auth_risk_level'] ?? ($tokenAttributes['risk_level'] ?? null),
            ],
            'assurance' => [
                'current' => $attributes['auth_current_assurance'] ?? ($tokenAttributes['current_assurance'] ?? null),
                'required_min' => $attributes['auth_required_min_assurance'] ?? ($tokenAttributes['required_min_assurance'] ?? null),
                'profile' => $attributes['auth_assurance_profile'] ?? ($tokenAttributes['authentication_assurance_profile'] ?? null),
            ],
        ];

        return $this->json($payload, 200, $this->securityProjectionHeaders($payload));
    }

    #[AuthenticationRequired(minimumStrength: AuthenticationStrength::Token)]
    #[Policies(['role:user || permission:dashboard:read'])]
    public function revoke(Request $request): Response
    {
        $principal = $this->security()?->principal ?? null;
        $token = $this->extractBearerToken($request);
        $result = $token !== ''
            ? $this->bearerTokens->revokeAccessTokenPair($token)
            : [
                'status' => 'bad_request',
                'access_token_revoked' => false,
                'refresh_token_revoked' => false,
                'family_id' => null,
                'refresh_token_id' => null,
                'reason_code' => 'authorization_header_missing',
            ];

        $payload = [
            'endpoint' => $this->responseEndpoint($request),
            'status' => $result['status'],
            'principal_id' => $principal?->id(),
            'access_token_revoked' => $result['access_token_revoked'],
            'refresh_token_revoked' => $result['refresh_token_revoked'],
            'family_id' => $result['family_id'],
            'refresh_token_id' => $result['refresh_token_id'],
            'reason_code' => $result['reason_code'],
        ];

        $headers = [
            'Cache-Control' => 'no-store',
            'X-Auth-Bearer-Revoked' => $result['access_token_revoked'] ? 'true' : 'false',
            'X-Auth-Bearer-Revoke-Status' => $result['status'],
        ];

        if (is_string($result['family_id']) && $result['family_id'] !== '') {
            $headers['X-Auth-Bearer-Family'] = $result['family_id'];
        }

        return $this->json($payload, 200, $headers);
    }

    #[AuthenticationRequired(minimumStrength: AuthenticationStrength::Token)]
    #[Policies(['role:user || permission:dashboard:read'])]
    public function protectedOperation(Request $request): Response
    {
        $payload = $this->introspectionPayload($request);
        $risk = is_array($payload['risk'] ?? null) ? $payload['risk'] : [];
        $assurance = is_array($payload['assurance'] ?? null) ? $payload['assurance'] : [];
        $currentStrength = $this->security()?->authenticationStrength ?? AuthenticationStrength::Anonymous;

        $requiredStrength = AuthenticationAssurance::resolveExplicitStrength(
            $request->routeMeta('required_strength', AuthenticationStrength::MultiFactor->name),
        ) ?? AuthenticationStrength::MultiFactor;
        $baseRequiredMinAssurance = $this->normalizeInt($request->routeMeta('required_min_assurance'), 0);
        $riskStepUpThreshold = $this->normalizeInt($request->routeMeta('risk_step_up_threshold'));
        $riskDenyThreshold = $this->normalizeInt($request->routeMeta('risk_deny_threshold'));
        $riskElevatedMinAssurance = $this->normalizeInt($request->routeMeta('risk_elevated_min_assurance'), $baseRequiredMinAssurance);
        $riskScore = $this->normalizeInt($risk['score'] ?? null);
        $currentAssurance = $this->normalizeInt($assurance['current'] ?? null, $currentStrength->value);
        $effectiveRequiredMinAssurance = $baseRequiredMinAssurance;

        if ($riskScore !== null && $riskStepUpThreshold !== null && $riskScore >= $riskStepUpThreshold) {
            $effectiveRequiredMinAssurance = max($effectiveRequiredMinAssurance, $riskElevatedMinAssurance);
        }

        if ($riskScore !== null && $riskDenyThreshold !== null && $riskScore >= $riskDenyThreshold) {
            throw new RiskDeniedException(
                message: 'The current bearer token has been blocked for this operation by the configured adaptive risk threshold.',
                riskScore: $riskScore,
                denyThreshold: $riskDenyThreshold,
                metadata: [
                    'operation' => (string) $request->routeMeta('operation_name', 'auth.tokens.protected_operation'),
                    'risk_level' => $risk['level'] ?? null,
                    'current_assurance' => $currentAssurance,
                    'required_min_assurance' => $effectiveRequiredMinAssurance,
                ],
            );
        }

        if ($currentStrength->value < $requiredStrength->value) {
            throw new StepUpRequiredException(
                requiredStrength: $requiredStrength,
                currentStrength: $currentStrength,
                operation: (string) $request->routeMeta('operation_name', 'auth.tokens.protected_operation'),
                riskScore: $riskScore,
                riskLevel: is_string($risk['level'] ?? null) ? $risk['level'] : null,
                requiredMinAssurance: $effectiveRequiredMinAssurance,
                currentAssurance: $currentAssurance,
            );
        }

        if ($effectiveRequiredMinAssurance > 0 && $currentAssurance < $effectiveRequiredMinAssurance) {
            throw new AssuranceInsufficientException(
                requiredMinAssurance: $effectiveRequiredMinAssurance,
                currentAssurance: $currentAssurance,
                operation: (string) $request->routeMeta('operation_name', 'auth.tokens.protected_operation'),
                riskScore: $riskScore,
                riskLevel: is_string($risk['level'] ?? null) ? $risk['level'] : null,
                requiredStrengthName: $requiredStrength->name,
                currentStrengthName: $currentStrength->name,
            );
        }

        $payload['operation'] = [
            'name' => (string) $request->routeMeta('operation_name', 'auth.tokens.protected_operation'),
            'decision' => 'passed',
            'required_strength' => $requiredStrength->name,
            'required_min_assurance' => $effectiveRequiredMinAssurance,
            'risk_step_up_threshold' => $riskStepUpThreshold,
            'risk_deny_threshold' => $riskDenyThreshold,
        ];

        $headers = $this->securityProjectionHeaders($payload);
        $headers['X-Auth-Operation'] = (string) $payload['operation']['name'];
        $headers['X-Auth-Operation-Decision'] = 'passed';
        $headers['X-Auth-Required-Strength'] = $requiredStrength->name;

        if ($effectiveRequiredMinAssurance > 0) {
            $headers['X-Auth-Assurance-Required-Min'] = (string) $effectiveRequiredMinAssurance;
        }
        if ($riskStepUpThreshold !== null) {
            $headers['X-Auth-Risk-Step-Up-Threshold'] = (string) $riskStepUpThreshold;
        }
        if ($riskDenyThreshold !== null) {
            $headers['X-Auth-Risk-Deny-Threshold'] = (string) $riskDenyThreshold;
        }

        return $this->json($payload, 200, $headers);
    }

    private function extractBearerToken(Request $request): string
    {
        $authorization = (string) ($request->header('Authorization') ?? '');

        return preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) === 1
            ? trim((string) ($matches[1] ?? ''))
            : '';
    }

    private function responseEndpoint(Request $request): string
    {
        return ltrim($request->path(), '/');
    }

    /**
     * @return array<string, mixed>
     */
    private function introspectionPayload(Request $request): array
    {
        $security = $this->security();
        $principal = $security?->principal ?? null;
        $claims = $principal?->claims() ?? [];
        $attributes = $security?->attributes->attributes ?? [];
        $token = $this->extractBearerToken($request);
        $projection = $token !== '' ? $this->bearerTokens->describeAccessTokenForSecurityContext($token) : $this->emptyProjection('authorization_header_missing');
        $tokenAttributes = is_array($projection['attributes'] ?? null) ? $projection['attributes'] : [];

        return [
            'endpoint' => $this->responseEndpoint($request),
            'active' => $projection['active'],
            'token_type' => $projection['token_type'],
            'principal_id' => $principal?->id(),
            'principal_type' => $principal?->type()?->value ?? null,
            'authentication_strength' => $security?->authenticationStrength->name ?? null,
            'client_id' => $projection['client_id'],
            'scopes' => $projection['scopes'],
            'roles' => $claims['roles'] ?? [],
            'permissions' => $claims['permissions'] ?? [],
            'family_id' => $projection['family_id'],
            'refresh_token_id' => $projection['refresh_token_id'],
            'issued_at' => $projection['issued_at'],
            'expires_at' => $projection['expires_at'],
            'risk' => [
                'score' => $attributes['auth_risk_score'] ?? ($tokenAttributes['risk_score'] ?? null),
                'level' => $attributes['auth_risk_level'] ?? ($tokenAttributes['risk_level'] ?? null),
            ],
            'assurance' => [
                'current' => $attributes['auth_current_assurance'] ?? ($tokenAttributes['current_assurance'] ?? null),
                'required_min' => $attributes['auth_required_min_assurance'] ?? ($tokenAttributes['required_min_assurance'] ?? null),
                'profile' => $attributes['auth_assurance_profile'] ?? ($tokenAttributes['authentication_assurance_profile'] ?? null),
            ],
        ];
    }

    private function normalizeInt(mixed $value, ?int $default = null): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * @return array{
     *   endpoint:string,
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
     * }
     */
    private function emptyProjection(string $reasonCode): array
    {
        return [
            'active' => false,
            'found' => false,
            'reason_code' => $reasonCode,
            'token_type' => null,
            'client_id' => null,
            'identifier' => null,
            'identity_type' => null,
            'scopes' => [],
            'issued_at' => null,
            'expires_at' => null,
            'family_id' => null,
            'refresh_token_id' => null,
            'revoked' => false,
            'attributes' => [],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function securityProjectionHeaders(array $payload): array
    {
        $headers = [
            'Cache-Control' => 'no-store',
        ];

        if (is_string($payload['principal_type'] ?? null) && $payload['principal_type'] !== '') {
            $headers['X-Auth-Principal-Type'] = $payload['principal_type'];
        }
        if (is_string($payload['authentication_strength'] ?? null) && $payload['authentication_strength'] !== '') {
            $headers['X-Auth-Authentication-Strength'] = $payload['authentication_strength'];
        }
        if (is_string($payload['client_id'] ?? null) && $payload['client_id'] !== '') {
            $headers['X-Auth-Bearer-Client'] = $payload['client_id'];
        }
        if (is_array($payload['scopes'] ?? null) && $payload['scopes'] !== []) {
            $headers['X-Auth-Bearer-Scopes'] = implode(' ', array_map('strval', $payload['scopes']));
        }
        if (is_string($payload['family_id'] ?? null) && $payload['family_id'] !== '') {
            $headers['X-Auth-Bearer-Family'] = $payload['family_id'];
        }

        $risk = is_array($payload['risk'] ?? null) ? $payload['risk'] : [];
        if (isset($risk['score']) && (is_int($risk['score']) || is_numeric($risk['score']))) {
            $headers['X-Auth-Risk-Score'] = (string) $risk['score'];
        }
        if (is_string($risk['level'] ?? null) && $risk['level'] !== '') {
            $headers['X-Auth-Risk-Level'] = $risk['level'];
        }

        $assurance = is_array($payload['assurance'] ?? null) ? $payload['assurance'] : [];
        if (isset($assurance['current']) && (is_int($assurance['current']) || is_numeric($assurance['current']))) {
            $headers['X-Auth-Assurance-Current'] = (string) $assurance['current'];
        }
        if (isset($assurance['required_min']) && (is_int($assurance['required_min']) || is_numeric($assurance['required_min']))) {
            $headers['X-Auth-Assurance-Required-Min'] = (string) $assurance['required_min'];
        }
        if (is_string($assurance['profile'] ?? null) && $assurance['profile'] !== '') {
            $headers['X-Auth-Assurance-Profile'] = $assurance['profile'];
        }

        return $headers;
    }
}
