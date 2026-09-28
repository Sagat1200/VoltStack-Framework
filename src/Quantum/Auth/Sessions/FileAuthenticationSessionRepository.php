<?php

declare(strict_types=1);

namespace Quantum\Auth\Sessions;

use Quantum\Auth\Contracts\AuthenticationSessionRepositoryInterface;
use Quantum\Auth\Contracts\BulkDeletableSessionRepositoryInterface;
use Quantum\Auth\Contracts\FilterableAuthenticationSessionRepositoryInterface;
use Quantum\Auth\Identity\GenericIdentity;
use Quantum\Auth\Identity\IdentityIdentifier;
use Quantum\Auth\Identity\IdentityInterface;
use Quantum\Auth\Identity\IdentityReference;
use RuntimeException;

final class FileAuthenticationSessionRepository implements AuthenticationSessionRepositoryInterface, FilterableAuthenticationSessionRepositoryInterface, BulkDeletableSessionRepositoryInterface
{
    public function __construct(
        private readonly string $directory,
        private readonly int $recoveryRetentionSeconds = 604800,
    ) {}

    public function save(AuthenticationSession $session): void
    {
        $this->ensureDirectory();

        $payload = json_encode([
            'id' => $session->id->value,
            'identity' => [
                'identifier' => (string) $session->identity->identifier(),
                'type' => $session->identity->type(),
                'attributes' => $session->identity instanceof GenericIdentity ? $session->identity->attributes : [],
            ],
            'reference' => [
                'identifier' => $session->reference->identifier->value,
                'type' => $session->reference->type,
            ],
            'method' => $session->method,
            'issued_at' => $session->issuedAt,
            'expires_at' => $session->expiresAt,
            'attributes' => $session->attributes,
        ], JSON_THROW_ON_ERROR);

        file_put_contents($this->pathFor($session->id->value), $payload, LOCK_EX);
        $this->deleteRecoveryReason($session->id->value);
    }

    public function find(string $sessionId): ?AuthenticationSession
    {
        $path = $this->pathFor($sessionId);

        if (! is_file($path)) {
            return null;
        }

        $payload = file_get_contents($path);

        if (! is_string($payload) || trim($payload) === '') {
            return null;
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $identityData = is_array($data['identity'] ?? null) ? $data['identity'] : [];
        $referenceData = is_array($data['reference'] ?? null) ? $data['reference'] : [];

        $identity = new GenericIdentity(
            identifier: new IdentityIdentifier((string) ($identityData['identifier'] ?? '')),
            type: (string) ($identityData['type'] ?? 'user'),
            attributes: is_array($identityData['attributes'] ?? null) ? $identityData['attributes'] : [],
        );

        return new AuthenticationSession(
            id: new AuthenticationSessionId((string) ($data['id'] ?? $sessionId)),
            identity: $identity,
            reference: new IdentityReference(
                new IdentityIdentifier((string) ($referenceData['identifier'] ?? (string) $identity->identifier())),
                (string) ($referenceData['type'] ?? $identity->type()),
            ),
            method: (string) ($data['method'] ?? 'password'),
            issuedAt: (int) ($data['issued_at'] ?? time()),
            expiresAt: isset($data['expires_at']) ? (int) $data['expires_at'] : null,
            attributes: is_array($data['attributes'] ?? null) ? $data['attributes'] : [],
        );
    }

    public function listForIdentity(IdentityInterface $identity): array
    {
        $sessions = [];

        foreach ($this->sessionFiles() as $file) {
            $payload = file_get_contents($file);

            if (! is_string($payload) || trim($payload) === '') {
                continue;
            }

            /** @var array<string, mixed> $data */
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $identityData = is_array($data['identity'] ?? null) ? $data['identity'] : [];

            if ((string) ($identityData['identifier'] ?? '') !== (string) $identity->identifier()) {
                continue;
            }

            $sessionId = (string) ($data['id'] ?? '');
            $session = $sessionId !== '' ? $this->find($sessionId) : null;

            if ($session !== null) {
                $sessions[] = $session;
            }
        }

        return $sessions;
    }

    public function all(): array
    {
        $sessions = [];

        foreach ($this->sessionFiles() as $file) {
            $payload = file_get_contents($file);

            if (! is_string($payload) || trim($payload) === '') {
                continue;
            }

            /** @var array<string, mixed> $data */
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $sessionId = (string) ($data['id'] ?? '');
            $session = $sessionId !== '' ? $this->find($sessionId) : null;

            if ($session !== null) {
                $sessions[] = $session;
            }
        }

        return $sessions;
    }

    public function delete(
        string $sessionId,
        AuthenticationSessionRecoveryReason $reason = AuthenticationSessionRecoveryReason::Revoked,
    ): void {
        $path = $this->pathFor($sessionId);

        if (is_file($path)) {
            @unlink($path);
        }

        $this->writeRecoveryReason($sessionId, $reason);
    }

    public function deleteForIdentity(
        IdentityInterface $identity,
        ?string $exceptSessionId = null,
        AuthenticationSessionRecoveryReason $reason = AuthenticationSessionRecoveryReason::Revoked,
    ): void {
        foreach ($this->sessionFiles() as $file) {
            $payload = file_get_contents($file);

            if (! is_string($payload) || trim($payload) === '') {
                continue;
            }

            /** @var array<string, mixed> $data */
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $sessionId = (string) ($data['id'] ?? '');
            $identityData = is_array($data['identity'] ?? null) ? $data['identity'] : [];

            if ((string) ($identityData['identifier'] ?? '') !== (string) $identity->identifier()) {
                continue;
            }

            if ($exceptSessionId !== null && $sessionId === $exceptSessionId) {
                continue;
            }

            @unlink($file);
            $this->writeRecoveryReason($sessionId, $reason);
        }
    }

    public function findRecoveryReason(string $sessionId): ?AuthenticationSessionRecoveryReason
    {
        $path = $this->recoveryReasonPathFor($sessionId);

        if (! is_file($path)) {
            return null;
        }

        $payload = file_get_contents($path);

        if (! is_string($payload) || trim($payload) === '') {
            return null;
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $reason = $data['reason'] ?? null;

        return is_string($reason)
            ? AuthenticationSessionRecoveryReason::tryFrom($reason)
            : null;
    }

    public function touch(AuthenticationSession $session): void
    {
        $this->save($session);
    }

    public function purgeExpired(?int $now = null): int
    {
        $deleted = 0;
        $instant = $now ?? time();

        foreach ($this->sessionFiles() as $file) {
            $payload = file_get_contents($file);

            if (! is_string($payload) || trim($payload) === '') {
                continue;
            }

            /** @var array<string, mixed> $data */
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $expiresAt = $data['expires_at'] ?? null;

            if ($expiresAt === null || (int) $expiresAt > $instant) {
                continue;
            }

            $sessionId = (string) ($data['id'] ?? '');

            @unlink($file);
            if ($sessionId !== '') {
                $this->writeRecoveryReason($sessionId, AuthenticationSessionRecoveryReason::Expired, $instant);
            }
            $deleted++;
        }

        return $deleted;
    }

    public function purgeRecoveryReasons(?int $now = null): int
    {
        $this->ensureRecoveryDirectory();

        $deleted = 0;
        $instant = $now ?? time();
        $threshold = $this->recoveryRetentionSeconds <= 0
            ? $instant
            : $instant - $this->recoveryRetentionSeconds;

        foreach ($this->recoveryFiles() as $file) {
            $payload = file_get_contents($file);

            if (! is_string($payload) || trim($payload) === '') {
                if (@unlink($file) || ! is_file($file)) {
                    $deleted++;
                }

                continue;
            }

            /** @var array<string, mixed> $data */
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $recordedAt = $data['recorded_at'] ?? null;
            $recordedAt = is_numeric($recordedAt) ? (int) $recordedAt : 0;

            if ($recordedAt > $threshold) {
                continue;
            }

            if (! @unlink($file) && is_file($file)) {
                throw new RuntimeException(sprintf('Unable to remove auth recovery tombstone [%s].', $file));
            }

            $deleted++;
        }

        return $deleted;
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->directory)) {
            $this->ensureRecoveryDirectory();
            return;
        }

        if (! @mkdir($this->directory, 0777, true) && ! is_dir($this->directory)) {
            throw new RuntimeException(sprintf('Unable to create auth session directory [%s].', $this->directory));
        }

        $this->ensureRecoveryDirectory();
    }

    private function pathFor(string $sessionId): string
    {
        return rtrim($this->directory, '\\/') . DIRECTORY_SEPARATOR . $sessionId . '.json';
    }

    private function recoveryReasonPathFor(string $sessionId): string
    {
        return $this->recoveryDirectory() . DIRECTORY_SEPARATOR . $sessionId . '.json';
    }

    /**
     * @return list<string>
     */
    private function sessionFiles(): array
    {
        $this->ensureDirectory();

        $files = glob(rtrim($this->directory, '\\/') . DIRECTORY_SEPARATOR . '*.json');

        if ($files === false) {
            return [];
        }

        return array_values(array_filter($files, static fn(mixed $file): bool => is_string($file)));
    }

    private function recoveryDirectory(): string
    {
        return rtrim($this->directory, '\\/') . DIRECTORY_SEPARATOR . 'tombstones';
    }

    private function ensureRecoveryDirectory(): void
    {
        $recoveryDirectory = $this->recoveryDirectory();

        if (is_dir($recoveryDirectory)) {
            return;
        }

        if (! @mkdir($recoveryDirectory, 0777, true) && ! is_dir($recoveryDirectory)) {
            throw new RuntimeException(sprintf('Unable to create auth recovery directory [%s].', $recoveryDirectory));
        }
    }

    /**
     * @return list<string>
     */
    private function recoveryFiles(): array
    {
        $this->ensureRecoveryDirectory();

        $files = glob($this->recoveryDirectory() . DIRECTORY_SEPARATOR . '*.json');

        if ($files === false) {
            return [];
        }

        return array_values(array_filter($files, static fn(mixed $file): bool => is_string($file)));
    }

    private function writeRecoveryReason(
        string $sessionId,
        AuthenticationSessionRecoveryReason $reason,
        ?int $recordedAt = null,
    ): void
    {
        $this->ensureDirectory();

        file_put_contents($this->recoveryReasonPathFor($sessionId), json_encode([
            'session_id' => $sessionId,
            'reason' => $reason->value,
            'recorded_at' => $recordedAt ?? time(),
        ], JSON_THROW_ON_ERROR), LOCK_EX);
    }

    private function deleteRecoveryReason(string $sessionId): void
    {
        $path = $this->recoveryReasonPathFor($sessionId);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function findByCriteria(array $criteria): array
    {
        $limit = isset($criteria['limit']) && is_int($criteria['limit']) && $criteria['limit'] > 0 ? $criteria['limit'] : null;
        $offset = isset($criteria['offset']) && is_int($criteria['offset']) && $criteria['offset'] > 0 ? $criteria['offset'] : 0;

        $all = $this->all();
        $filtered = [];
        $skipped = 0;
        $taken = 0;

        foreach ($all as $session) {
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
        foreach ($this->all() as $session) {
            if ($this->sessionMatches($session, $criteria)) $count++;
        }
        return $count;
    }

    public function deleteByIds(array $sessionIds): int
    {
        $ids = array_values(array_filter($sessionIds, static fn (mixed $v): bool => is_string($v) && trim((string)$v) !== ''));
        if ($ids === []) return 0;

        $deleted = 0;
        foreach ($ids as $sessionId) {
            $path = $this->pathFor($sessionId);
            if (is_file($path)) {
                @unlink($path);
                $this->writeRecoveryReason($sessionId, AuthenticationSessionRecoveryReason::Revoked);
                $deleted++;
            }
        }
        return $deleted;
    }

    public function deleteByCriteria(array $criteria): int
    {
        $toDelete = [];
        foreach ($this->all() as $session) {
            if ($this->sessionMatches($session, $criteria)) $toDelete[] = (string)$session->id;
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
