<?php

declare(strict_types=1);

namespace Quantum\Auth\Authenticators;

use Quantum\Auth\Context\AuthenticationContext;
use Quantum\Auth\Contracts\AuthenticatorInterface;
use Quantum\Auth\Contracts\IdentityProviderInterface;
use Quantum\Auth\Contracts\PasswordPolicyInterface;
use Quantum\Auth\Contracts\PasswordRehashingIdentityProviderInterface;
use Quantum\Auth\Credentials\PasswordCredentials;
use Quantum\Auth\Decisions\AuthenticationDecision;
use Quantum\Auth\Exceptions\IdentityNotEligibleException;
use Quantum\Auth\Exceptions\InvalidCredentialsException;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Auth\Runtime\AuthenticationOperationContext;
use Quantum\Auth\Support\AuthenticationAssurance;

final class PasswordAuthenticator implements AuthenticatorInterface
{
    public function __construct(
        private readonly IdentityProviderInterface $identityProvider,
        private readonly PasswordPolicyInterface $passwordPolicy,
    ) {}

    public function supports(AuthenticationOperationContext $context): bool
    {
        return $context->operation === 'authenticate'
            && is_array($context->request->attribute('credentials', null));
    }

    public function authenticate(AuthenticationOperationContext $context): AuthenticationDecision
    {
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

        if ($needsRehash && $this->identityProvider instanceof PasswordRehashingIdentityProviderInterface) {
            $rehashed = $this->identityProvider->upgradePasswordHash(
                $identity,
                $this->passwordPolicy->hash($credentials->password),
            );
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
                attributes: AuthenticationAssurance::enrichAttributes([], 'password'),
            ),
            [
                'authenticator' => 'password',
                'identifier' => $credentials->identifier,
                'password_needs_rehash' => $needsRehash,
                'password_rehashed' => $rehashed,
            ],
        );
    }
}
