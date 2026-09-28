<?php

declare(strict_types=1);

namespace Quantum\Auth\Runtime;

use Quantum\Auth\Contracts\MutableIdentityProviderInterface;
use Quantum\Auth\Identity\IdentityInterface;
use Quantum\Auth\Identity\IdentitySecurityState;

final class IdentitySecurityStateRule implements AuthenticationPolicyRuleInterface
{
    /**
     * @param array<int, string> $allowedStates
     * @param array<int, string> $operations
     */
    public function __construct(
        private readonly array $allowedStates = [
            IdentitySecurityState::Active->value,
        ],
        private readonly ?MutableIdentityProviderInterface $identityProvider = null,
        private readonly array $operations = ['managed_devices', 'revoke_managed_device', 'issue_session', 'authenticate'],
    ) {}

    public function applies(string $operation, ?IdentityInterface $identity = null, array $context = []): bool
    {
        if ($identity === null) {
            return false;
        }

        return in_array($operation, $this->operations, true);
    }

    public function evaluate(string $operation, ?IdentityInterface $identity = null, array $context = []): PolicyDecision
    {
        if (! $this->applies($operation, $identity, $context)) {
            return PolicyDecision::allow(['security_state_rule_not_applicable']);
        }

        $state = null;

        if (isset($context['security_state']) && is_string($context['security_state']) && trim($context['security_state']) !== '') {
            $state = trim($context['security_state']);
        } elseif ($this->identityProvider instanceof MutableIdentityProviderInterface) {
            $state = $this->identityProvider->securityStateFor($identity)->value;
        } else {
            $state = IdentitySecurityState::Active->value;
        }

        if (! in_array($state, $this->allowedStates, true)) {
            return PolicyDecision::deny([
                'auth.policy.identity_security_state_not_eligible',
            ], [
                'security_state' => $state,
                'allowed_states' => $this->allowedStates,
            ]);
        }

        return PolicyDecision::allow(['auth.policy.identity_security_state_eligible'], [
            'security_state' => $state,
            'allowed_states' => $this->allowedStates,
        ]);
    }
}
