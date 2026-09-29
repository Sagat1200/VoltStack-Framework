<?php

declare(strict_types=1);

namespace Quantum\Auth\Passkeys;

use Quantum\Auth\Context\AuthenticationContext;
use Quantum\Auth\Context\AuthenticationRequest;
use Quantum\Auth\Contracts\AuthenticatorInterface;
use Quantum\Auth\Contracts\IdentityProviderInterface;
use Quantum\Auth\Contracts\PasskeyCredentialStoreInterface;
use Quantum\Auth\Decisions\AuthenticationDecision;
use Quantum\Auth\Decisions\AuthenticationDecisionStatus;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Auth\Runtime\AuthenticationOperationContext;
use Quantum\Auth\Support\AuthenticationAssurance;

final class PasskeyAuthenticator implements AuthenticatorInterface
{
    public function __construct(
        private readonly RelyingPartyConfig $rp,
        private readonly PasskeyCredentialStoreInterface $store,
        private readonly ?IdentityProviderInterface $identityProvider = null,
    ) {
    }

    public function supports(AuthenticationOperationContext $context): bool
    {
        $request = $context->request;
        $attrs = $request->attributes;

        $hasB64 = is_string($attrs['passkey_client_data_json_b64'] ?? null)
            && is_string($attrs['passkey_authenticator_data_b64'] ?? null)
            && is_string($attrs['passkey_signature_b64'] ?? null)
            && is_string($attrs['passkey_credential_id_b64'] ?? null);

        if ($hasB64) {
            return true;
        }

        $body = $request->body;
        return is_string($body['passkey_client_data_json_b64'] ?? null)
            && is_string($body['passkey_authenticator_data_b64'] ?? null)
            && is_string($body['passkey_signature_b64'] ?? null)
            && is_string($body['passkey_credential_id_b64'] ?? null);
    }

    public function authenticate(AuthenticationOperationContext $context): AuthenticationDecision
    {
        $request = $context->request;
        $payload = $this->extractPayload($request);

        if ($payload === null) {
            return AuthenticationDecision::rejected([
                'reason' => 'passkey_missing_payload',
                'passkey' => true,
            ]);
        }

        $ceremony = new PasskeyAssertionCeremony($this->rp);
        $result = $ceremony->verifyAssertion($payload, $this->store);

        if (! $result->isValid) {
            return AuthenticationDecision::rejected([
                'reason' => $result->metadata['failure_reason'] ?? 'passkey_assertion_failed',
                'passkey' => true,
                'assertion_metadata' => $result->metadata,
            ]);
        }

        $userHandle = $result->userHandle;
        if ($userHandle === null || $userHandle === '') {
            return AuthenticationDecision::rejected([
                'reason' => 'passkey_missing_user_handle',
                'passkey' => true,
            ]);
        }

        $identity = null;
        if ($this->identityProvider !== null) {
            try {
                $identity = $this->identityProvider->findByIdentifier($userHandle);
            } catch (\Throwable) {
                $identity = null;
            }
        }

        if ($identity === null) {
            return AuthenticationDecision::rejected([
                'reason' => 'passkey_identity_not_found',
                'passkey' => true,
                'user_handle' => $userHandle,
            ]);
        }

        $amr = ['pwd', 'hwk', 'passkey'];
        $assurance = AuthenticationAssurance::composeAssuranceFromAmr($amr);
        $ctx = AuthenticationContext::fromIdentity(
            $identity,
            amr: $amr,
            assuranceProfile: $assurance,
            metadata: [
                'passkey' => true,
                'credential_id' => $result->credentialId,
                'sign_count' => $result->signCountIncremented,
                'signature_alg' => $result->metadata['signature_alg'] ?? null,
            ],
        );

        return AuthenticationDecision::authenticated(
            context: $ctx,
            metadata: [
                'reason' => 'passkey_assertion_valid',
                'passkey' => true,
                'assertion_metadata' => $result->metadata,
            ],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractPayload(AuthenticationRequest $request): ?array
    {
        $fromAttrs = [
            'client_data_json_b64' => $request->attributes['passkey_client_data_json_b64'] ?? null,
            'authenticator_data_b64' => $request->attributes['passkey_authenticator_data_b64'] ?? null,
            'signature_b64' => $request->attributes['passkey_signature_b64'] ?? null,
            'credential_id_b64' => $request->attributes['passkey_credential_id_b64'] ?? null,
            'user_handle' => $request->attributes['passkey_user_handle'] ?? null,
        ];
        if (is_string($fromAttrs['client_data_json_b64'])) {
            return array_filter($fromAttrs, static fn (mixed $v): bool => $v !== null);
        }

        $fromBody = [
            'client_data_json_b64' => $request->body['passkey_client_data_json_b64'] ?? null,
            'authenticator_data_b64' => $request->body['passkey_authenticator_data_b64'] ?? null,
            'signature_b64' => $request->body['passkey_signature_b64'] ?? null,
            'credential_id_b64' => $request->body['passkey_credential_id_b64'] ?? null,
            'user_handle' => $request->body['passkey_user_handle'] ?? null,
        ];
        if (is_string($fromBody['client_data_json_b64'])) {
            return array_filter($fromBody, static fn (mixed $v): bool => $v !== null);
        }

        return null;
    }
}
