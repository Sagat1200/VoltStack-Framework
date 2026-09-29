<?php

declare(strict_types=1);

namespace Quantum\Auth\Authenticators;

use Quantum\Auth\Context\AuthenticationContext;
use Quantum\Auth\Context\AuthenticationRequest;
use Quantum\Auth\Contracts\AuthenticatorInterface;
use Quantum\Auth\Contracts\IdentityProviderInterface;
use Quantum\Auth\Contracts\PasskeyAwareIdentityProviderInterface;
use Quantum\Auth\Contracts\PasskeyCredentialStoreInterface;
use Quantum\Auth\Decisions\AuthenticationDecision;
use Quantum\Auth\Decisions\AuthenticationDecisionStatus;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Auth\Passkeys\AssertionResult;
use Quantum\Auth\Passkeys\PasskeyAssertionCeremony;
use Quantum\Auth\Runtime\AuthenticationOperationContext;
use Quantum\Auth\Support\AuthenticationAssurance;

/**
 * V2 PasskeyAuthenticator — candidate priority 900 para autenticación por WebAuthn passkeys.
 *
 * Requiere:
 *   - PasskeyAssertionCeremony configurado con RP correcto.
 *   - PasskeyCredentialStoreInterface con credenciales almacenadas (registradas previamente).
 *   - IdentityProviderInterface para resolver la identidad canónica desde user_handle.
 *
 * Backward compat: si NO se reciben credenciales de passkey en el request → supports() = false →
 *   orchestrator continua al siguiente authenticator (Password, Session, Bearer).
 */
final class PasskeyAuthenticator implements AuthenticatorInterface
{
    public function __construct(
        private readonly PasskeyAssertionCeremony $assertionCeremony,
        private readonly PasskeyCredentialStoreInterface $credentialStore,
        private readonly IdentityProviderInterface $identityProvider,
    ) {
    }

    public function supports(AuthenticationOperationContext $context): bool
    {
        $credentials = $context->request->attributes['credentials'] ?? [];
        if (! is_array($credentials)) {
            return false;
        }
        $mechanism = $credentials['mechanism'] ?? null;
        if ($mechanism === 'passkey') {
            return true;
        }
        return isset($credentials['passkey_assertion']) && is_array($credentials['passkey_assertion']);
    }

    public function authenticate(AuthenticationOperationContext $context): AuthenticationDecision
    {
        $request = $context->request;
        $credentials = $request->attributes['credentials'] ?? [];
        if (! is_array($credentials)) {
            return $this->reject($request, 'invalid_credentials_shape');
        }
        $assertion = $credentials['passkey_assertion'] ?? null;
        if (! is_array($assertion)) {
            return $this->reject($request, 'passkey_assertion_missing');
        }

        $result = $this->assertionCeremony->verifyAssertion($assertion, $this->credentialStore);

        if (! $result->isValid) {
            $reason = is_array($result->metadata) && isset($result->metadata['failure_reason'])
                ? (string) $result->metadata['failure_reason']
                : 'passkey_invalid';
            return $this->reject($request, $reason);
        }

        $userHandle = $result->userHandle;
        if ($userHandle === null || $userHandle === '') {
            return $this->reject($request, 'passkey_empty_user_handle');
        }

        $identity = null;
        if ($this->identityProvider instanceof PasskeyAwareIdentityProviderInterface) {
            $identity = $this->identityProvider->findByUserHandle($userHandle);
        }
        if ($identity === null) {
            $identity = $this->identityProvider->findByIdentifier($userHandle);
        }

        if ($identity === null) {
            return $this->reject($request, 'passkey_identity_not_resolved');
        }

        $amr = ['passkey', 'hwk'];
        if ($result->metadata['user_verified'] ?? false) {
            $amr[] = 'uv';
        }
        $assuranceProfile = AuthenticationAssurance::composeAssuranceFromAmr(
            new \Quantum\Auth\Runtime\AuthenticationMethodReferenceList($amr)
        );
        $attributes = [
            'amr' => $amr,
            'assurance_profile' => $assuranceProfile->value,
        ];
        $authContext = new AuthenticationContext(
            identity: $identity,
            reference: new IdentityReference(
                identifier: $identity->identifier(),
                type: $identity->type(),
            ),
            requestId: $context->request->requestId,
            method: 'passkey',
            attributes: AuthenticationAssurance::enrichAttributes($attributes, 'passkey'),
        );

        return new AuthenticationDecision(
            status: AuthenticationDecisionStatus::Authenticated,
            context: $authContext,
            metadata: [
                'authenticator' => 'passkey',
                'reason' => 'passkey_ok',
                'credential_id' => $result->credentialId,
                'sign_count' => $result->signCountIncremented,
                'metadata' => $result->metadata,
            ],
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function reject(AuthenticationRequest $request, string $reason, array $extra = []): AuthenticationDecision
    {
        return AuthenticationDecision::rejected(array_merge([
            'authenticator' => 'passkey',
            'reason' => $reason,
        ], $extra));
    }
}
