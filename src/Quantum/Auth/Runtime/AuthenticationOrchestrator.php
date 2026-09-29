<?php

declare(strict_types=1);

namespace Quantum\Auth\Runtime;

use Quantum\Auth\Contracts\AuthenticationOrchestratorInterface;
use Quantum\Auth\Contracts\AbuseProtectionThrottleInterface;
use Quantum\Auth\Contracts\AuthenticatorResolverInterface;
use Quantum\Auth\Contracts\TransactionNonceStoreInterface;
use Quantum\Auth\Decisions\AuthenticationDecision;
use Quantum\Auth\Risk\CompositeRiskSignalEngine;

final class AuthenticationOrchestrator implements AuthenticationOrchestratorInterface
{
    public function __construct(
        private readonly AuthenticatorResolverInterface $resolver,
        private readonly ?AbuseProtectionThrottleInterface $throttle = null,
        private readonly ?CompositeRiskSignalEngine $riskEngine = null,
        private readonly ?TransactionNonceStoreInterface $nonceStore = null,
        private readonly ?CsrfChallengeBinder $csrfBinder = null,
    ) {}

    public function execute(AuthenticationOperationContext $context): AuthenticationDecision
    {
        $minAssuranceAttr = $context->request->attributes['min_authentication_assurance'] ?? null;
        $requiredMinAssurance = is_int($minAssuranceAttr) ? $minAssuranceAttr : (is_numeric($minAssuranceAttr) ? (int)$minAssuranceAttr : null);
        if ($requiredMinAssurance !== null && $requiredMinAssurance > 0) {
            $currentAssurance = 0;
            $meta = [];
            if ($context->currentContext !== null) {
                try {
                    $strength = $context->currentContext->authenticationStrength();
                    $currentAssurance = $strength->value;
                    $meta['current_assurance_name'] = $strength->name;
                } catch (\Throwable) {
                    $currentAssurance = 0;
                }
                $assuranceOverride = $context->currentContext->attribute('assurance_value');
                if (is_int($assuranceOverride)) {
                    $currentAssurance = $assuranceOverride;
                    $customName = $context->currentContext->attribute('assurance_name');
                    if (is_string($customName) && $customName !== '') {
                        $meta['current_assurance_name'] = $customName;
                    }
                }
            }
            if ($currentAssurance < $requiredMinAssurance) {
                return AuthenticationDecision::rejected(array_merge([
                    'operation' => $context->operation,
                    'source' => 'orchestrator_preauth_min_assurance',
                    'reason' => 'auth.assurance_insufficient',
                    'required_min_assurance' => $requiredMinAssurance,
                    'current_assurance' => $currentAssurance,
                ], $meta));
            }
        }

        if ($this->nonceStore !== null) {
            $existingNonce = $context->request->attributes['auth_nonce'] ?? null;
            if (is_string($existingNonce) && trim($existingNonce) !== '') {
                $expectedBindings = [];
                $credentials = $context->request->attributes['credentials'] ?? [];
                if (is_array($credentials) && isset($credentials['identifier']) && is_string($credentials['identifier'])) {
                    $expectedBindings['auth_identifier'] = $credentials['identifier'];
                }
                $validation = $this->nonceStore->validateNonce($existingNonce, $expectedBindings);
                if (! $validation->valid) {
                    return AuthenticationDecision::rejected([
                        'operation' => $context->operation,
                        'source' => 'orchestrator_nonce',
                        'reason' => 'auth.invalid_transaction_nonce',
                        'nonce_reason' => $validation->reasonCode,
                    ]);
                }
            }
        }

        if ($this->throttle !== null) {
            $credentials = $context->request->attributes['credentials'] ?? [];
            $identifier = is_array($credentials) && isset($credentials['identifier']) && is_string($credentials['identifier'])
                ? $credentials['identifier']
                : '__unknown_identifier__';
            $deviceRef = $context->request->attributes['device_ref'] ?? null;
            $ipPrefix = $context->request->attributes['ip_prefix'] ?? null;
            $rawPassword = is_array($credentials) && isset($credentials['password']) && is_string($credentials['password'])
                ? $credentials['password']
                : null;
            $deviceRefStr = is_string($deviceRef) ? $deviceRef : null;
            $ipPrefixStr = is_string($ipPrefix) ? $ipPrefix : null;

            $throttleDecision = $this->throttle->decide($identifier, $deviceRefStr, $ipPrefixStr, $rawPassword);
            if ($throttleDecision->isDenied()) {
                return AuthenticationDecision::rejected(array_merge(
                    [
                        'operation' => $context->operation,
                        'source' => 'orchestrator_throttle',
                        'reason' => 'auth.throttled',
                        'throttle_reason' => $throttleDecision->reasonCode,
                        'retry_after_seconds' => $throttleDecision->retryAfterSeconds,
                    ],
                    ['throttle_metadata' => $throttleDecision->metadata],
                ));
            }
        }

        $rejectionMetadata = [];
        $tried = 0;
        $firstDecision = null;

        foreach ($this->resolver->resolve($context) as $authenticator) {
            $tried++;
            $decision = $authenticator->authenticate($context);

            if ($decision->isAuthenticated()) {
                $metadata = $decision->metadata;
                $mutated = false;

                if ($this->riskEngine !== null && $decision->context !== null) {
                    $risk = $this->riskEngine->evaluate($context->request, $decision->context);
                    $metadata['risk_score'] = $risk->score;
                    $metadata['risk_level'] = $risk->level;
                    $metadata['risk_reason_codes'] = $risk->reasonCodes;
                    $metadata['risk_signal_metadata'] = $risk->metadata;
                    $mutated = true;
                }

                if ($this->nonceStore !== null) {
                    $identityId = null;
                    $deviceRef = $context->request->attributes['device_ref'] ?? null;
                    $ipPrefix = $context->request->attributes['ip_prefix'] ?? null;
                    if ($decision->context !== null) {
                        $identityId = $decision->context->identity->identifier()->value ?? null;
                    }
                    $binding = [
                        'identity_id' => is_string($identityId) && $identityId !== '' ? $identityId : null,
                        'device_ref' => is_string($deviceRef) && $deviceRef !== '' ? $deviceRef : null,
                        'ip_prefix' => is_string($ipPrefix) && $ipPrefix !== '' ? $ipPrefix : null,
                    ];
                    $ttl = 300;
                    $nonceRecord = $this->nonceStore->issueNonce($ttl, $binding);
                    $metadata['next_auth_nonce'] = $nonceRecord->value;
                    $metadata['next_auth_nonce_expires_at'] = $nonceRecord->expiresAt;

                    $binder = $this->csrfBinder ?? new CsrfChallengeBinder();
                    $deviceRefStr = is_string($deviceRef) ? $deviceRef : null;
                    $metadata['csrf_challenge'] = $binder->issueChallenge($nonceRecord->value, $deviceRefStr);
                    $mutated = true;
                }

                if ($mutated) {
                    return new AuthenticationDecision(
                        status: $decision->status,
                        context: $decision->context,
                        metadata: $metadata,
                    );
                }
                return $decision;
            }

            if ($firstDecision === null) {
                $firstDecision = $decision;
            }

            $name = $decision->metadata['authenticator'] ?? null;
            $reason = $decision->metadata['reason'] ?? 'rejected';
            $key = is_string($name) && $name !== '' ? $name : ('candidate_' . $tried);

            $rejectionMetadata[$key . '_reason'] = $reason;
            $rejectionMetadata[$key . '_metadata'] = $decision->metadata;
        }

        if ($context->operation === 'recover' && $context->currentContext !== null) {
            return AuthenticationDecision::authenticated($context->currentContext, [
                'operation' => $context->operation,
                'source' => 'current_context',
            ]);
        }

        if ($tried === 1 && $firstDecision !== null) {
            return $firstDecision;
        }

        if ($tried > 1) {
            return AuthenticationDecision::rejected(array_merge(
                [
                    'operation' => $context->operation,
                    'source' => 'orchestrator_composite',
                    'candidates_tried' => $tried,
                ],
                $rejectionMetadata,
            ));
        }

        return AuthenticationDecision::unauthenticated([
            'operation' => $context->operation,
            'source' => 'orchestrator',
        ]);
    }
}