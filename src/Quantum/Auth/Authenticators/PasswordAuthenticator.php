<?php

declare(strict_types=1);

namespace Quantum\Auth\Authenticators;

use Quantum\Auth\Context\AuthenticationContext;
use Quantum\Auth\Contracts\AuthenticatorInterface;
use Quantum\Auth\Contracts\IdentityProviderInterface;
use Quantum\Auth\Credentials\PasswordCredentials;
use Quantum\Auth\Decisions\AuthenticationDecision;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Auth\Runtime\AuthenticationOperationContext;

final class PasswordAuthenticator implements AuthenticatorInterface
{
    public function __construct(
        private readonly IdentityProviderInterface $identityProvider,
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
            ]);
        }

        $identity = $this->identityProvider->findByIdentifier($credentials->identifier);

        if ($identity === null) {
            return AuthenticationDecision::rejected([
                'reason' => 'invalid_credentials',
                'authenticator' => 'password',
            ]);
        }

        $passwordHash = $this->identityProvider->passwordHashFor($identity);

        if (! is_string($passwordHash) || $passwordHash === '' || ! password_verify($credentials->password, $passwordHash)) {
            return AuthenticationDecision::rejected([
                'reason' => 'invalid_credentials',
                'authenticator' => 'password',
            ]);
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
            ),
            [
                'authenticator' => 'password',
                'identifier' => $credentials->identifier,
            ],
        );
    }
}