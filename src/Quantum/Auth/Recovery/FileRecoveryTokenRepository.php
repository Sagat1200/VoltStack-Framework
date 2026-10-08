<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

use Quantum\Auth\Contracts\RecoveryTokenRepositoryInterface;
use RuntimeException;

final class FileRecoveryTokenRepository implements RecoveryTokenRepositoryInterface
{
    public function __construct(
        private readonly string $storageDirectory,
    ) {
        $directory = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, trim($this->storageDirectory)), DIRECTORY_SEPARATOR);

        if ($directory === '') {
            throw new RuntimeException('FileRecoveryTokenRepository storage directory cannot be empty.');
        }

        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf(
                'Unable to create recovery token storage directory [%s].',
                $directory,
            ));
        }
    }

    public function save(PasswordResetTokenRecord $token): void
    {
        $payload = json_encode($token->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $written = @file_put_contents($this->pathFor($token->id), $payload . PHP_EOL);

        if ($written === false) {
            throw new RuntimeException(sprintf(
                'Unable to persist recovery token [%s].',
                $token->id,
            ));
        }
    }

    public function find(string $tokenId): ?PasswordResetTokenRecord
    {
        $path = $this->pathFor($tokenId);

        if (! is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($data) ? PasswordResetTokenRecord::fromArray($data) : null;
    }

    public function markConsumed(string $tokenId, ?int $consumedAt = null): bool
    {
        $existing = $this->find($tokenId);

        if ($existing === null || $existing->isConsumed()) {
            return false;
        }

        $this->save(new PasswordResetTokenRecord(
            id: $existing->id,
            secretHash: $existing->secretHash,
            reference: $existing->reference,
            identifier: $existing->identifier,
            issuedAt: $existing->issuedAt,
            expiresAt: $existing->expiresAt,
            consumedAt: ($consumedAt ?? time()) > 0 ? ($consumedAt ?? time()) : time(),
            attributes: $existing->attributes,
        ));

        return true;
    }

    public function deleteExpired(?int $now = null): int
    {
        $now ??= time();
        $deleted = 0;

        $files = glob($this->directory() . DIRECTORY_SEPARATOR . '*.json');
        if (! is_array($files)) {
            return 0;
        }

        foreach ($files as $file) {
            if (! is_string($file)) {
                continue;
            }

            $token = $this->find(pathinfo($file, PATHINFO_FILENAME));
            if ($token === null || ! $token->isExpired($now)) {
                continue;
            }

            if (@unlink($file) !== false) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function pathFor(string $tokenId): string
    {
        return $this->directory() . DIRECTORY_SEPARATOR . $tokenId . '.json';
    }

    private function directory(): string
    {
        return rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, trim($this->storageDirectory)), DIRECTORY_SEPARATOR);
    }
}
