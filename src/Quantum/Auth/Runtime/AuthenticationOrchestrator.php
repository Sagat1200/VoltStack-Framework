<?php

declare(strict_types=1);

namespace Quantum\Auth\Runtime;

use Quantum\Auth\Contracts\AuthenticationOrchestratorInterface;
use Quantum\Auth\Contracts\AuthenticatorResolverInterface;
use Quantum\Auth\Decisions\AuthenticationDecision;

final class AuthenticationOrchestrator implements AuthenticationOrchestratorInterface
{
    public function __construct(
        private readonly AuthenticatorResolverInterface $resolver,
    ) {}

    public function execute(AuthenticationOperationContext $context): AuthenticationDecision
    {
        foreach ($this->resolver->resolve($context) as $authenticator) {
            return $authenticator->authenticate($context);
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