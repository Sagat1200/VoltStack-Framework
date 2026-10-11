<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

/**
 * Transacción de recuperación multi-step.
 *
 * Modela el ciclo de vida completo de un flujo de recuperación
 * (password reset, admin-initiated, federated-link) como una secuencia
 * ordenada de pasos (`RecoveryStep`) ligados a un `tokenId`.
 *
 * Los pasos se ordenan por `strength` ascendente. El paso
 * `CREDENTIAL_PERSISTENCE` es el commit point: los pasos anteriores son
 * reversibles (pre-commit), los posteriores son best-effort post-commit
 * (no se revierten si fallan, ya que la credencial ya cambió).
 *
 * Roll-forward: en un reintento con el mismo token aún no consumido, los
 * pasos ya `completed` se saltan y la ejecución continúa desde el primer
 * paso pendiente/fallado/rolled_back.
 *
 * Rollback parcial: si un paso pre-commit falla, todos los pasos
 * pre-commit completados se marcan `rolled_back` y se lanza excepción.
 */
final readonly class RecoveryTransaction
{
    // Identificadores de paso y sus strengths (orden de ejecución).
    public const STEP_TOKEN_VALIDATION = 'token_validation';
    public const STEP_EVIDENCE_VERIFICATION = 'evidence_verification';
    public const STEP_PASSWORD_POLICY = 'password_policy';
    public const STEP_CREDENTIAL_PERSISTENCE = 'credential_persistence';
    public const STEP_STATE_CLEANUP = 'state_cleanup';
    public const STEP_SESSION_REVOCATION = 'session_revocation';
    public const STEP_RECOVERY_CODE_ROTATION = 'recovery_code_rotation';
    public const STEP_NOTIFICATION = 'notification';
    public const STEP_TOKEN_CONSUMPTION = 'token_consumption';

    // Strength por paso (menor = primero).
    public const STRENGTH_TOKEN_VALIDATION = 10;
    public const STRENGTH_EVIDENCE_VERIFICATION = 20;
    public const STRENGTH_PASSWORD_POLICY = 30;
    public const STRENGTH_CREDENTIAL_PERSISTENCE = 40; // commit point
    public const STRENGTH_STATE_CLEANUP = 50;
    public const STRENGTH_SESSION_REVOCATION = 60;
    public const STRENGTH_RECOVERY_CODE_ROTATION = 70;
    public const STRENGTH_NOTIFICATION = 80;
    public const STRENGTH_TOKEN_CONSUMPTION = 90;

    /**
     * @param list<RecoveryStep> $steps
     */
    public function __construct(
        public string $tokenId,
        public array $steps,
        public int $startedAt,
        public int $updatedAt,
    ) {}

    /**
     * Crea una transacción nueva con todos los pasos en estado pending.
     */
    public static function create(string $tokenId, ?int $now = null): self
    {
        $now ??= time();

        $definitions = [
            self::STEP_TOKEN_VALIDATION => self::STRENGTH_TOKEN_VALIDATION,
            self::STEP_EVIDENCE_VERIFICATION => self::STRENGTH_EVIDENCE_VERIFICATION,
            self::STEP_PASSWORD_POLICY => self::STRENGTH_PASSWORD_POLICY,
            self::STEP_CREDENTIAL_PERSISTENCE => self::STRENGTH_CREDENTIAL_PERSISTENCE,
            self::STEP_STATE_CLEANUP => self::STRENGTH_STATE_CLEANUP,
            self::STEP_SESSION_REVOCATION => self::STRENGTH_SESSION_REVOCATION,
            self::STEP_RECOVERY_CODE_ROTATION => self::STRENGTH_RECOVERY_CODE_ROTATION,
            self::STEP_NOTIFICATION => self::STRENGTH_NOTIFICATION,
            self::STEP_TOKEN_CONSUMPTION => self::STRENGTH_TOKEN_CONSUMPTION,
        ];

        $steps = [];
        foreach ($definitions as $id => $strength) {
            $steps[] = new RecoveryStep(
                stepId: $id,
                strength: $strength,
                status: RecoveryStepStatus::Pending,
            );
        }

        return new self(
            tokenId: $tokenId,
            steps: $steps,
            startedAt: $now,
            updatedAt: $now,
        );
    }

    /**
     * @return list<RecoveryStep> pasos ordenados por strength ascendente.
     */
    public function orderedSteps(): array
    {
        $steps = $this->steps;
        usort(
            $steps,
            static fn (RecoveryStep $a, RecoveryStep $b): int => $a->strength <=> $b->strength,
        );

        return $steps;
    }

    public function findStep(string $stepId): ?RecoveryStep
    {
        foreach ($this->steps as $step) {
            if ($step->stepId === $stepId) {
                return $step;
            }
        }

        return null;
    }

    public function isStepCompleted(string $stepId): bool
    {
        $step = $this->findStep($stepId);

        return $step instanceof RecoveryStep && $step->isCompleted();
    }

    /**
     * Devuelve el índice (0-based) del paso dentro de la lista ordenada,
     * o -1 si no existe.
     */
    public function stepIndex(string $stepId): int
    {
        foreach ($this->orderedSteps() as $index => $step) {
            if ($step->stepId === $stepId) {
                return $index;
            }
        }

        return -1;
    }

    /**
     * Reemplaza un paso por su stepId y devuelve la transacción actualizada.
     */
    public function withStep(RecoveryStep $replacement, ?int $now = null): self
    {
        $now ??= time();
        $steps = [];
        foreach ($this->steps as $step) {
            $steps[] = $step->stepId === $replacement->stepId ? $replacement : $step;
        }

        return new self(
            tokenId: $this->tokenId,
            steps: $steps,
            startedAt: $this->startedAt,
            updatedAt: $now,
        );
    }

    /**
     * Marca un paso como completado con metadata opcional.
     *
     * @param array<string, mixed> $metadata
     */
    public function markStepCompleted(string $stepId, array $metadata = [], ?int $now = null): self
    {
        $step = $this->findStep($stepId);
        if (! $step instanceof RecoveryStep) {
            return $this;
        }

        $completed = $step
            ->withStatus(RecoveryStepStatus::InProgress, $now)
            ->withStatus(RecoveryStepStatus::Completed, $now);

        if ($metadata !== []) {
            $completed = $completed->withMetadata($metadata);
        }

        return $this->withStep($completed, $now);
    }

    /**
     * Marca un paso como fallido con un motivo.
     */
    public function markStepFailed(string $stepId, string $reason, ?int $now = null): self
    {
        $step = $this->findStep($stepId);
        if (! $step instanceof RecoveryStep) {
            return $this;
        }

        return $this->withStep($step->withFailure($reason, $now), $now);
    }

    /**
     * Rollback parcial: marca como `rolled_back` todos los pasos
     * completados cuya strength sea < commit point (pre-commit).
     *
     * Los pasos post-commit (strength >= credential_persistence) NO se
     * revierten porque ya se confirmó el cambio de credencial.
     */
    public function rollbackReversible(?int $now = null): self
    {
        $now ??= time();
        $steps = [];
        foreach ($this->steps as $step) {
            if (
                $step->strength < self::STRENGTH_CREDENTIAL_PERSISTENCE
                && $step->isCompleted()
            ) {
                $steps[] = $step->markRolledBack($now);
            } else {
                $steps[] = $step;
            }
        }

        return new self(
            tokenId: $this->tokenId,
            steps: $steps,
            startedAt: $this->startedAt,
            updatedAt: $now,
        );
    }

    /**
     * Indica si todos los pasos están completados (transacción terminada).
     */
    public function allCompleted(): bool
    {
        foreach ($this->steps as $step) {
            if (! $step->isCompleted()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Devuelve el primer paso no completado (pending/failed/rolled_back)
     * en orden de strength, o null si todos están completados.
     */
    public function nextPendingStep(): ?RecoveryStep
    {
        foreach ($this->orderedSteps() as $step) {
            if (! $step->isCompleted()) {
                return $step;
            }
        }

        return null;
    }

    /**
     * @return list<array{step_id:string,strength:int,status:string,started_at:?int,completed_at:?int,failure_reason:?string,metadata:array<string,mixed>}>
     */
    public function stepsToArray(): array
    {
        return array_map(
            static fn (RecoveryStep $step): array => $step->toArray(),
            $this->orderedSteps(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'token_id' => $this->tokenId,
            'started_at' => $this->startedAt,
            'updated_at' => $this->updatedAt,
            'steps' => $this->stepsToArray(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $steps = [];
        $rawSteps = is_array($data['steps'] ?? null) ? $data['steps'] : [];
        foreach ($rawSteps as $rawStep) {
            if (is_array($rawStep)) {
                $steps[] = RecoveryStep::fromArray($rawStep);
            }
        }

        return new self(
            tokenId: (string) ($data['token_id'] ?? ''),
            steps: $steps,
            startedAt: isset($data['started_at']) && is_int($data['started_at']) ? $data['started_at'] : time(),
            updatedAt: isset($data['updated_at']) && is_int($data['updated_at']) ? $data['updated_at'] : time(),
        );
    }
}
