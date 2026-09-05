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

    public function delete(string $sessionId): void
    {
        $path = $this->pathFor($sessionId);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function deleteForIdentity(IdentityInterface $identity, ?string $exceptSessionId = null): void
    {
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
        }
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

            @unlink($file);
            $deleted++;
        }

        return $deleted;
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->directory)) {
            return;
        }

        if (! @mkdir($this->directory, 0777, true) && ! is_dir($this->directory)) {
            throw new RuntimeException(sprintf('Unable to create auth session directory [%s].', $this->directory));
        }
    }

    private function pathFor(string $sessionId): string
    {
        return rtrim($this->directory, '\\/') . DIRECTORY_SEPARATOR . $sessionId . '.json';
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

        return array_values(array_filter($files, static fn (mixed $file): bool => is_string($file)));
    }
}
