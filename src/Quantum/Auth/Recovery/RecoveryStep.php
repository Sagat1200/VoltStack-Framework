<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

/**
 * Paso individual dentro de una transacción de recuperación.
 *
 * Cada paso tiene un `strength` que define su orden de ejecución
 * (de menor a mayor). El commit point (credential_persistence) marca
 * el límite entre pasos reversibles (pre-commit) y pasos best-effort
 * post-commit que no se revierte si fallan (ya se cambió la credencial).
 */
final readonly class RecoveryStep
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $stepId,
        public int $strength,
        public RecoveryStepStatus $status = RecoveryStepStatus::Pending,
        public ?int $startedAt = null,
        public ?int $completedAt = null,
        public ?string $failureReason = null,
        public array $metadata = [],
    ) {}

    public function isCompleted(): bool
    {
        return $this->status === RecoveryStepStatus::Completed;
    }

    public function isFailed(): bool
    {
        return $this->status === RecoveryStepStatus::Failed;
    }

    public function isRolledBack(): bool
    {
        return $this->status === RecoveryStepStatus::RolledBack;
    }

    public function withStatus(RecoveryStepStatus $status, ?int $at = null): self
    {
        $at ??= time();

        return new self(
            stepId: $this->stepId,
            strength: $this->strength,
            status: $status,
            startedAt: $status === RecoveryStepStatus::InProgress ? $at : $this->startedAt,
            completedAt: $status === RecoveryStepStatus::Completed ? $at : $this->completedAt,
            failureReason: $this->failureReason,
            metadata: $this->metadata,
        );
    }

    public function withFailure(string $reason, ?int $at = null): self
    {
        $at ??= time();

        return new self(
            stepId: $this->stepId,
            strength: $this->strength,
            status: RecoveryStepStatus::Failed,
            startedAt: $this->startedAt,
            completedAt: $this->completedAt,
            failureReason: $reason,
            metadata: $this->metadata,
        );
    }

    public function withMetadata(array $metadata): self
    {
        return new self(
            stepId: $this->stepId,
            strength: $this->strength,
            status: $this->status,
            startedAt: $this->startedAt,
            completedAt: $this->completedAt,
            failureReason: $this->failureReason,
            metadata: $metadata,
        );
    }

    public function markRolledBack(?int $at = null): self
    {
        $at ??= time();

        return new self(
            stepId: $this->stepId,
            strength: $this->strength,
            status: RecoveryStepStatus::RolledBack,
            startedAt: $this->startedAt,
            completedAt: $this->completedAt,
            failureReason: $this->failureReason,
            metadata: $this->metadata + ['rolled_back_at' => $at],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'step_id' => $this->stepId,
            'strength' => $this->strength,
            'status' => $this->status->value,
            'started_at' => $this->startedAt,
            'completed_at' => $this->completedAt,
            'failure_reason' => $this->failureReason,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $status = RecoveryStepStatus::tryFrom((string) ($data['status'] ?? 'pending'))
            ?? RecoveryStepStatus::Pending;

        return new self(
            stepId: (string) ($data['step_id'] ?? ''),
            strength: isset($data['strength']) && is_int($data['strength']) ? $data['strength'] : 0,
            status: $status,
            startedAt: isset($data['started_at']) && is_int($data['started_at']) ? $data['started_at'] : null,
            completedAt: isset($data['completed_at']) && is_int($data['completed_at']) ? $data['completed_at'] : null,
            failureReason: isset($data['failure_reason']) && is_string($data['failure_reason'])
                ? $data['failure_reason']
                : null,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        );
    }
}
