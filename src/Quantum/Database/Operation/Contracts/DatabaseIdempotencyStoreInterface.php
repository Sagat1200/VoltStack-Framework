<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Contracts;

use Quantum\Database\Operation\DatabaseIdempotencyAcquireResult;
use Quantum\Database\Operation\DatabaseIdempotencyRecord;

interface DatabaseIdempotencyStoreInterface
{
    public function acquire(DatabaseIdempotencyRecord $record): DatabaseIdempotencyAcquireResult;

    /**
     * @param array<string, mixed> $confirmation
     */
    public function complete(DatabaseIdempotencyRecord $record, array $confirmation = []): void;

    public function fail(DatabaseIdempotencyRecord $record): void;

    public function release(DatabaseIdempotencyRecord $record): void;

    public function latest(): ?DatabaseIdempotencyRecord;

    public function find(string $keyHash): ?DatabaseIdempotencyRecord;

    /**
     * @return list<DatabaseIdempotencyRecord>
     */
    public function recent(int $limit = 10): array;

    /**
     * @return array<string, mixed>
     */
    public function aggregate(int $limit = 50): array;
}
