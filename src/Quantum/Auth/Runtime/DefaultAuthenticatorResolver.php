<?php

declare(strict_types=1);

namespace Quantum\Auth\Runtime;

use Quantum\Auth\Authenticators\BearerAuthenticator;
use Quantum\Auth\Authenticators\PasswordAuthenticator;
use Quantum\Auth\Authenticators\SessionAuthenticator;
use Quantum\Auth\Contracts\AuthenticatorInterface;
use Quantum\Auth\Contracts\AuthenticatorResolverInterface;

final class DefaultAuthenticatorResolver implements AuthenticatorResolverInterface
{
    public function __construct(
        private readonly SessionAuthenticator $sessionAuthenticator,
        private readonly PasswordAuthenticator $passwordAuthenticator,
        private readonly ?BearerAuthenticator $bearerAuthenticator = null,
    ) {
    }

    public function resolve(AuthenticationOperationContext $context): array
    {
        $candidates = match ($context->operation) {
            'recover' => $this->withBearer([$this->sessionAuthenticator]),
            'authenticate', 'step_up' => $this->withBearer([$this->passwordAuthenticator]),
            default => $this->withBearer([]),
        };

        return array_values(array_filter(
            $candidates,
            static fn (AuthenticatorInterface $authenticator): bool => $authenticator->supports($context),
        ));
    }

    /**
     * @param array<int, AuthenticatorInterface> $candidates
     *
     * @return array<int, AuthenticatorInterface>
     */
    private function withBearer(array $candidates): array
    {
        if ($this->bearerAuthenticator !== null) {
            array_unshift($candidates, $this->bearerAuthenticator);
        }

        return $candidates;
    }
}

