<?php

declare(strict_types=1);

namespace Quantum\Auth\AbuseProtection;

use PDO;
use PDOException;
use Quantum\Auth\Contracts\DistributedThrottleCounterInterface;
use Quantum\Database\Contracts\ConnectionManagerInterface;
use RuntimeException;

final class DatabaseDistributedThrottleCounter implements DistributedThrottleCounterInterface
{
    private bool $schemaReady = false;

    public function __construct(
        private readonly ConnectionManagerInterface $connections,
        private readonly ?string $connectionName = null,
        private readonly string $table = 'auth_throttle_events',
    ) {}

    public function currentCount(string $bucketKey, ?int $now = null): int
    {
        $timestamp = $now ?? time();
        $this->purgeExpired($timestamp);

        $statement = $this->pdo()->prepare(sprintf(
            'SELECT COUNT(*) FROM %s WHERE bucket_key = :bucket_key AND expires_at > :now',
            $this->quotedTable(),
        ));
        $statement->execute([
            ':bucket_key' => $bucketKey,
            ':now' => $timestamp,
        ]);

        return max(0, (int) $statement->fetchColumn());
    }

    public function increment(string $bucketKey, int $windowSeconds, ?int $now = null): int
    {
        $timestamp = $now ?? time();
        $window = max(1, $windowSeconds);
        $expiresAt = $timestamp + $window;
        $this->purgeExpired($timestamp);

        $statement = $this->pdo()->prepare(sprintf(
            'INSERT INTO %s (bucket_key, observed_at, expires_at) VALUES (:bucket_key, :observed_at, :expires_at)',
            $this->quotedTable(),
        ));
        $statement->execute([
            ':bucket_key' => $bucketKey,
            ':observed_at' => $timestamp,
            ':expires_at' => $expiresAt,
        ]);

        return $this->currentCount($bucketKey, $timestamp);
    }

    public function reset(string $bucketKey): void
    {
        $statement = $this->pdo()->prepare(sprintf(
            'DELETE FROM %s WHERE bucket_key = :bucket_key',
            $this->quotedTable(),
        ));
        $statement->execute([
            ':bucket_key' => $bucketKey,
        ]);
    }

    private function purgeExpired(int $timestamp): void
    {
        $statement = $this->pdo()->prepare(sprintf(
            'DELETE FROM %s WHERE expires_at <= :now',
            $this->quotedTable(),
        ));
        $statement->execute([
            ':now' => $timestamp,
        ]);
    }

    private function pdo(): PDO
    {
        $this->ensureSchema();

        return $this->connections->connection($this->connectionName)->pdo();
    }

    private function ensureSchema(): void
    {
        if ($this->schemaReady) {
            return;
        }

        $pdo = $this->connections->connection($this->connectionName)->pdo();

        try {
            $pdo->exec(sprintf(
                'CREATE TABLE IF NOT EXISTS %s (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    bucket_key TEXT NOT NULL,
                    observed_at INTEGER NOT NULL,
                    expires_at INTEGER NOT NULL
                )',
                $this->quotedTable(),
            ));
            $pdo->exec(sprintf(
                'CREATE INDEX IF NOT EXISTS %s ON %s (bucket_key, expires_at)',
                $this->quotedIndex('bucket_expires_idx'),
                $this->quotedTable(),
            ));
        } catch (PDOException $exception) {
            throw new RuntimeException(
                sprintf('Unable to initialize distributed throttle storage table [%s].', $this->table),
                previous: $exception,
            );
        }

        $this->schemaReady = true;
    }

    private function quotedTable(): string
    {
        return $this->quoteIdentifier($this->table);
    }

    private function quotedIndex(string $suffix): string
    {
        return $this->quoteIdentifier($this->table . '_' . $suffix);
    }

    private function quoteIdentifier(string $identifier): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9_]/', '_', $identifier) ?? '';
        $normalized = trim($normalized, '_');

        if ($normalized === '') {
            throw new RuntimeException('Invalid distributed throttle identifier.');
        }

        return '"' . $normalized . '"';
    }
}
