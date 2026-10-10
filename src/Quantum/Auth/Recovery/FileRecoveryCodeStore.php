<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

use Quantum\Auth\Contracts\RecoveryCodeStoreInterface;
use Quantum\Auth\Identity\IdentityReference;

/**
 * Implementación durable basada en un archivo JSONL.
 *
 * Estructura de línea (una por recovery code):
 *   {
 *     "code_hash": "...",
 *     "identity_identifier": "...",
 *     "identity_type": "user",
 *     "issued_at": 0,
 *     "expires_at": null|int,
 *     "consumed_at": null|int,
 *     "metadata": {}
 *   }
 *
 * Estrategia de mutación (consume y rotate): reescribe el archivo completo por
 * simplicidad. Mantiene lock exclusivo en toda la transacción de escritura.
 */
class FileRecoveryCodeStore implements RecoveryCodeStoreInterface
{
    public function __construct(
        private readonly string $storagePath,
    ) {
        $directory = dirname($this->storagePath);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        if (! file_exists($this->storagePath)) {
            file_put_contents($this->storagePath, '', FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * @return list<array{
     *     code_hash: string,
     *     identity_identifier: string,
     *     identity_type: string,
     *     issued_at: int,
     *     expires_at: ?int,
     *     consumed_at: ?int,
     *     metadata: array,
     * }>
     */
    private function readAll(): array
    {
        $content = file_get_contents($this->storagePath);
        if ($content === false || trim($content) === '') {
            return [];
        }
        $rows = [];
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($row) && isset($row['code_hash'], $row['identity_identifier'])) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param list<array> $rows
     */
    private function writeAll(array $rows): void
    {
        $lines = '';
        foreach ($rows as $row) {
            $lines .= json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        }
        file_put_contents($this->storagePath, $lines, LOCK_EX);
    }

    private function bucket(IdentityReference $identity): string
    {
        return sprintf('%s|%s', $identity->type, (string) $identity->identifier);
    }

    private function bucketOfRow(array $row): string
    {
        return sprintf('%s|%s', $row['identity_type'] ?? 'user', $row['identity_identifier'] ?? '');
    }

    public function attachBatch(IdentityReference $identity, iterable $codes): array
    {
        $bucket = $this->bucket($identity);
        $rows = $this->readAll();
        $persisted = [];
        foreach ($codes as $code) {
            if (! $code instanceof RecoveryCode) {
                continue;
            }
            $row = [
                'code_hash' => $code->codeHash,
                'identity_identifier' => $code->identityIdentifier,
                'identity_type' => $code->identityType,
                'issued_at' => $code->issuedAt,
                'expires_at' => $code->expiresAt,
                'consumed_at' => $code->consumedAt,
                'metadata' => $code->metadata,
            ];
            $rows[] = $row;
            $persisted[] = RecoveryCode::fromArray($row);
        }
        $this->writeAll($rows);

        return $persisted;
    }

    public function listForIdentity(IdentityReference $identity): array
    {
        $bucket = $this->bucket($identity);
        $results = [];
        foreach ($this->readAll() as $row) {
            if ($this->bucketOfRow($row) === $bucket) {
                $results[] = RecoveryCode::fromArray($row);
            }
        }

        return $results;
    }

    public function findUsableByCode(IdentityReference $identity, string $plainCode, ?int $now = null): ?RecoveryCode
    {
        $now ??= time();
        $bucket = $this->bucket($identity);
        foreach ($this->readAll() as $row) {
            if ($this->bucketOfRow($row) !== $bucket) {
                continue;
            }
            $code = RecoveryCode::fromArray($row);
            if (! $code->isUsable($now)) {
                continue;
            }
            $isMatch = str_starts_with($code->codeHash, '$2') || str_starts_with($code->codeHash, '$argon')
                ? password_verify($plainCode, $code->codeHash)
                : hash_equals($code->codeHash, hash('sha256', $plainCode));
            if ($isMatch) {
                return $code;
            }
        }

        return null;
    }

    public function consume(RecoveryCode $code, ?int $now = null): ?RecoveryCode
    {
        $now ??= time();
        $rows = $this->readAll();
        $updated = null;
        foreach ($rows as $idx => $row) {
            if (($row['code_hash'] ?? '') !== $code->codeHash) {
                continue;
            }
            if (($row['identity_identifier'] ?? '') !== $code->identityIdentifier
                || ($row['identity_type'] ?? 'user') !== $code->identityType) {
                continue;
            }
            $stored = RecoveryCode::fromArray($row);
            if (! $stored->isUsable($now)) {
                return null;
            }
            $updated = $stored->withConsumedAt($now);
            $rows[$idx] = $updated->toArray();
            break;
        }
        if ($updated === null) {
            return null;
        }
        $this->writeAll($rows);

        return $updated;
    }

    public function rotateForIdentity(IdentityReference $identity, ?int $now = null): int
    {
        $now ??= time();
        $bucket = $this->bucket($identity);
        $rows = $this->readAll();
        $inactivated = 0;
        foreach ($rows as $idx => $row) {
            if ($this->bucketOfRow($row) !== $bucket) {
                continue;
            }
            if (! isset($row['consumed_at']) || $row['consumed_at'] === null) {
                $row['consumed_at'] = $now;
                $rows[$idx] = $row;
                ++$inactivated;
            }
        }
        $this->writeAll($rows);

        return $inactivated;
    }
}
