<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Contracts;

use Quantum\Database\Operation\DatabaseIdempotencyAcquireResult;
use Quantum\Database\Operation\DatabaseIdempotencyRecord;

interface DatabaseIdempotencyStoreInterface
{
    public function acquire(DatabaseIdempotencyRecord $record): DatabaseIdempotencyAcquireResult;

    public function complete(DatabaseIdempotencyRecord $record): void;

    public function fail(DatabaseIdempotencyRecord $record): void;

    public function release(DatabaseIdempotencyRecord $record): void;
}