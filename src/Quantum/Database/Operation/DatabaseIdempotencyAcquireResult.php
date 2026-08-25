<?php

declare(strict_types=1);

namespace Quantum\Database\Operation;

final readonly class DatabaseIdempotencyAcquireResult
{
    public function __construct(
        public bool $acquired,
        public string $reason,
        public ?DatabaseIdempotencyRecord $record = null,
    ) {}

    public static function acquired(DatabaseIdempotencyRecord $record, string $reason = 'acquired'): self
    {
        return new self(true, $reason, $record);
    }

    public static function duplicate(DatabaseIdempotencyRecord $record): self
    {
        return new self(false, 'duplicate', $record);
    }

    public static function replay(DatabaseIdempotencyRecord $record): self
    {
        return new self(false, 'replay', $record);
    }

    public static function conflict(DatabaseIdempotencyRecord $record): self
    {
        return new self(false, 'conflict', $record);
    }
}