<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use Quantum\Database\Operation\Contracts\DatabaseIdempotencyStoreInterface;
use Quantum\Database\Operation\DatabaseIdempotencyAcquireResult;
use Quantum\Database\Operation\DatabaseIdempotencyAggregation;
use Quantum\Database\Operation\DatabaseIdempotencyRecord;

final class NullDatabaseIdempotencyStore implements DatabaseIdempotencyStoreInterface
{
    public function acquire(DatabaseIdempotencyRecord $record): DatabaseIdempotencyAcquireResult
    {
        return DatabaseIdempotencyAcquireResult::acquired($record);
    }

    public function complete(DatabaseIdempotencyRecord $record, array $confirmation = []): void {}

    public function fail(DatabaseIdempotencyRecord $record): void {}

    public function release(DatabaseIdempotencyRecord $record): void {}

    public function latest(): ?DatabaseIdempotencyRecord
    {
        return null;
    }

    public function find(string $keyHash): ?DatabaseIdempotencyRecord
    {
        return null;
    }

    public function recent(int $limit = 10): array
    {
        return [];
    }

    public function aggregate(int $limit = 50): array
    {
        return DatabaseIdempotencyAggregation::aggregate([]);
    }
}
