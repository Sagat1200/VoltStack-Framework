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

    public function save(AuthenticationSession $session): void
    {
        $this->sessions[(string) $session->id] = $session;
    }

    public function find(string $sessionId): ?AuthenticationSession
    {
        return $this->sessions[$sessionId] ?? null;
    }

    public function delete(string $sessionId): void
    {
        unset($this->sessions[$sessionId]);
    }

    public function deleteForIdentity(IdentityInterface $identity, ?string $exceptSessionId = null): void
    {
        foreach ($this->sessions as $sessionId => $session) {
            if ((string) $session->identity->identifier() !== (string) $identity->identifier()) {
                continue;
            }

            if ($exceptSessionId !== null && $sessionId === $exceptSessionId) {
                continue;
            }

            unset($this->sessions[$sessionId]);
        }
    }

    public function purgeExpired(?int $now = null): int
    {
        $deleted = 0;

        foreach ($this->sessions as $sessionId => $session) {
            if (! $session->isExpired($now)) {
                continue;
            }

            unset($this->sessions[$sessionId]);
            $deleted++;
        }

        return $deleted;
    }
}
