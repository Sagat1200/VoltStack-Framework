<?php

declare(strict_types=1);

namespace Quantum\Auth\Authenticators;

use Quantum\Auth\Context\AuthenticationContext;
use Quantum\Auth\Contracts\AuthenticatorInterface;
use Quantum\Auth\Contracts\IdentityProviderInterface;
use Quantum\Auth\Contracts\MultiFactorIdentityProviderInterface;
use Quantum\Auth\Contracts\PasswordPolicyInterface;
use Quantum\Auth\Contracts\PasswordRehashingIdentityProviderInterface;
use Quantum\Auth\Contracts\TrustedDeviceRepositoryInterface;
use Quantum\Auth\Credentials\PasswordCredentials;
use Quantum\Auth\Decisions\AuthenticationDecision;
use Quantum\Auth\Devices\TrustedDeviceCredentialValidator;
use Quantum\Auth\Exceptions\IdentityNotEligibleException;
use Quantum\Auth\Exceptions\InvalidSecondFactorException;
use Quantum\Auth\Exceptions\InvalidCredentialsException;
use Quantum\Auth\Exceptions\SecondFactorNotAvailableException;
use Quantum\Auth\Exceptions\SecondFactorRequiredException;
use Quantum\Auth\Exceptions\StepUpAuthenticationRequiredException;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Auth\Runtime\AuthenticationOperationContext;
use Quantum\Auth\Support\AuthenticationAssurance;
use Quantum\Config\ConfigRepository;
use Quantum\Controllers\Security\Context\AuthenticationStrength;

final class PasswordAuthenticator implements AuthenticatorInterface
{
    public function __construct(
        private readonly IdentityProviderInterface $identityProvider,
        private readonly PasswordPolicyInterface $passwordPolicy,
        private readonly TrustedDeviceRepositoryInterface $trustedDevices,
        private readonly ConfigRepository $config,
    ) {}

    public function supports(AuthenticationOperationContext $context): bool
    {
        return in_array($context->operation, ['authenticate', 'step_up'], true)
            && is_array($context->request->attribute('credentials', null));
    }

    public function authenticate(AuthenticationOperationContext $context): AuthenticationDecision
    {
        if ($context->operation === 'step_up') {
            return $this->authenticateStepUp($context);
        }

        $credentials = PasswordCredentials::fromArray(
            is_array($context->request->attribute('credentials', null))
                ? $context->request->attribute('credentials', [])
                : [],
        );

        if ($credentials === null) {
            return AuthenticationDecision::rejected([
                'reason' => 'missing_credentials',
                'authenticator' => 'password',
                'exception' => InvalidCredentialsException::class,
            ]);
        }

        $identity = $this->identityProvider->findByIdentifier($credentials->identifier);

        if ($identity === null) {
            return AuthenticationDecision::rejected([
                'reason' => 'invalid_credentials',
                'authenticator' => 'password',
                'exception' => InvalidCredentialsException::class,
            ]);
        }

        $securityState = $this->identityProvider->securityStateFor($identity);

        if (! $securityState->isEligibleForAuthentication()) {
            return AuthenticationDecision::rejected([
                'reason' => 'identity_not_eligible',
                'authenticator' => 'password',
                'security_state' => $securityState->value,
                'exception' => IdentityNotEligibleException::class,
            ]);
        }

        $passwordHash = $this->identityProvider->passwordHashFor($identity);

        if (! is_string($passwordHash) || $passwordHash === '' || ! $this->passwordPolicy->verify($credentials->password, $passwordHash)) {
            return AuthenticationDecision::rejected([
                'reason' => 'invalid_credentials',
                'authenticator' => 'password',
                'exception' => InvalidCredentialsException::class,
            ]);
        }

        $needsRehash = $this->passwordPolicy->needsRehash($passwordHash);
        $rehashed = false;
        $contextAttributes = [];
        $secondFactorSatisfied = false;
        $reference = new IdentityReference(
            identifier: $identity->identifier(),
            type: $identity->type(),
        );
        $trustedDeviceValidation = $this->trustedDeviceCredentialValidator()->validate(
            is_string($context->request->attribute('trusted_device_credential'))
                ? trim((string) $context->request->attribute('trusted_device_credential'))
                : null,
            $reference,
            $this->deviceReference($context, $reference),
        );
        $trustedDevice = $trustedDeviceValidation->device;
        $trustedDeviceInvalid = $trustedDeviceValidation->invalid;
        $trustedDeviceChallengeReduced = false;

        if ($needsRehash && $this->identityProvider instanceof PasswordRehashingIdentityProviderInterface) {
            $rehashed = $this->identityProvider->upgradePasswordHash(
                $identity,
                $this->passwordPolicy->hash($credentials->password),
            );
        }

        if ($this->identityProvider instanceof MultiFactorIdentityProviderInterface) {
            $requiresSecondFactor = $this->identityProvider->requiresSecondFactor($identity);
            $providedSecondFactor = $credentials->secondFactor;
            $supportsSecondFactor = $this->identityProvider->supportsSecondFactor($identity);

            if ($supportsSecondFactor && $providedSecondFactor !== null && $providedSecondFactor !== '') {
                if (! $this->identityProvider->verifySecondFactor($identity, $providedSecondFactor)) {
                    return AuthenticationDecision::rejected([
                        'reason' => 'invalid_second_factor',
                        'authenticator' => 'password',
                        'trusted_device_invalid' => $trustedDeviceInvalid,
                        'exception' => InvalidSecondFactorException::class,
                    ]);
                }

                $secondFactorSatisfied = true;
                $contextAttributes['amr'] = ['pwd', 'mfa'];
                $contextAttributes['authentication_strength'] = AuthenticationStrength::MultiFactor->name;
                $contextAttributes['authentication_assurance_profile'] = 'multi_factor';
            } elseif ($requiresSecondFactor && $trustedDevice === null) {
                return AuthenticationDecision::rejected([
                    'reason' => 'second_factor_required',
                    'authenticator' => 'password',
                    'trusted_device_invalid' => $trustedDeviceInvalid,
                    'trusted_device_replayed' => $trustedDeviceValidation->replayed,
                    'exception' => SecondFactorRequiredException::class,
                ]);
            } elseif ($requiresSecondFactor && $trustedDevice !== null) {
                $trustedDeviceChallengeReduced = true;
            }
        }

        if ($trustedDevice !== null) {
            $contextAttributes['trusted_device_credential_present'] = true;
            $contextAttributes['trusted_device_public_id'] = $trustedDevice->publicId->value;
            $contextAttributes['session_device_trust_state'] = 'trusted';
        }

        return AuthenticationDecision::authenticated(
            new AuthenticationContext(
                identity: $identity,
                reference: new IdentityReference(
                    identifier: $identity->identifier(),
                    type: $identity->type(),
                ),
                requestId: $context->request->requestId,
                method: 'password',
                attributes: AuthenticationAssurance::enrichAttributes($contextAttributes, 'password'),
            ),
            [
                'authenticator' => 'password',
                'identifier' => $credentials->identifier,
                'password_needs_rehash' => $needsRehash,
                'password_rehashed' => $rehashed,
                'second_factor_satisfied' => $secondFactorSatisfied,
                'trusted_device_invalid' => $trustedDeviceInvalid,
                'trusted_device_replayed' => $trustedDeviceValidation->replayed,
                'trusted_device_public_id' => $trustedDevice?->publicId->value,
                'trusted_device_rotate' => $trustedDeviceChallengeReduced && $this->rotateTrustedDeviceOnChallengeReduction(),
            ],
        );
    }

    private function authenticateStepUp(AuthenticationOperationContext $context): AuthenticationDecision
    {
        $current = $context->currentContext;

        if ($current === null) {
            return AuthenticationDecision::rejected([
                'reason' => 'step_up_requires_authentication',
                'authenticator' => 'password',
                'exception' => StepUpAuthenticationRequiredException::class,
            ]);
        }

        if ($current->authenticationStrength()->value >= AuthenticationStrength::MultiFactor->value) {
            return AuthenticationDecision::authenticated($current, [
                'authenticator' => 'password',
                'operation' => 'step_up',
                'step_up_satisfied' => true,
                'step_up_noop' => true,
            ]);
        }

        if (! $this->identityProvider instanceof MultiFactorIdentityProviderInterface) {
            return AuthenticationDecision::rejected([
                'reason' => 'second_factor_not_available',
                'authenticator' => 'password',
                'exception' => SecondFactorNotAvailableException::class,
            ]);
        }

        if (! $this->identityProvider->supportsSecondFactor($current->identity)) {
            return AuthenticationDecision::rejected([
                'reason' => 'second_factor_not_available',
                'authenticator' => 'password',
                'exception' => SecondFactorNotAvailableException::class,
            ]);
        }

        $credentials = is_array($context->request->attribute('credentials', null))
            ? $context->request->attribute('credentials', [])
            : [];
        $secondFactor = PasswordCredentials::secondFactorFromArray($credentials);

        if ($secondFactor === null || trim($secondFactor) === '') {
            return AuthenticationDecision::rejected([
                'reason' => 'second_factor_required',
                'authenticator' => 'password',
                'exception' => SecondFactorRequiredException::class,
            ]);
        }

        if (! $this->identityProvider->verifySecondFactor($current->identity, $secondFactor)) {
            return AuthenticationDecision::rejected([
                'reason' => 'invalid_second_factor',
                'authenticator' => 'password',
                'exception' => InvalidSecondFactorException::class,
            ]);
        }

        $attributes = array_merge($current->attributes, [
            'amr' => ['pwd', 'mfa'],
            'authentication_strength' => AuthenticationStrength::MultiFactor->name,
            'authentication_assurance_profile' => 'multi_factor',
        ]);

        return AuthenticationDecision::authenticated(
            new AuthenticationContext(
                identity: $current->identity,
                reference: $current->reference,
                requestId: $context->request->requestId,
                method: $current->method,
                attributes: AuthenticationAssurance::enrichAttributes($attributes, $current->method),
            ),
            [
                'authenticator' => 'password',
                'operation' => 'step_up',
                'step_up_satisfied' => true,
            ],
        );
    }

    private function trustedDeviceCredentialValidator(): TrustedDeviceCredentialValidator
    {
        return new TrustedDeviceCredentialValidator($this->trustedDevices, $this->config);
    }

    private function deviceReference(AuthenticationOperationContext $context, IdentityReference $reference): ?string
    {
        $fingerprintParts = array_filter([
            strtolower(trim((string) $context->request->attribute('trusted_device_host', ''))),
            strtolower(trim((string) $context->request->attribute('trusted_device_user_agent', ''))),
            strtolower(trim((string) $context->request->attribute('trusted_device_accept_language', ''))),
            strtolower($reference->identifier->value),
            strtolower($reference->type),
        ], static fn (string $value): bool => $value !== '');

        if ($fingerprintParts === []) {
            return null;
        }

        return 'devref_' . substr(hash_hmac(
            'sha256',
            implode('|', $fingerprintParts),
            $this->deviceReferenceSalt(),
        ), 0, 20);
    }

    private function rotateTrustedDeviceOnChallengeReduction(): bool
    {
        return (bool) $this->config->get('auth.trusted_devices.rotation.on_challenge_reduction', true);
    }

    private function deviceReferenceSalt(): string
    {
        $configured = $this->config->get('auth.session.device.reference_salt');

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $appKey = $this->config->get('app.key');

        if (is_string($appKey) && trim($appKey) !== '') {
            return trim($appKey);
        }

        return 'voltstack-auth-device-reference';
    }
}
