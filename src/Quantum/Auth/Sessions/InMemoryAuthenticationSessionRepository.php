<?php

declare(strict_types=1);

namespace Quantum\Auth\Sessions;

use Quantum\Auth\Contracts\AuthenticationSessionRepositoryInterface;

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
}
