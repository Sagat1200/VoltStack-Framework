<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use Quantum\Database\Operation\Contracts\DatabaseIdempotencyStoreInterface;
use Quantum\Database\Operation\DatabaseIdempotencyAcquireResult;
use Quantum\Database\Operation\DatabaseIdempotencyAggregation;
use Quantum\Database\Operation\DatabaseIdempotencyRecord;

final class InMemoryDatabaseIdempotencyStore implements DatabaseIdempotencyStoreInterface
{
    /**
     * @var array<string, DatabaseIdempotencyRecord>
     */
    private array $records = [];

    public function acquire(DatabaseIdempotencyRecord $record): DatabaseIdempotencyAcquireResult
    {
        $existing = $this->records[$record->keyHash] ?? null;
        if (!$existing instanceof DatabaseIdempotencyRecord) {
            $this->records[$record->keyHash] = $record;

            return DatabaseIdempotencyAcquireResult::acquired($record);
        }

        if ($existing->isExpired()) {
            $this->records[$record->keyHash] = $record;

            return DatabaseIdempotencyAcquireResult::acquired($record, 'reclaimed_expired');
        }

        if ($existing->operationFingerprint === $record->operationFingerprint) {
            return DatabaseIdempotencyAcquireResult::duplicate($existing);
        }

        return DatabaseIdempotencyAcquireResult::conflict($existing);
    }

    public function complete(DatabaseIdempotencyRecord $record): void
    {
        $this->records[$record->keyHash] = $record->withStatus('completed');
    }

    public function fail(DatabaseIdempotencyRecord $record): void
    {
        $this->records[$record->keyHash] = $record->withStatus('failed');
    }

    public function release(DatabaseIdempotencyRecord $record): void
    {
        unset($this->records[$record->keyHash]);
    }

    public function latest(): ?DatabaseIdempotencyRecord
    {
        $records = $this->recent(1);
        $latest = $records[array_key_last($records)] ?? null;

        return $latest instanceof DatabaseIdempotencyRecord ? $latest : null;
    }

    public function find(string $keyHash): ?DatabaseIdempotencyRecord
    {
        $record = $this->records[$keyHash] ?? null;

        return $record instanceof DatabaseIdempotencyRecord ? $record : null;
    }

    public function recent(int $limit = 10): array
    {
        $records = array_values($this->records);
        usort($records, static fn(DatabaseIdempotencyRecord $left, DatabaseIdempotencyRecord $right): int => strcmp($left->createdAt, $right->createdAt));

        return array_values(array_slice($records, -max(1, $limit)));
    }

    public function aggregate(int $limit = 50): array
    {
        return DatabaseIdempotencyAggregation::aggregate($this->recent($limit));
    }
}
