<?php

declare(strict_types=1);

namespace Quantum\Auth\Sessions;

use Quantum\Auth\Contracts\AuthenticationSessionRepositoryInterface;
use Quantum\Auth\Contracts\BulkDeletableSessionRepositoryInterface;
use Quantum\Auth\Contracts\FilterableAuthenticationSessionRepositoryInterface;
use Quantum\Auth\Identity\IdentityInterface;

final class InMemoryAuthenticationSessionRepository implements AuthenticationSessionRepositoryInterface, FilterableAuthenticationSessionRepositoryInterface, BulkDeletableSessionRepositoryInterface
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

    public function findByCriteria(array $criteria): array
    {
        $limit = isset($criteria['limit']) && is_int($criteria['limit']) && $criteria['limit'] > 0 ? $criteria['limit'] : null;
        $offset = isset($criteria['offset']) && is_int($criteria['offset']) && $criteria['offset'] > 0 ? $criteria['offset'] : 0;

        $filtered = [];
        $skipped = 0;
        $taken = 0;

        foreach ($this->sessions as $session) {
            if (! $this->sessionMatches($session, $criteria)) continue;
            if ($skipped < $offset) { $skipped++; continue; }
            $filtered[] = $session;
            $taken++;
            if ($limit !== null && $taken >= $limit) break;
        }

        return $filtered;
    }

    public function countByCriteria(array $criteria): int
    {
        $count = 0;
        foreach ($this->sessions as $session) {
            if ($this->sessionMatches($session, $criteria)) $count++;
        }
        return $count;
    }

    public function deleteByIds(array $sessionIds): int
    {
        $ids = array_values(array_filter($sessionIds, static fn (mixed $v): bool => is_string($v) && trim((string)$v) !== ''));
        if ($ids === []) return 0;

        $lookup = array_fill_keys($ids, true);
        $deleted = 0;
        foreach ($lookup as $sessionId => $_) {
            if (isset($this->sessions[$sessionId])) {
                unset($this->sessions[$sessionId]);
                $this->rememberRecoveryReason($sessionId, AuthenticationSessionRecoveryReason::Revoked);
                $deleted++;
            }
        }
        return $deleted;
    }

    public function deleteByCriteria(array $criteria): int
    {
        $toDelete = [];
        foreach ($this->sessions as $sessionId => $session) {
            if ($this->sessionMatches($session, $criteria)) $toDelete[] = (string)$sessionId;
        }
        return $this->deleteByIds($toDelete);
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private function sessionMatches(AuthenticationSession $session, array $criteria): bool
    {
        $identityId = (string)$session->identity->identifier();
        $attrs = is_array($session->attributes) ? $session->attributes : [];
        $status = is_string($attrs['status'] ?? null) ? $attrs['status'] : null;
        $sessionType = is_string($attrs['session_type'] ?? null) ? $attrs['session_type'] : null;
        $deviceFingerprint = is_string($attrs['device_fingerprint'] ?? null) ? $attrs['device_fingerprint'] : null;
        $trustedDeviceId = is_string($attrs['trusted_device_id'] ?? null) ? $attrs['trusted_device_id'] : null;

        if (isset($criteria['identity_id'])) {
            $ids = is_array($criteria['identity_id']) ? $criteria['identity_id'] : [$criteria['identity_id']];
            $mapped = array_values(array_filter($ids, static fn (mixed $v): bool => is_string($v) || is_numeric($v)));
            if (! in_array($identityId, array_map(static fn (mixed $v): string => (string)$v, $mapped), true)) return false;
        }

        if (isset($criteria['status']) && $status !== null) {
            $values = is_array($criteria['status']) ? $criteria['status'] : [$criteria['status']];
            if (! in_array($status, array_values(array_filter($values, static fn (mixed $v): bool => is_string($v))), true)) return false;
        }

        if (isset($criteria['session_type']) && $sessionType !== null) {
            $values = is_array($criteria['session_type']) ? $criteria['session_type'] : [$criteria['session_type']];
            if (! in_array($sessionType, array_values(array_filter($values, static fn (mixed $v): bool => is_string($v))), true)) return false;
        }

        if (isset($criteria['device_fingerprint']) && is_string($criteria['device_fingerprint']) && $criteria['device_fingerprint'] !== '') {
            if ($deviceFingerprint === null || $deviceFingerprint !== $criteria['device_fingerprint']) return false;
        }

        if (isset($criteria['trusted_device_id'])) {
            $ids = is_array($criteria['trusted_device_id']) ? $criteria['trusted_device_id'] : [$criteria['trusted_device_id']];
            $idsFiltered = array_values(array_filter($ids, static fn (mixed $v): bool => is_string($v) && trim((string)$v) !== ''));
            if ($trustedDeviceId === null || ! in_array($trustedDeviceId, $idsFiltered, true)) return false;
        }

        if (isset($criteria['authentication_method'])) {
            $methods = is_array($criteria['authentication_method']) ? $criteria['authentication_method'] : [$criteria['authentication_method']];
            $methodsFiltered = array_values(array_filter($methods, static fn (mixed $v): bool => is_string($v)));
            if (! in_array($session->method, $methodsFiltered, true)) return false;
        }

        if (isset($criteria['expires_before']) && is_int($criteria['expires_before'])) {
            if ($session->expiresAt === null || $session->expiresAt > $criteria['expires_before']) return false;
        }

        if (isset($criteria['expires_after']) && is_int($criteria['expires_after'])) {
            if ($session->expiresAt === null || $session->expiresAt <= $criteria['expires_after']) return false;
        }

        if (isset($criteria['issued_before']) && is_int($criteria['issued_before'])) {
            if ($session->issuedAt > $criteria['issued_before']) return false;
        }

        if (isset($criteria['issued_after']) && is_int($criteria['issued_after'])) {
            if ($session->issuedAt <= $criteria['issued_after']) return false;
        }

        return true;
    }
}
