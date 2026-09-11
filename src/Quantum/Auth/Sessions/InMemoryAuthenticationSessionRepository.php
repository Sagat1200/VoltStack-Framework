<?php

declare(strict_types=1);

namespace Quantum\Auth\Sessions;

use Quantum\Auth\Contracts\AuthenticationSessionRepositoryInterface;
use Quantum\Auth\Identity\IdentityInterface;

final class InMemoryAuthenticationSessionRepository implements AuthenticationSessionRepositoryInterface
{
    public function __construct(
        private readonly int $recoveryRetentionSeconds = 604800,
    ) {}

    /**
     * @var array<string, AuthenticationSession>
     */
    private array $sessions = [];

    /**
     * @var array<string, array{reason: AuthenticationSessionRecoveryReason, recorded_at: int}>
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

    public function all(): array
    {
        return array_values($this->sessions);
    }

    public function delete(
        string $sessionId,
        AuthenticationSessionRecoveryReason $reason = AuthenticationSessionRecoveryReason::Revoked,
    ): void {
        unset($this->sessions[$sessionId]);
        $this->rememberRecoveryReason($sessionId, $reason);
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
            $this->rememberRecoveryReason($sessionId, $reason);
        }
    }

    public function findRecoveryReason(string $sessionId): ?AuthenticationSessionRecoveryReason
    {
        return $this->recoveryReasons[$sessionId]['reason'] ?? null;
    }

    public function touch(AuthenticationSession $session): void
    {
        $this->sessions[(string) $session->id] = $session;
    }

    public function purgeExpired(?int $now = null): int
    {
        $deleted = 0;

        foreach ($this->sessions as $sessionId => $session) {
            if (! $session->isExpired($now)) {
                continue;
            }

            unset($this->sessions[$sessionId]);
            $this->rememberRecoveryReason($sessionId, AuthenticationSessionRecoveryReason::Expired, $now ?? time());
            $deleted++;
        }

        return $deleted;
    }

    public function purgeRecoveryReasons(?int $now = null): int
    {
        if ($this->recoveryReasons === []) {
            return 0;
        }

        $deleted = 0;
        $instant = $now ?? time();
        $threshold = $this->recoveryRetentionSeconds <= 0
            ? $instant
            : $instant - $this->recoveryRetentionSeconds;

        foreach ($this->recoveryReasons as $sessionId => $record) {
            if (($record['recorded_at'] ?? 0) > $threshold) {
                continue;
            }

            unset($this->recoveryReasons[$sessionId]);
            $deleted++;
        }

        return $deleted;
    }

    private function rememberRecoveryReason(
        string $sessionId,
        AuthenticationSessionRecoveryReason $reason,
        ?int $recordedAt = null,
    ): void {
        $this->recoveryReasons[$sessionId] = [
            'reason' => $reason,
            'recorded_at' => $recordedAt ?? time(),
        ];
    }
}
