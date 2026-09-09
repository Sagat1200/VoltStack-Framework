<?php

declare(strict_types=1);

namespace Quantum\Auth\Sessions;

use Quantum\Auth\Contracts\AuthenticationSessionRepositoryInterface;
use Quantum\Auth\Identity\IdentityInterface;

final class InMemoryAuthenticationSessionRepository implements AuthenticationSessionRepositoryInterface
{
    /**
     * @var array<string, AuthenticationSession>
     */
    private array $sessions = [];

    /**
     * @var array<string, AuthenticationSessionRecoveryReason>
     */
    private array $recoveryReasons = [];

    public function save(AuthenticationSession $session): void
    {
        $this->sessions[(string) $session->id] = $session;
        unset($this->recoveryReasons[(string) $session->id]);
    }

    public function find(string $sessionId): ?AuthenticationSession
    {
        return $this->sessions[$sessionId] ?? null;
    }

    public function listForIdentity(IdentityInterface $identity): array
    {
        return array_values(array_filter(
            $this->sessions,
            static fn(AuthenticationSession $session): bool => (string) $session->identity->identifier() === (string) $identity->identifier(),
        ));
    }

    public function delete(
        string $sessionId,
        AuthenticationSessionRecoveryReason $reason = AuthenticationSessionRecoveryReason::Revoked,
    ): void {
        unset($this->sessions[$sessionId]);
        $this->recoveryReasons[$sessionId] = $reason;
    }

    public function deleteForIdentity(
        IdentityInterface $identity,
        ?string $exceptSessionId = null,
        AuthenticationSessionRecoveryReason $reason = AuthenticationSessionRecoveryReason::Revoked,
    ): void {
        foreach ($this->sessions as $sessionId => $session) {
            if ((string) $session->identity->identifier() !== (string) $identity->identifier()) {
                continue;
            }

            if ($exceptSessionId !== null && $sessionId === $exceptSessionId) {
                continue;
            }

            unset($this->sessions[$sessionId]);
            $this->recoveryReasons[$sessionId] = $reason;
        }
    }

    public function findRecoveryReason(string $sessionId): ?AuthenticationSessionRecoveryReason
    {
        return $this->recoveryReasons[$sessionId] ?? null;
    }

    public function purgeExpired(?int $now = null): int
    {
        $deleted = 0;

        foreach ($this->sessions as $sessionId => $session) {
            if (! $session->isExpired($now)) {
                continue;
            }

            unset($this->sessions[$sessionId]);
            $this->recoveryReasons[$sessionId] = AuthenticationSessionRecoveryReason::Expired;
            $deleted++;
        }

        return $deleted;
    }
}
