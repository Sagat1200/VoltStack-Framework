<?php

declare(strict_types=1);

namespace Quantum\Auth\Authenticators;

use Quantum\Auth\Context\AuthenticationContext;
use Quantum\Auth\Contracts\AuthenticationSessionRepositoryInterface;
use Quantum\Auth\Contracts\AuthenticatorInterface;
use Quantum\Auth\Decisions\AuthenticationDecision;
use Quantum\Auth\Runtime\AuthenticationOperationContext;
use Quantum\Auth\Sessions\AuthenticationSessionRecoveryReason;
use Quantum\Auth\Support\AuthenticationAssurance;

final class SessionAuthenticator implements AuthenticatorInterface
{
    public function __construct(
        private readonly AuthenticationSessionRepositoryInterface $sessions,
    ) {}

    public function supports(AuthenticationOperationContext $context): bool
    {
        return $context->operation === 'recover'
            && is_string($context->request->attribute('session_id', null))
            && trim((string) $context->request->attribute('session_id', null)) !== '';
    }

    public function authenticate(AuthenticationOperationContext $context): AuthenticationDecision
    {
        $this->sessions->purgeExpired();

        $sessionId = trim((string) $context->request->attribute('session_id', ''));

        if ($sessionId === '') {
            return AuthenticationDecision::unauthenticated([
                'reason' => 'missing_session_id',
                'authenticator' => 'session',
            ]);
        }

        $session = $this->sessions->find($sessionId);

        if ($session === null) {
            $reason = $this->sessions->findRecoveryReason($sessionId);

            return AuthenticationDecision::unauthenticated([
                'reason' => $reason?->value ?? 'session_not_found',
                'authenticator' => 'session',
            ]);
        }

        if ($session->isExpired()) {
            $this->sessions->delete($sessionId, AuthenticationSessionRecoveryReason::Expired);

            return AuthenticationDecision::unauthenticated([
                'reason' => AuthenticationSessionRecoveryReason::Expired->value,
                'authenticator' => 'session',
            ]);
        }

        return AuthenticationDecision::authenticated(
            new AuthenticationContext(
                identity: $session->identity,
                reference: $session->reference,
                requestId: $context->request->requestId,
                method: $session->method,
                attributes: AuthenticationAssurance::enrichAttributes(
                    array_merge($session->attributes, ['session_id' => $sessionId]),
                    $session->method,
                ),
            ),
            [
                'authenticator' => 'session',
                'session_id' => $sessionId,
            ],
        );
    }
}
