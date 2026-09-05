<?php

declare(strict_types=1);

namespace Quantum\Auth\Runtime;

use Quantum\Auth\Authenticators\PasswordAuthenticator;
use Quantum\Auth\Authenticators\SessionAuthenticator;
use Quantum\Auth\Contracts\AuthenticatorInterface;
use Quantum\Auth\Contracts\AuthenticatorResolverInterface;

final class DefaultAuthenticatorResolver implements AuthenticatorResolverInterface
{
    public function __construct(
        private readonly SessionAuthenticator $sessionAuthenticator,
        private readonly PasswordAuthenticator $passwordAuthenticator,
    ) {
    }

    public function resolve(AuthenticationOperationContext $context): array
    {
        $candidates = match ($context->operation) {
            'recover' => [$this->sessionAuthenticator],
            'authenticate' => [$this->passwordAuthenticator],
            default => [],
        };

        return array_values(array_filter(
            $candidates,
            static fn (AuthenticatorInterface $authenticator): bool => $authenticator->supports($context),
        ));
    }
}
