<?php

declare(strict_types=1);

namespace Quantum\Database\Migration;

final readonly class MigrationExecutionCheckpoint
{
    /**
     * @param list<string> $completedVersions
     */
    public function __construct(
        public string $fingerprint,
        public ?int $batchNumber,
        public string $phase,
        public int $plannedCount,
        public int $failedPosition,
        public array $completedVersions,
        public ?string $failedVersion,
        public ?string $failedMigration,
        public ?string $failedDescription,
    ) {}

    public function completedCount(): int
    {
        return count($this->completedVersions);
    }

    public function hasFailedMigration(): bool
    {
        return $this->failedVersion !== null && $this->failedMigration !== null;
    }

    public function remainingCount(): int
    {
        return max(0, $this->plannedCount - $this->completedCount());
    }
}
