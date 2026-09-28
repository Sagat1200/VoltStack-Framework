<?php

declare(strict_types=1);

namespace Quantum\Auth\Runtime;

use Quantum\Auth\Identity\IdentityInterface;

final class SessionCountLimitRule implements AuthenticationPolicyRuleInterface
{
    /**
     * @param int|null $maximumConcurrentSessions
     * @param array<int, string> $operations
     */
    public function __construct(
        private readonly ?int $maximumConcurrentSessions = null,
        private readonly array $operations = ['issue_session', 'managed_devices', 'revoke_managed_device'],
    ) {}

    public function applies(string $operation, ?IdentityInterface $identity = null, array $context = []): bool
    {
        if ($this->maximumConcurrentSessions === null || $this->maximumConcurrentSessions <= 0) {
            return false;
        }

        return in_array($operation, $this->operations, true);
    }

    public function evaluate(string $operation, ?IdentityInterface $identity = null, array $context = []): PolicyDecision
    {
        if (! $this->applies($operation, $identity, $context)) {
            return PolicyDecision::allow(['session_limit_rule_not_applicable']);
        }

        $currentCount = isset($context['current_session_count']) && is_int($context['current_session_count'])
            ? $context['current_session_count']
            : 0;

        $denyWhen = match (true) {
            $operation === 'issue_session' => $currentCount >= $this->maximumConcurrentSessions,
            default => false,
        };

        if ($denyWhen) {
            return PolicyDecision::deny([
                'auth.policy.concurrent_session_limit_exceeded',
            ], [
                'current_session_count' => $currentCount,
                'maximum_concurrent_sessions' => $this->maximumConcurrentSessions,
            ]);
        }

        return PolicyDecision::allow(['auth.policy.concurrent_sessions_within_limit'], [
            'current_session_count' => $currentCount,
            'maximum_concurrent_sessions' => $this->maximumConcurrentSessions,
        ]);
    }
}
