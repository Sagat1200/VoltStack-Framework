<?php

declare(strict_types=1);

namespace Quantum\Auth\Controllers;

use Quantum\Auth\Context\AuthenticationContext;
use Quantum\Auth\Context\AuthenticationRequest;
use Quantum\Auth\Contracts\AuthenticationOrchestratorInterface;
use Quantum\Auth\Contracts\IdentityProviderInterface;
use Quantum\Auth\Contracts\MultiFactorIdentityProviderInterface;
use Quantum\Auth\Exceptions\AssuranceInsufficientException;
use Quantum\Auth\Exceptions\AuthenticationException;
use Quantum\Auth\Exceptions\InvalidCredentialsException;
use Quantum\Auth\Exceptions\InvalidSecondFactorException;
use Quantum\Auth\Exceptions\RevokedAuthenticationSessionException;
use Quantum\Auth\Exceptions\RiskDeniedException;
use Quantum\Auth\Exceptions\SecondFactorNotAvailableException;
use Quantum\Auth\Exceptions\SecondFactorRequiredException;
use Quantum\Auth\Exceptions\StaleAuthenticationSessionException;
use Quantum\Auth\Exceptions\StepUpAuthenticationRequiredException;
use Quantum\Auth\Exceptions\StepUpRequiredException;
use Quantum\Auth\Identity\GenericIdentity;
use Quantum\Auth\Identity\IdentityIdentifier;
use Quantum\Auth\Identity\IdentityInterface;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Auth\Passkeys\PasskeyAssertionCeremony;
use Quantum\Auth\Runtime\AuthenticationOperationContext;
use Quantum\Auth\Support\AuthenticationAssurance;
use Quantum\Auth\Tokens\BearerTokenService;
use Quantum\Config\ConfigRepository;
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
        private readonly AuthenticationOrchestratorInterface $orchestrator,
        private readonly IdentityProviderInterface $identityProvider,
        private readonly ConfigRepository $config,
    ) {}

    #[AuthenticationRequired(minimumStrength: AuthenticationStrength::Token)]
    #[Policies(['role:user || permission:dashboard:read'])]
    public function introspect(Request $request): Response
    {
        return $this->json(
            $this->introspectionPayload($request),
            200,
            $this->securityProjectionHeaders($this->introspectionPayload($request)),
        );
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
        $result = $this->evaluateProtectedOperation($request);

        return $this->json($result['payload'], 200, $result['headers']);
    }

    #[AuthenticationRequired(minimumStrength: AuthenticationStrength::Token)]
    #[Policies(['role:user || permission:dashboard:read'])]
    public function protectedOperationStepUpChallenge(Request $request): Response
    {
        $state = $this->protectedOperationState($request);
        $this->assertRiskNotDenied($state);

        $methods = $this->stepUpMethodMetadata($state['current_context'], $state['effective_required_min_assurance']);
        $payload = $state['payload'];
        $payload['operation'] = [
            'name' => $state['operation_name'],
            'decision' => ($state['step_up_required'] || $state['assurance_insufficient']) ? 'challenge_required' : 'already_satisfied',
            'required_strength' => $state['required_strength']->name,
            'required_min_assurance' => $state['effective_required_min_assurance'],
            'risk_step_up_threshold' => $state['risk_step_up_threshold'],
            'risk_deny_threshold' => $state['risk_deny_threshold'],
        ];
        $payload['challenge'] = [
            'required' => $state['step_up_required'] || $state['assurance_insufficient'],
            'current_strength' => $state['current_strength']->name,
            'current_assurance' => $state['current_assurance'],
            'available_methods' => $methods['names'],
            'recommended_methods' => $methods['recommended'],
            'challenge_endpoint' => $this->stepUpChallengeEndpoint($request),
            'continuation_endpoint' => $this->stepUpContinuationEndpoint($request),
            'methods' => $methods['definitions'],
        ];

        $headers = $this->securityProjectionHeaders($payload);
        $headers['X-Auth-Operation'] = $state['operation_name'];
        $headers['X-Auth-Step-Up-Challenge-Endpoint'] = $this->stepUpChallengeEndpoint($request);
        $headers['X-Auth-Step-Up-Continuation-Endpoint'] = $this->stepUpContinuationEndpoint($request);
        if ($methods['names'] !== []) {
            $headers['X-Auth-Step-Up-Available-Methods'] = implode(',', $methods['names']);
        }
        $headers['X-Auth-Operation-Decision'] = ($state['step_up_required'] || $state['assurance_insufficient'])
            ? 'challenge_required'
            : 'already_satisfied';

        return $this->json($payload, 200, $headers);
    }

    #[AuthenticationRequired(minimumStrength: AuthenticationStrength::Token)]
    #[Policies(['role:user || permission:dashboard:read'])]
    public function protectedOperationStepUpContinue(Request $request): Response
    {
        $state = $this->protectedOperationState($request);
        $this->assertRiskNotDenied($state);

        if (! $state['step_up_required'] && ! $state['assurance_insufficient']) {
            $result = $this->evaluateProtectedOperation($request, $state['current_context']);
            $result['payload']['step_up'] = [
                'decision' => 'not_required',
                'continuation_endpoint' => $this->stepUpContinuationEndpoint($request),
            ];
            $result['headers']['X-Auth-Step-Up-Completed'] = 'not_required';

            return $this->json($result['payload'], 200, $result['headers']);
        }

        $credentials = $this->continuationCredentials($request);
        $decision = $this->orchestrator->execute(new AuthenticationOperationContext(
            operation: 'step_up',
            request: new AuthenticationRequest(
                requestId: $this->requestIdentifier($request),
                transport: 'http',
                attributes: [
                    'credentials' => $credentials,
                ],
            ),
            currentContext: $state['current_context'],
        ));

        if (! $decision->isAuthenticated() || $decision->context === null) {
            throw $this->exceptionFromDecision($decision->metadata);
        }

        if (! $this->sameIdentity($state['current_context'], $decision->context)) {
            throw new InvalidCredentialsException('Step-up credential does not match the current bearer principal.');
        }

        $result = $this->evaluateProtectedOperation($request, $decision->context);
        $result['payload']['step_up'] = [
            'decision' => 'completed',
            'mechanism' => $this->normalizedMechanism($credentials),
            'previous_strength' => $state['current_strength']->name,
            'current_strength' => $decision->context->authenticationStrength()->name,
            'current_assurance' => $this->assuranceValueForContext(
                $decision->context,
                $decision->context->authenticationStrength()->value,
            ),
            'continuation_endpoint' => $this->stepUpContinuationEndpoint($request),
        ];
        $result['headers']['X-Auth-Step-Up-Completed'] = 'true';
        $result['headers']['X-Auth-Step-Up-Mechanism'] = $this->normalizedMechanism($credentials);

        return $this->json($result['payload'], 200, $result['headers']);
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
    private function introspectionPayload(Request $request, ?AuthenticationContext $authContext = null): array
    {
        $security = $this->security();
        $principal = $security?->principal ?? null;
        $claims = $principal?->claims() ?? [];
        $attributes = $security?->attributes->attributes ?? [];
        $token = $this->extractBearerToken($request);
        $projection = $token !== '' ? $this->bearerTokens->describeAccessTokenForSecurityContext($token) : $this->emptyProjection('authorization_header_missing');
        $tokenAttributes = is_array($projection['attributes'] ?? null) ? $projection['attributes'] : [];

        $effectivePrincipalId = $authContext?->identity->identifier()->value ?? $principal?->id();
        $effectivePrincipalType = $authContext?->identity->type() ?? ($principal?->type()?->value ?? null);
        $effectiveStrength = $authContext?->authenticationStrength()->name ?? ($security?->authenticationStrength->name ?? null);
        $effectiveCurrentAssurance = $authContext !== null
            ? $this->assuranceValueForContext($authContext, $authContext->authenticationStrength()->value)
            : ($attributes['auth_current_assurance'] ?? ($tokenAttributes['current_assurance'] ?? null));
        $effectiveProfile = $authContext?->authenticationAssuranceProfile()
            ?? ($attributes['auth_assurance_profile'] ?? ($tokenAttributes['authentication_assurance_profile'] ?? null));

        return [
            'endpoint' => $this->responseEndpoint($request),
            'active' => $projection['active'],
            'token_type' => $projection['token_type'],
            'principal_id' => $effectivePrincipalId,
            'principal_type' => $effectivePrincipalType,
            'authentication_strength' => $effectiveStrength,
            'client_id' => $projection['client_id'],
            'scopes' => $projection['scopes'],
            'roles' => $claims['roles'] ?? ($tokenAttributes['roles'] ?? []),
            'permissions' => $claims['permissions'] ?? ($tokenAttributes['permissions'] ?? []),
            'family_id' => $projection['family_id'],
            'refresh_token_id' => $projection['refresh_token_id'],
            'issued_at' => $projection['issued_at'],
            'expires_at' => $projection['expires_at'],
            'risk' => [
                'score' => $attributes['auth_risk_score'] ?? ($tokenAttributes['risk_score'] ?? null),
                'level' => $attributes['auth_risk_level'] ?? ($tokenAttributes['risk_level'] ?? null),
            ],
            'assurance' => [
                'current' => $effectiveCurrentAssurance,
                'required_min' => $attributes['auth_required_min_assurance'] ?? ($tokenAttributes['required_min_assurance'] ?? null),
                'profile' => $effectiveProfile,
            ],
        ];
    }

    /**
     * @return array{
     *   payload:array<string,mixed>,
     *   headers:array<string,string>
     * }
     */
    private function evaluateProtectedOperation(Request $request, ?AuthenticationContext $authContext = null): array
    {
        $state = $this->protectedOperationState($request, $authContext);
        $methods = $this->stepUpMethodMetadata($state['current_context'], $state['effective_required_min_assurance']);

        $this->assertRiskNotDenied($state);

        if ($state['step_up_required']) {
            throw new StepUpRequiredException(
                requiredStrength: $state['required_strength'],
                currentStrength: $state['current_strength'],
                operation: $state['operation_name'],
                riskScore: $state['risk_score'],
                riskLevel: $state['risk_level'],
                requiredMinAssurance: $state['effective_required_min_assurance'],
                currentAssurance: $state['current_assurance'],
                challengeEndpoint: $this->stepUpChallengeEndpoint($request),
                continuationEndpoint: $this->stepUpContinuationEndpoint($request),
                availableMethods: $methods['names'],
            );
        }

        if ($state['assurance_insufficient']) {
            throw new AssuranceInsufficientException(
                requiredMinAssurance: $state['effective_required_min_assurance'],
                currentAssurance: $state['current_assurance'],
                operation: $state['operation_name'],
                riskScore: $state['risk_score'],
                riskLevel: $state['risk_level'],
                requiredStrengthName: $state['required_strength']->name,
                currentStrengthName: $state['current_strength']->name,
                challengeEndpoint: $this->stepUpChallengeEndpoint($request),
                continuationEndpoint: $this->stepUpContinuationEndpoint($request),
                availableMethods: $methods['names'],
            );
        }

        $payload = $state['payload'];
        $payload['operation'] = [
            'name' => $state['operation_name'],
            'decision' => 'passed',
            'required_strength' => $state['required_strength']->name,
            'required_min_assurance' => $state['effective_required_min_assurance'],
            'risk_step_up_threshold' => $state['risk_step_up_threshold'],
            'risk_deny_threshold' => $state['risk_deny_threshold'],
        ];

        $headers = $this->securityProjectionHeaders($payload);
        $headers['X-Auth-Operation'] = (string) $payload['operation']['name'];
        $headers['X-Auth-Operation-Decision'] = 'passed';
        $headers['X-Auth-Required-Strength'] = $state['required_strength']->name;

        if ($state['effective_required_min_assurance'] > 0) {
            $headers['X-Auth-Assurance-Required-Min'] = (string) $state['effective_required_min_assurance'];
        }
        if ($state['risk_step_up_threshold'] !== null) {
            $headers['X-Auth-Risk-Step-Up-Threshold'] = (string) $state['risk_step_up_threshold'];
        }
        if ($state['risk_deny_threshold'] !== null) {
            $headers['X-Auth-Risk-Deny-Threshold'] = (string) $state['risk_deny_threshold'];
        }

        return [
            'payload' => $payload,
            'headers' => $headers,
        ];
    }

    /**
     * @return array{
     *   payload:array<string,mixed>,
     *   current_context:AuthenticationContext,
     *   current_strength:AuthenticationStrength,
     *   required_strength:AuthenticationStrength,
     *   current_assurance:int,
     *   effective_required_min_assurance:int,
     *   risk_score:?int,
     *   risk_level:?string,
     *   risk_step_up_threshold:?int,
     *   risk_deny_threshold:?int,
     *   operation_name:string,
     *   step_up_required:bool,
     *   assurance_insufficient:bool
     * }
     */
    private function protectedOperationState(Request $request, ?AuthenticationContext $authContext = null): array
    {
        $currentContext = $authContext ?? $this->currentBearerAuthenticationContext($request);
        $payload = $this->introspectionPayload($request, $currentContext);
        $risk = is_array($payload['risk'] ?? null) ? $payload['risk'] : [];
        $currentStrength = $currentContext->authenticationStrength();
        $requiredStrength = AuthenticationAssurance::resolveExplicitStrength(
            $request->routeMeta('required_strength', AuthenticationStrength::MultiFactor->name),
        ) ?? AuthenticationStrength::MultiFactor;
        $baseRequiredMinAssurance = $this->normalizeInt($request->routeMeta('required_min_assurance'), 0) ?? 0;
        $riskStepUpThreshold = $this->normalizeInt($request->routeMeta('risk_step_up_threshold'));
        $riskDenyThreshold = $this->normalizeInt($request->routeMeta('risk_deny_threshold'));
        $riskElevatedMinAssurance = $this->normalizeInt($request->routeMeta('risk_elevated_min_assurance'), $baseRequiredMinAssurance) ?? $baseRequiredMinAssurance;
        $riskScore = $this->normalizeInt($risk['score'] ?? null);
        $currentAssurance = $this->normalizeInt(
            $payload['assurance']['current'] ?? null,
            $this->assuranceValueForContext($currentContext, $currentStrength->value),
        ) ?? $currentStrength->value;
        $effectiveRequiredMinAssurance = $baseRequiredMinAssurance;

        if ($riskScore !== null && $riskStepUpThreshold !== null && $riskScore >= $riskStepUpThreshold) {
            $effectiveRequiredMinAssurance = max($effectiveRequiredMinAssurance, $riskElevatedMinAssurance);
        }

        return [
            'payload' => $payload,
            'current_context' => $currentContext,
            'current_strength' => $currentStrength,
            'required_strength' => $requiredStrength,
            'current_assurance' => $currentAssurance,
            'effective_required_min_assurance' => $effectiveRequiredMinAssurance,
            'risk_score' => $riskScore,
            'risk_level' => is_string($risk['level'] ?? null) ? $risk['level'] : null,
            'risk_step_up_threshold' => $riskStepUpThreshold,
            'risk_deny_threshold' => $riskDenyThreshold,
            'operation_name' => (string) $request->routeMeta('operation_name', 'auth.tokens.protected_operation'),
            'step_up_required' => $currentStrength->value < $requiredStrength->value,
            'assurance_insufficient' => $effectiveRequiredMinAssurance > 0 && $currentAssurance < $effectiveRequiredMinAssurance,
        ];
    }

    /**
     * @param array{
     *   operation_name:string,
     *   risk_score:?int,
     *   risk_level:?string,
     *   current_assurance:int,
     *   effective_required_min_assurance:int,
     *   risk_deny_threshold:?int
     * } $state
     */
    private function assertRiskNotDenied(array $state): void
    {
        if (
            $state['risk_score'] !== null
            && $state['risk_deny_threshold'] !== null
            && $state['risk_score'] >= $state['risk_deny_threshold']
        ) {
            throw new RiskDeniedException(
                message: 'The current bearer token has been blocked for this operation by the configured adaptive risk threshold.',
                riskScore: $state['risk_score'],
                denyThreshold: $state['risk_deny_threshold'],
                metadata: [
                    'operation' => $state['operation_name'],
                    'risk_level' => $state['risk_level'],
                    'current_assurance' => $state['current_assurance'],
                    'required_min_assurance' => $state['effective_required_min_assurance'],
                ],
            );
        }
    }

    private function currentBearerAuthenticationContext(Request $request): AuthenticationContext
    {
        $token = $this->extractBearerToken($request);
        $projection = $token !== '' ? $this->bearerTokens->describeAccessTokenForSecurityContext($token) : $this->emptyProjection('authorization_header_missing');
        $identifier = is_string($projection['identifier'] ?? null) ? trim((string) $projection['identifier']) : '';

        if ($identifier === '') {
            throw new InvalidCredentialsException('The current bearer token cannot be projected into an authentication context.');
        }

        $identity = $this->identityProvider->findByIdentifier($identifier);
        if (! $identity instanceof IdentityInterface) {
            $identity = new GenericIdentity(
                new IdentityIdentifier($identifier),
                is_string($projection['identity_type'] ?? null) && trim((string) $projection['identity_type']) !== ''
                    ? trim((string) $projection['identity_type'])
                    : 'user',
                $this->genericIdentityAttributes($projection),
            );
        }

        $tokenAttributes = is_array($projection['attributes'] ?? null) ? $projection['attributes'] : [];
        $derivedStrength = $this->derivedStrengthFromBearerAttributes($tokenAttributes);
        $attributes = array_merge($tokenAttributes, [
            'access_token_id' => $token,
            'access_token_client_id' => $projection['client_id'],
            'access_token_scopes' => $projection['scopes'],
            'access_token_issued_at' => $projection['issued_at'],
            'access_token_expires_at' => $projection['expires_at'],
            'refresh_token_id' => $projection['refresh_token_id'],
            'authentication_strength' => $tokenAttributes['authentication_strength'] ?? $derivedStrength->name,
            'authentication_strength_value' => $tokenAttributes['authentication_strength_value'] ?? $derivedStrength->value,
            'assurance_value' => $tokenAttributes['current_assurance'] ?? $derivedStrength->value,
            'assurance_name' => $tokenAttributes['authentication_assurance_profile'] ?? AuthenticationAssurance::profileFor($derivedStrength),
        ]);

        return new AuthenticationContext(
            identity: $identity,
            reference: new IdentityReference(
                identifier: $identity->identifier(),
                type: $identity->type(),
            ),
            requestId: $this->requestIdentifier($request),
            method: 'bearer',
            attributes: AuthenticationAssurance::enrichAttributes($attributes, 'bearer'),
        );
    }

    /**
     * @param array<string,mixed> $projection
     * @return array<string,mixed>
     */
    private function genericIdentityAttributes(array $projection): array
    {
        $attributes = is_array($projection['attributes'] ?? null) ? $projection['attributes'] : [];
        $attributes['_provider_identifier_value'] = $projection['identifier'] ?? null;

        return $attributes;
    }

    /**
     * @param AuthenticationContext $currentContext
     * @return array{
     *   names:list<string>,
     *   recommended:list<string>,
     *   definitions:list<array<string,mixed>>
     * }
     */
    private function stepUpMethodMetadata(AuthenticationContext $currentContext, int $requiredMinAssurance): array
    {
        $names = [];
        $recommended = [];
        $definitions = [];

        if (
            $this->identityProvider instanceof MultiFactorIdentityProviderInterface
            && $this->identityProvider->supportsSecondFactor($currentContext->identity)
        ) {
            $names[] = 'second_factor';
            if ($requiredMinAssurance <= AuthenticationStrength::MultiFactor->value) {
                $recommended[] = 'second_factor';
            }
            $definitions[] = [
                'type' => 'second_factor',
                'supported' => true,
                'can_satisfy_requirement' => $requiredMinAssurance <= AuthenticationStrength::MultiFactor->value,
                'fields' => ['second_factor'],
            ];
        }

        if ((bool) $this->config->get('auth.passkeys.enabled', false)) {
            $names[] = 'passkey';
            $recommended[] = 'passkey';
            $origins = $this->config->get('auth.passkeys.rp.origins', []);
            $originList = is_array($origins)
                ? array_values(array_filter($origins, static fn (mixed $origin): bool => is_string($origin) && trim($origin) !== ''))
                : [];
            $definitions[] = [
                'type' => 'passkey',
                'supported' => true,
                'can_satisfy_requirement' => true,
                'assertion' => (new PasskeyAssertionCeremony(new \Quantum\Auth\Passkeys\RelyingPartyConfig(
                    rpId: (string) $this->config->get('auth.passkeys.rp.id', 'localhost'),
                    rpName: (string) $this->config->get('auth.passkeys.rp.name', 'VoltStack App'),
                    allowedOrigins: $originList,
                )))->beginAssertion(
                    $currentContext->identity->identifier()->value,
                ),
                'fields' => ['passkey_assertion'],
            ];
        }

        return [
            'names' => array_values(array_unique($names)),
            'recommended' => array_values(array_unique($recommended)),
            'definitions' => $definitions,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function continuationCredentials(Request $request): array
    {
        $input = $request->all();
        $credentials = is_array($input['credentials'] ?? null) ? $input['credentials'] : $input;

        if (! is_array($credentials)) {
            return [];
        }

        if (! isset($credentials['mechanism'])) {
            $credentials['mechanism'] = $this->normalizedMechanism($credentials);
        }

        return $credentials;
    }

    /**
     * @param array<string,mixed> $credentials
     */
    private function normalizedMechanism(array $credentials): string
    {
        $mechanism = strtolower(trim((string) ($credentials['mechanism'] ?? '')));
        if ($mechanism !== '') {
            return $mechanism;
        }
        if (is_array($credentials['passkey_assertion'] ?? null)) {
            return 'passkey';
        }
        if (is_string($credentials['second_factor'] ?? null) && trim((string) $credentials['second_factor']) !== '') {
            return 'second_factor';
        }

        return 'unknown';
    }

    private function sameIdentity(AuthenticationContext $left, AuthenticationContext $right): bool
    {
        return $left->identity->identifier()->value === $right->identity->identifier()->value
            && $left->identity->type() === $right->identity->type();
    }

    private function assuranceValueForContext(AuthenticationContext $context, int $default): int
    {
        $explicit = $context->attribute('assurance_value');

        if (is_int($explicit)) {
            return $explicit;
        }

        if (is_numeric($explicit)) {
            return (int) $explicit;
        }

        return $default;
    }

    /**
     * @param array<string,mixed> $attributes
     */
    private function derivedStrengthFromBearerAttributes(array $attributes): AuthenticationStrength
    {
        $explicit = AuthenticationAssurance::resolveExplicitStrength(
            $attributes['authentication_strength']
                ?? $attributes['authentication_strength_name']
                ?? $attributes['authentication_strength_value']
                ?? null,
        );

        if ($explicit !== null) {
            return $explicit;
        }

        $amr = array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            is_array($attributes['amr'] ?? null) ? $attributes['amr'] : [],
        );

        if (array_intersect($amr, ['passkey', 'hwk', 'hardware', 'hardware_backed']) !== []) {
            return AuthenticationStrength::HardwareBacked;
        }

        if (array_intersect($amr, ['mfa', 'multi_factor', 'otp']) !== []) {
            return AuthenticationStrength::MultiFactor;
        }

        return AuthenticationStrength::Token;
    }

    private function stepUpChallengeEndpoint(Request $request): string
    {
        return (string) $request->routeMeta(
            'step_up_challenge_endpoint',
            '/auth/tokens/protected-operation/step-up/challenge',
        );
    }

    private function stepUpContinuationEndpoint(Request $request): string
    {
        return (string) $request->routeMeta(
            'step_up_continuation_endpoint',
            '/auth/tokens/protected-operation/step-up/continue',
        );
    }

    private function requestIdentifier(Request $request): string
    {
        $fromHeader = $request->header('X-Request-Id');
        if (is_string($fromHeader) && trim($fromHeader) !== '') {
            return trim($fromHeader);
        }

        return 'http_' . substr(
            hash('xxh128', $request->method() . '|' . $request->path() . '|' . spl_object_id($request)),
            0,
            16,
        );
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function exceptionFromDecision(array $metadata): AuthenticationException
    {
        $reason = (string) ($metadata['reason'] ?? 'auth.failed');

        return match ($reason) {
            'step_up_requires_authentication' => new StepUpAuthenticationRequiredException(),
            'second_factor_not_available' => new SecondFactorNotAvailableException(),
            'second_factor_required' => new SecondFactorRequiredException(),
            'invalid_second_factor' => new InvalidSecondFactorException(),
            'session_revoked' => new RevokedAuthenticationSessionException(),
            'session_expired', 'session_not_found' => new StaleAuthenticationSessionException(),
            'invalid_credentials', 'missing_credentials' => new InvalidCredentialsException(),
            default => new AuthenticationException('Authentication failed.', 'auth.failed'),
        };
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
