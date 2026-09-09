<?php

declare(strict_types=1);

namespace Quantum\Auth\Sessions;

use Quantum\Auth\Contracts\AuthenticationSessionRepositoryInterface;
use Quantum\Auth\Identity\GenericIdentity;
use Quantum\Auth\Identity\IdentityIdentifier;
use Quantum\Auth\Identity\IdentityInterface;
use Quantum\Auth\Identity\IdentityReference;
use RuntimeException;

final class FileAuthenticationSessionRepository implements AuthenticationSessionRepositoryInterface
{
    public function __construct(
        private readonly string $directory,
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
                $this->writeRecoveryReason($sessionId, AuthenticationSessionRecoveryReason::Expired);
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

    private function writeRecoveryReason(string $sessionId, AuthenticationSessionRecoveryReason $reason): void
    {
        $this->ensureDirectory();

        file_put_contents($this->recoveryReasonPathFor($sessionId), json_encode([
            'session_id' => $sessionId,
            'reason' => $reason->value,
            'recorded_at' => time(),
        ], JSON_THROW_ON_ERROR), LOCK_EX);
    }

    private function deleteRecoveryReason(string $sessionId): void
    {
        $path = $this->recoveryReasonPathFor($sessionId);

        if (is_file($path)) {
            @unlink($path);
        }
    }
}
