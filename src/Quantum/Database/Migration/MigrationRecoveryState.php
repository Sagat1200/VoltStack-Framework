<?php

declare(strict_types=1);

namespace Quantum\Database\Migration;

final readonly class MigrationRecoveryState
{
    /**
     * @param list<string> $targetVersions
     */
    public function __construct(
        public string $operation,
        public MigrationExecutionCheckpoint $checkpoint,
        public array $targetVersions,
        public string $recordedAt,
    ) {}

    /**
     * @return array{
     *   operation:string,
     *   checkpoint:array<string, mixed>,
     *   target_versions:list<string>,
     *   recorded_at:string
     * }
     */
    public function toArray(): array
    {
        return [
            'operation' => $this->operation,
            'checkpoint' => $this->checkpoint->toArray(),
            'target_versions' => array_values($this->targetVersions),
            'recorded_at' => $this->recordedAt,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $operation = (string) ($payload['operation'] ?? '');
        $checkpoint = $payload['checkpoint'] ?? null;
        $targetVersions = $payload['target_versions'] ?? [];
        $recordedAt = (string) ($payload['recorded_at'] ?? '');

        if (!in_array($operation, ['migrate', 'rollback'], true)) {
            throw new \RuntimeException('Invalid migration recovery operation payload.');
        }

        if (!is_array($checkpoint)) {
            throw new \RuntimeException('Invalid migration recovery checkpoint payload.');
        }

        if (!is_array($targetVersions)) {
            throw new \RuntimeException('Invalid migration recovery target_versions payload.');
        }

        return new self(
            operation: $operation,
            checkpoint: MigrationExecutionCheckpoint::fromArray($checkpoint),
            targetVersions: array_values(array_map(static fn(mixed $version): string => (string) $version, $targetVersions)),
            recordedAt: $recordedAt,
        );
    }
}
