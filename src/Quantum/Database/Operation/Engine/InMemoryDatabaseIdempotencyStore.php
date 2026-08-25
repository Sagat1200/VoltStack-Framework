<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use Quantum\Database\Operation\Contracts\DatabaseIdempotencyStoreInterface;
use Quantum\Database\Operation\DatabaseIdempotencyAcquireResult;
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
}
