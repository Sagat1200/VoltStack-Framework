<?php

declare(strict_types=1);

namespace Quantum\Database\Migration;

final readonly class MigrationRecoveryPlan
{
    /**
     * @param list<string> $targetVersions
     * @param list<string> $versions
     */
    public function __construct(
        public string $action,
        public string $sourceOperation,
        public MigrationExecutionCheckpoint $checkpoint,
        public array $targetVersions,
        public array $versions,
        public string $summary,
    ) {
    }

    public function plannedCount(): int
    {
        return count($this->versions);
    }

    public function hasItems(): bool
    {
        return $this->versions !== [];
    }
}
