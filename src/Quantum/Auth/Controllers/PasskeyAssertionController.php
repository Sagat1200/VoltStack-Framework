<?php

declare(strict_types=1);

namespace Quantum\Auth\Controllers;

use Quantum\Auth\Context\AuthenticationContext;
use Quantum\Auth\Context\AuthenticationRequest;
use Quantum\Auth\Contracts\AuthenticationOrchestratorInterface;
use Quantum\Auth\Contracts\IdentityProviderInterface;
use Quantum\Auth\Contracts\TransactionNonceStoreInterface;
use Quantum\Auth\Identity\GenericIdentity;
use Quantum\Auth\Identity\IdentityIdentifier;
use Quantum\Auth\Identity\IdentityInterface;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Auth\Passkeys\PasskeyAssertionCeremony;
use Quantum\Auth\Passkeys\RelyingPartyConfig;
use Quantum\Auth\Runtime\AuthenticationOperationContext;
use Quantum\Auth\Runtime\CsrfChallengeBinder;
use Quantum\Auth\Runtime\NonceRecord;
use Quantum\Auth\Support\AuthenticationAssurance;
use Quantum\Auth\Tokens\BearerTokenService;
use Quantum\Config\ConfigRepository;
use Quantum\Controllers\Controller;
use Quantum\Controllers\Security\Context\AuthenticationStrength;
use Quantum\Http\Request;
use Quantum\Http\Response;

final class PasskeyAssertionController extends Controller
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly RelyingPartyConfig $rpConfig,
        private readonly AuthenticationOrchestratorInterface $orchestrator,
        private readonly BearerTokenService $bearerTokens,
        private readonly IdentityProviderInterface $identityProvider,
    ) {}

    public function challenge(Request $request): Response
    {
        if (! $this->passkeysEnabled()) {
            return $this->disabledResponse($request);
        }

        $mode = $this->mode($request);
        $currentContext = $mode === 'step_up' ? $this->currentBearerAuthenticationContext($request) : null;

        if ($mode === 'step_up' && ! $currentContext instanceof AuthenticationContext) {
            return $this->json([
                'endpoint' => $this->responseEndpoint($request),
                'reason_code' => 'auth.step_up_requires_authentication',
                'mode' => $mode,
            ], 401, $this->headers([
                'X-Auth-Passkey-Mode' => $mode,
            ]));
        }

        $userHandle = $currentContext instanceof AuthenticationContext
            ? $this->passkeyUserHandleForContext($currentContext)
            : $this->nonEmptyString(
            $request->input('user_handle', $request->input('identifier')),
        );
        $challenge = (new PasskeyAssertionCeremony($this->rpConfig))->beginAssertion($userHandle);
        $transaction = $this->issueChallengeTransaction(
            $request,
            $mode,
            $currentContext,
            $userHandle,
            $challenge,
        );

        $payload = [
            'endpoint' => $this->responseEndpoint($request),
            'mode' => $mode,
            'operation' => $this->nonEmptyString($request->input('operation')) ?? 'auth.passkeys.assertion',
            'challenge' => $challenge,
            'complete_endpoint' => $this->completeEndpoint($request),
            'target_continuation_endpoint' => $this->nonEmptyString($request->input('target_continuation_endpoint')),
            'transaction' => $transaction,
        ];

        if ($currentContext instanceof AuthenticationContext) {
            $payload['current_principal_id'] = $currentContext->identity->identifier()->value;
            $payload['current_user_handle'] = $this->passkeyUserHandleForContext($currentContext);
            $payload['current_strength'] = $currentContext->authenticationStrength()->name;
            $payload['current_assurance'] = $this->assuranceValueForContext(
                $currentContext,
                $currentContext->authenticationStrength()->value,
            );
        }

        return $this->json($payload, 200, $this->headers([
            'X-Auth-Passkey-Mode' => $mode,
            'X-Auth-Passkey-Complete-Endpoint' => $this->completeEndpoint($request),
        ]));
    }

    public function complete(Request $request): Response
    {
        if (! $this->passkeysEnabled()) {
            return $this->disabledResponse($request);
        }

        $mode = $this->mode($request);
        $currentContext = $mode === 'step_up' ? $this->currentBearerAuthenticationContext($request) : null;

        if ($mode === 'step_up' && ! $currentContext instanceof AuthenticationContext) {
            return $this->json([
                'endpoint' => $this->responseEndpoint($request),
                'reason_code' => 'auth.step_up_requires_authentication',
                'mode' => $mode,
            ], 401, $this->headers([
                'X-Auth-Passkey-Mode' => $mode,
            ]));
        }

        $assertion = $this->passkeyAssertionPayload($request);
        if ($assertion === []) {
            return $this->json([
                'endpoint' => $this->responseEndpoint($request),
                'reason_code' => 'auth.passkey_assertion_missing',
                'mode' => $mode,
            ], 400, $this->headers([
                'X-Auth-Passkey-Mode' => $mode,
            ]));
        }

        $challengeStateError = $this->validateChallengeTransaction(
            $request,
            $assertion,
            $mode,
            $currentContext,
        );
        if ($challengeStateError instanceof Response) {
            return $challengeStateError;
        }

        $operation = $mode === 'step_up' ? 'step_up' : 'authenticate';
        $decision = $this->orchestrator->execute(new AuthenticationOperationContext(
            operation: $operation,
            request: new AuthenticationRequest(
                requestId: $this->requestIdentifier($request),
                transport: 'http',
                attributes: [
                    'credentials' => [
                        'mechanism' => 'passkey',
                        'passkey_assertion' => $assertion,
                    ],
                ],
            ),
            currentContext: $currentContext,
        ));

        if (! $decision->isAuthenticated() || ! $decision->context instanceof AuthenticationContext) {
            $reason = $this->nonEmptyString($decision->metadata['reason'] ?? null) ?? 'auth.failed';

            return $this->json([
                'endpoint' => $this->responseEndpoint($request),
                'mode' => $mode,
                'reason_code' => $reason,
                'decision_metadata' => $decision->metadata,
            ], 401, $this->headers([
                'X-Auth-Passkey-Mode' => $mode,
                'X-Auth-Passkey-Decision' => 'rejected',
            ]));
        }

        if (
            $mode === 'step_up'
            && $currentContext instanceof AuthenticationContext
            && ! $this->sameIdentity($currentContext, $decision->context)
        ) {
            return $this->json([
                'endpoint' => $this->responseEndpoint($request),
                'mode' => $mode,
                'reason_code' => 'auth.step_up_identity_mismatch',
                'current_principal_id' => $currentContext->identity->identifier()->value,
                'resolved_principal_id' => $decision->context->identity->identifier()->value,
            ], 403, $this->headers([
                'X-Auth-Passkey-Mode' => $mode,
                'X-Auth-Passkey-Decision' => 'identity_mismatch',
            ]));
        }

        $payload = [
            'endpoint' => $this->responseEndpoint($request),
            'mode' => $mode,
            'status' => 'authenticated',
            'principal_id' => $decision->context->identity->identifier()->value,
            'principal_type' => $decision->context->identity->type(),
            'authentication_method' => $decision->context->method,
            'authentication_strength' => $decision->context->authenticationStrength()->name,
            'current_assurance' => $this->assuranceValueForContext(
                $decision->context,
                $decision->context->authenticationStrength()->value,
            ),
            'credential_id' => $decision->metadata['credential_id'] ?? null,
            'sign_count' => $decision->metadata['sign_count'] ?? null,
            'decision_metadata' => $decision->metadata,
            'target_continuation_endpoint' => $this->nonEmptyString($request->input('target_continuation_endpoint')),
        ];

        if ($mode === 'step_up' && $currentContext instanceof AuthenticationContext) {
            $payload['previous_strength'] = $currentContext->authenticationStrength()->name;
            $payload['previous_assurance'] = $this->assuranceValueForContext(
                $currentContext,
                $currentContext->authenticationStrength()->value,
            );

            $continuation = $this->issueStepUpResultContinuation($request, $decision->context);
            if ($continuation !== null) {
                $payload['continuation_request'] = $continuation;
            }
        }

        return $this->json($payload, 200, $this->headers([
            'X-Auth-Passkey-Mode' => $mode,
            'X-Auth-Passkey-Decision' => 'authenticated',
            'X-Auth-Authentication-Strength' => $decision->context->authenticationStrength()->name,
            'X-Auth-Assurance-Current' => (string) $this->assuranceValueForContext(
                $decision->context,
                $decision->context->authenticationStrength()->value,
            ),
            'X-Auth-Step-Up-Completed' => $mode === 'step_up' ? 'true' : null,
        ]));
    }

    private function passkeysEnabled(): bool
    {
        return (bool) $this->config->get('auth.passkeys.enabled', false);
    }

    private function mode(Request $request): string
    {
        $mode = strtolower(trim((string) $request->input('mode', 'authenticate')));

        return $mode === 'step_up' ? 'step_up' : 'authenticate';
    }

    /**
     * @return array<string,mixed>
     */
    private function passkeyAssertionPayload(Request $request): array
    {
        $input = $request->all();
        $assertion = is_array($input['passkey_assertion'] ?? null) ? $input['passkey_assertion'] : $input;

        return is_array($assertion) ? $assertion : [];
    }

    /**
     * @param array<string,mixed> $assertion
     */
    private function validateChallengeTransaction(
        Request $request,
        array $assertion,
        string $mode,
        ?AuthenticationContext $currentContext,
    ): ?Response {
        $store = $this->transactionNonceStore();
        if (! $store instanceof TransactionNonceStoreInterface) {
            return null;
        }

        $authNonce = $this->nonEmptyString($request->input('auth_nonce'))
            ?? $this->nonEmptyString($assertion['auth_nonce'] ?? null);
        $csrfChallenge = $this->nonEmptyString($request->input('csrf_challenge'))
            ?? $this->nonEmptyString($assertion['csrf_challenge'] ?? null);

        if ($authNonce === null || $csrfChallenge === null) {
            return $this->json([
                'endpoint' => $this->responseEndpoint($request),
                'mode' => $mode,
                'reason_code' => 'auth.passkey_challenge_state_missing',
            ], 400, $this->headers([
                'X-Auth-Passkey-Mode' => $mode,
            ]));
        }

        $csrfBinder = new CsrfChallengeBinder();
        if (! $csrfBinder->verifyChallenge($csrfChallenge, $authNonce, $currentContext?->deviceReference())) {
            return $this->json([
                'endpoint' => $this->responseEndpoint($request),
                'mode' => $mode,
                'reason_code' => 'auth.invalid_csrf_challenge',
            ], 401, $this->headers([
                'X-Auth-Passkey-Mode' => $mode,
            ]));
        }

        $validation = $store->validateNonce(
            $authNonce,
            $this->expectedChallengeBindings($request, $mode, $currentContext),
        );

        if (! $validation->valid || ! $validation->nonceRecord instanceof NonceRecord) {
            return $this->json([
                'endpoint' => $this->responseEndpoint($request),
                'mode' => $mode,
                'reason_code' => 'auth.invalid_transaction_nonce',
                'nonce_reason' => $validation->reasonCode,
            ], 401, $this->headers([
                'X-Auth-Passkey-Mode' => $mode,
            ]));
        }

        $clientData = $this->decodedClientData($assertion);
        $expectedChallenge = $this->nonEmptyString(
            $validation->nonceRecord->bindingClaims['passkey_challenge'] ?? null,
        );

        if (
            $expectedChallenge !== null
            && ($clientData['challenge'] ?? null) !== $expectedChallenge
        ) {
            return $this->json([
                'endpoint' => $this->responseEndpoint($request),
                'mode' => $mode,
                'reason_code' => 'auth.passkey_challenge_mismatch',
            ], 401, $this->headers([
                'X-Auth-Passkey-Mode' => $mode,
            ]));
        }

        return null;
    }

    /**
     * @param array<string,mixed> $challenge
     * @return array<string,mixed>|null
     */
    private function issueChallengeTransaction(
        Request $request,
        string $mode,
        ?AuthenticationContext $currentContext,
        ?string $userHandle,
        array $challenge,
    ): ?array {
        $store = $this->transactionNonceStore();
        if (! $store instanceof TransactionNonceStoreInterface) {
            return null;
        }

        $record = $store->issueNonce($this->transactionNonceTtl(), array_filter([
            'flow' => 'passkey_assertion_challenge',
            'mode' => $mode,
            'operation' => $this->nonEmptyString($request->input('operation')) ?? 'auth.passkeys.assertion',
            'user_handle' => $userHandle,
            'current_principal_id' => $currentContext?->identity->identifier()->value,
            'current_principal_type' => $currentContext?->identity->type(),
            'passkey_challenge' => $this->nonEmptyString($challenge['challenge'] ?? null),
        ], static fn (mixed $value): bool => $value !== null));

        $csrfBinder = new CsrfChallengeBinder();

        return [
            'auth_nonce' => $record->value,
            'csrf_challenge' => $csrfBinder->issueChallenge($record->value, $currentContext?->deviceReference()),
            'expires_at' => $record->expiresAt,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function issueStepUpResultContinuation(Request $request, AuthenticationContext $context): ?array
    {
        $target = $this->nonEmptyString($request->input('target_continuation_endpoint'));
        $store = $this->transactionNonceStore();

        if ($target === null || ! $store instanceof TransactionNonceStoreInterface) {
            return null;
        }

        $record = $store->issueNonce($this->transactionNonceTtl(), [
            'flow' => 'step_up_result',
            'principal_id' => $context->identity->identifier()->value,
            'principal_type' => $context->identity->type(),
            'target_continuation_endpoint' => $target,
            'authentication_method' => $context->method,
            'authentication_strength' => $context->authenticationStrength()->name,
            'current_assurance' => $this->assuranceValueForContext(
                $context,
                $context->authenticationStrength()->value,
            ),
            'authentication_assurance_profile' => $context->authenticationAssuranceProfile(),
            'amr' => is_array($context->attribute('amr')) ? $context->attribute('amr') : [],
        ]);

        return [
            'endpoint' => $target,
            'method' => 'POST',
            'expires_at' => $record->expiresAt,
            'payload' => [
                'step_up_result' => $record->value,
            ],
        ];
    }

    private function disabledResponse(Request $request): Response
    {
        return $this->json([
            'endpoint' => $this->responseEndpoint($request),
            'reason_code' => 'auth.passkeys_disabled',
        ], 503, $this->headers([]));
    }

    private function responseEndpoint(Request $request): string
    {
        return ltrim($request->path(), '/');
    }

    /**
     * @param array<string, string|null> $headers
     * @return array<string, string>
     */
    private function headers(array $headers): array
    {
        return array_filter([
            'Cache-Control' => 'no-store',
            ...$headers,
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');
    }

    private function completeEndpoint(Request $request): string
    {
        return $this->nonEmptyString($request->input('complete_endpoint')) ?? '/auth/passkeys/assertion/complete';
    }

    /**
     * @return array<string,mixed>
     */
    private function expectedChallengeBindings(
        Request $request,
        string $mode,
        ?AuthenticationContext $currentContext,
    ): array {
        return array_filter([
            'flow' => 'passkey_assertion_challenge',
            'mode' => $mode,
            'operation' => $this->nonEmptyString($request->input('operation')) ?? 'auth.passkeys.assertion',
            'user_handle' => $mode === 'step_up' && $currentContext instanceof AuthenticationContext
                ? $this->passkeyUserHandleForContext($currentContext)
                : $this->nonEmptyString($request->input('user_handle', $request->input('identifier'))),
            'current_principal_id' => $currentContext?->identity->identifier()->value,
            'current_principal_type' => $currentContext?->identity->type(),
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function currentBearerAuthenticationContext(Request $request): ?AuthenticationContext
    {
        $token = $this->extractBearerToken($request);
        if ($token === '') {
            return null;
        }

        $projection = $this->bearerTokens->describeAccessTokenForSecurityContext($token);
        $identifier = $this->nonEmptyString($projection['identifier'] ?? null);

        if ($identifier === null) {
            return null;
        }

        $identity = $this->identityProvider->findByIdentifier($identifier);
        if (! $identity instanceof IdentityInterface) {
            $identity = new GenericIdentity(
                new IdentityIdentifier($identifier),
                $this->nonEmptyString($projection['identity_type'] ?? null) ?? 'user',
                $this->genericIdentityAttributes($projection),
            );
        }

        $tokenAttributes = is_array($projection['attributes'] ?? null) ? $projection['attributes'] : [];
        $derivedStrength = $this->derivedStrengthFromBearerAttributes($tokenAttributes);
        $attributes = array_merge($tokenAttributes, [
            'access_token_id' => $token,
            'access_token_client_id' => $projection['client_id'] ?? null,
            'access_token_scopes' => $projection['scopes'] ?? [],
            'refresh_token_id' => $projection['refresh_token_id'] ?? null,
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

    private function extractBearerToken(Request $request): string
    {
        $authorization = (string) ($request->header('Authorization') ?? '');

        return preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) === 1
            ? trim((string) ($matches[1] ?? ''))
            : '';
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

    private function sameIdentity(AuthenticationContext $left, AuthenticationContext $right): bool
    {
        return $left->identity->identifier()->value === $right->identity->identifier()->value
            && $left->identity->type() === $right->identity->type();
    }

    private function passkeyUserHandleForContext(AuthenticationContext $context): string
    {
        if ($context->identity instanceof GenericIdentity) {
            $providerIdentifier = $context->identity->attributes['_provider_identifier_value'] ?? null;
            if (is_string($providerIdentifier) && trim($providerIdentifier) !== '') {
                return trim($providerIdentifier);
            }
        }

        return $context->identity->identifier()->value;
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @param array<string,mixed> $assertion
     * @return array<string,mixed>
     */
    private function decodedClientData(array $assertion): array
    {
        $encoded = $this->nonEmptyString($assertion['client_data_json_b64'] ?? null);
        if ($encoded === null) {
            return [];
        }

        $decoded = base64_decode($encoded, true);
        if (! is_string($decoded) || $decoded === '') {
            return [];
        }

        try {
            $parsed = json_decode($decoded, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($parsed) ? $parsed : [];
    }

    private function transactionNonceTtl(): int
    {
        return max(1, (int) $this->config->get('auth.transaction.nonce.ttl', 300));
    }

    private function transactionNonceStore(): ?TransactionNonceStoreInterface
    {
        if (! (bool) $this->config->get('auth.transaction.nonce.enabled', false)) {
            return null;
        }

        try {
            $candidate = app(TransactionNonceStoreInterface::class);
        } catch (\Throwable) {
            return null;
        }

        return $candidate instanceof TransactionNonceStoreInterface
            ? $candidate
            : null;
    }
}
