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

    /**
     * @return array{
     *   fingerprint:string,
     *   batch_number:int|null,
     *   phase:string,
     *   planned_count:int,
     *   failed_position:int,
     *   completed_versions:list<string>,
     *   failed_version:string|null,
     *   failed_migration:string|null,
     *   failed_description:string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'fingerprint' => $this->fingerprint,
            'batch_number' => $this->batchNumber,
            'phase' => $this->phase,
            'planned_count' => $this->plannedCount,
            'failed_position' => $this->failedPosition,
            'completed_versions' => array_values($this->completedVersions),
            'failed_version' => $this->failedVersion,
            'failed_migration' => $this->failedMigration,
            'failed_description' => $this->failedDescription,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $completedVersions = $payload['completed_versions'] ?? [];

        if (!is_array($completedVersions)) {
            throw new \RuntimeException('Invalid migration checkpoint completed_versions payload.');
        }

        return new self(
            fingerprint: (string) ($payload['fingerprint'] ?? ''),
            batchNumber: isset($payload['batch_number']) ? (int) $payload['batch_number'] : null,
            phase: (string) ($payload['phase'] ?? ''),
            plannedCount: (int) ($payload['planned_count'] ?? 0),
            failedPosition: (int) ($payload['failed_position'] ?? 0),
            completedVersions: array_values(array_map(static fn(mixed $version): string => (string) $version, $completedVersions)),
            failedVersion: isset($payload['failed_version']) ? (string) $payload['failed_version'] : null,
            failedMigration: isset($payload['failed_migration']) ? (string) $payload['failed_migration'] : null,
            failedDescription: isset($payload['failed_description']) ? (string) $payload['failed_description'] : null,
        );
    }
}
