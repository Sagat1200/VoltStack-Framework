<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use Quantum\Database\Operation\Contracts\DatabaseIdempotencyStoreInterface;
use Quantum\Database\Operation\DatabaseIdempotencyAcquireResult;
use Quantum\Database\Operation\DatabaseIdempotencyRecord;

final class NullDatabaseIdempotencyStore implements DatabaseIdempotencyStoreInterface
{
    public function acquire(DatabaseIdempotencyRecord $record): DatabaseIdempotencyAcquireResult
    {
        return DatabaseIdempotencyAcquireResult::acquired($record);
    }

    public function complete(DatabaseIdempotencyRecord $record): void
    {
    }

    public function fail(DatabaseIdempotencyRecord $record): void
    {
    }

    public function release(DatabaseIdempotencyRecord $record): void
    {
    }
}
