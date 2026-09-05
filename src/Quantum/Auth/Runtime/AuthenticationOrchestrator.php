<?php

declare(strict_types=1);

namespace Quantum\Auth\Runtime;

use Quantum\Auth\Contracts\AuthenticatorInterface;
use Quantum\Auth\Contracts\AuthenticationOrchestratorInterface;
use Quantum\Auth\Decisions\AuthenticationDecision;

final class AuthenticationOrchestrator implements AuthenticationOrchestratorInterface
{
    public function __construct(
        private readonly AuthenticatorInterface $passwordAuthenticator,
    ) {
    }

    public function execute(AuthenticationOperationContext $context): AuthenticationDecision
    {
        if ($context->operation === 'authenticate' && $this->passwordAuthenticator->supports($context)) {
            return $this->passwordAuthenticator->authenticate($context);
        }

        if ($context->operation === 'recover' && $context->currentContext !== null) {
            return AuthenticationDecision::authenticated($context->currentContext, [
                'operation' => $context->operation,
                'source' => 'current_context',
            ]);
        }

        return AuthenticationDecision::unauthenticated([
            'operation' => $context->operation,
            'source' => 'orchestrator',
        ]);
    }
}
