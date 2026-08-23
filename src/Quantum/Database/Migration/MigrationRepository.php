<?php

declare(strict_types=1);

namespace Quantum\Database\Migration;

use Quantum\Database\Dbal\Contract\ConnectionInterface;

final class MigrationRepository
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $tableName = 'framework_migrations',
    ) {
    }

    public function tableName(): string
    {
        return $this->tableName;
    }

    public function ensureRepository(): void
    {
        $table = $this->connection->quoteIdentifier($this->tableName);

        $this->connection->executeStatement(
            sprintf(
                'CREATE TABLE IF NOT EXISTS %s (version VARCHAR(191) PRIMARY KEY, migration VARCHAR(255) NOT NULL, batch INTEGER NOT NULL, executed_at VARCHAR(32) NOT NULL)',
                $table,
            )
        );
    }

    /**
     * @return list<MigrationRecord>
     */
    public function applied(): array
    {
        $this->ensureRepository();

        $table = $this->connection->quoteIdentifier($this->tableName);
        $rows = $this->connection
            ->executeQuery(sprintf('SELECT version, migration, batch, executed_at FROM %s ORDER BY batch ASC, version ASC', $table))
            ->fetchAllAssoc();

        $records = [];

        foreach ($rows as $row) {
            $records[] = new MigrationRecord(
                version: (string) ($row['version'] ?? ''),
                migration: (string) ($row['migration'] ?? ''),
                batch: (int) ($row['batch'] ?? 0),
                executedAt: (string) ($row['executed_at'] ?? ''),
            );
        }

        return $records;
    }

    /**
     * @return array<string, MigrationRecord>
     */
    public function appliedByVersion(): array
    {
        $indexed = [];

        foreach ($this->applied() as $record) {
            $indexed[$record->version] = $record;
        }

        return $indexed;
    }

    public function nextBatchNumber(): int
    {
        $this->ensureRepository();

        $table = $this->connection->quoteIdentifier($this->tableName);
        $row = $this->connection
            ->executeQuery(sprintf('SELECT MAX(batch) AS max_batch FROM %s', $table))
            ->fetchOneAssoc();

        return ((int) ($row['max_batch'] ?? 0)) + 1;
    }

    /**
     * @return list<MigrationRecord>
     */
    public function latestBatch(): array
    {
        $this->ensureRepository();

        $table = $this->connection->quoteIdentifier($this->tableName);
        $row = $this->connection
            ->executeQuery(sprintf('SELECT MAX(batch) AS max_batch FROM %s', $table))
            ->fetchOneAssoc();

        $batch = (int) ($row['max_batch'] ?? 0);
        if ($batch <= 0) {
            return [];
        }

        $rows = $this->connection
            ->executeQuery(
                sprintf('SELECT version, migration, batch, executed_at FROM %s WHERE batch = ? ORDER BY version DESC', $table),
                [$batch],
            )
            ->fetchAllAssoc();

        $records = [];

        foreach ($rows as $row) {
            $records[] = new MigrationRecord(
                version: (string) ($row['version'] ?? ''),
                migration: (string) ($row['migration'] ?? ''),
                batch: (int) ($row['batch'] ?? 0),
                executedAt: (string) ($row['executed_at'] ?? ''),
            );
        }

        return $records;
    }

    /**
     * @return list<MigrationRecord>
     */
    public function latest(int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $this->ensureRepository();

        $table = $this->connection->quoteIdentifier($this->tableName);
        $rows = $this->connection
            ->executeQuery(
                sprintf('SELECT version, migration, batch, executed_at FROM %s ORDER BY batch DESC, version DESC LIMIT %d', $table, $limit),
            )
            ->fetchAllAssoc();

        $records = [];

        foreach ($rows as $row) {
            $records[] = new MigrationRecord(
                version: (string) ($row['version'] ?? ''),
                migration: (string) ($row['migration'] ?? ''),
                batch: (int) ($row['batch'] ?? 0),
                executedAt: (string) ($row['executed_at'] ?? ''),
            );
        }

        return $records;
    }

    public function recordApplied(MigrationInterface $migration, int $batch, ?\DateTimeImmutable $executedAt = null): void
    {
        $this->ensureRepository();
        $table = $this->connection->quoteIdentifier($this->tableName);

        $this->connection->executeStatement(
            sprintf('INSERT INTO %s (version, migration, batch, executed_at) VALUES (?, ?, ?, ?)', $table),
            [
                $migration->version(),
                $migration::class,
                $batch,
                ($executedAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM),
            ],
        );
    }

    public function remove(MigrationInterface $migration): void
    {
        $this->ensureRepository();
        $table = $this->connection->quoteIdentifier($this->tableName);

        $this->connection->executeStatement(
            sprintf('DELETE FROM %s WHERE version = ?', $table),
            [$migration->version()],
        );
    }
}
