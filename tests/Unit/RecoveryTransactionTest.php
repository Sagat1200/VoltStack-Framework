<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Recovery\RecoveryStepStatus;
use Quantum\Auth\Recovery\RecoveryTransaction;

/**
 * Pruebas unitarias del modelo RecoveryTransaction (Bloque 6):
 * ordenamiento por strength, rollback parcial pre-commit,
 * roll-forward en reintentos y serialización.
 */
final class RecoveryTransactionTest extends TestCase
{
    public function test_ordered_steps_are_sorted_by_strength_ascending(): void
    {
        $transaction = RecoveryTransaction::create('token-xyz');
        $ordered = $transaction->orderedSteps();

        self::assertCount(9, $ordered);

        $strengths = array_map(static fn ($step): int => $step->strength, $ordered);
        $sorted = $strengths;
        sort($sorted);

        self::assertSame($sorted, $strengths, 'Los pasos deben estar ordenados por strength ascendente');
    }

    public function test_commit_point_is_credential_persistence_at_strength_forty(): void
    {
        $transaction = RecoveryTransaction::create('token-xyz');
        $commit = $transaction->findStep(RecoveryTransaction::STEP_CREDENTIAL_PERSISTENCE);

        self::assertNotNull($commit);
        self::assertSame(40, $commit->strength);
    }

    public function test_mark_step_completed_updates_status_and_timestamps(): void
    {
        $transaction = RecoveryTransaction::create('token-xyz');

        $before = time();
        $transaction = $transaction->markStepCompleted(RecoveryTransaction::STEP_TOKEN_VALIDATION);
        $after = time();

        $step = $transaction->findStep(RecoveryTransaction::STEP_TOKEN_VALIDATION);
        self::assertNotNull($step);
        self::assertSame(RecoveryStepStatus::Completed, $step->status);
        self::assertNotNull($step->completedAt);
        self::assertGreaterThanOrEqual($before, $step->completedAt);
        self::assertLessThanOrEqual($after, $step->completedAt);
        self::assertTrue($transaction->isStepCompleted(RecoveryTransaction::STEP_TOKEN_VALIDATION));
    }

    public function test_mark_step_failed_sets_failure_reason(): void
    {
        $transaction = RecoveryTransaction::create('token-xyz');
        $transaction = $transaction->markStepFailed(
            RecoveryTransaction::STEP_EVIDENCE_VERIFICATION,
            'Código TOTP inválido',
        );

        $step = $transaction->findStep(RecoveryTransaction::STEP_EVIDENCE_VERIFICATION);
        self::assertNotNull($step);
        self::assertSame(RecoveryStepStatus::Failed, $step->status);
        self::assertSame('Código TOTP inválido', $step->failureReason);
    }

    public function test_rollback_reversible_only_rolls_back_pre_commit_completed_steps(): void
    {
        $transaction = RecoveryTransaction::create('token-xyz')
            ->markStepCompleted(RecoveryTransaction::STEP_TOKEN_VALIDATION)
            ->markStepCompleted(RecoveryTransaction::STEP_EVIDENCE_VERIFICATION)
            ->markStepCompleted(RecoveryTransaction::STEP_PASSWORD_POLICY)
            ->markStepCompleted(RecoveryTransaction::STEP_CREDENTIAL_PERSISTENCE)
            ->markStepCompleted(RecoveryTransaction::STEP_STATE_CLEANUP);

        // Simular fallo en un paso posterior al commit.
        $transaction = $transaction->markStepFailed(
            RecoveryTransaction::STEP_SESSION_REVOCATION,
            'session store down',
        );

        $rolledBack = $transaction->rollbackReversible();

        // Pasos pre-commit (strength < 40) deben quedar rolled back.
        foreach ([
            RecoveryTransaction::STEP_TOKEN_VALIDATION,
            RecoveryTransaction::STEP_EVIDENCE_VERIFICATION,
            RecoveryTransaction::STEP_PASSWORD_POLICY,
        ] as $stepId) {
            $step = $rolledBack->findStep($stepId);
            self::assertNotNull($step);
            self::assertSame(RecoveryStepStatus::RolledBack, $step->status, "Step {$stepId} should be rolled back");
        }

        // Pasos post-commit (strength >= 40) NO se deshacen: el commit ya ocurrió.
        foreach ([
            RecoveryTransaction::STEP_CREDENTIAL_PERSISTENCE,
            RecoveryTransaction::STEP_STATE_CLEANUP,
        ] as $stepId) {
            $step = $rolledBack->findStep($stepId);
            self::assertNotNull($step);
            self::assertSame(RecoveryStepStatus::Completed, $step->status, "Step {$stepId} should remain completed");
        }

        // El paso fallido mantiene su estado.
        $failed = $rolledBack->findStep(RecoveryTransaction::STEP_SESSION_REVOCATION);
        self::assertNotNull($failed);
        self::assertSame(RecoveryStepStatus::Failed, $failed->status);
    }

    public function test_next_pending_step_skips_completed_steps_for_roll_forward(): void
    {
        $transaction = RecoveryTransaction::create('token-xyz')
            ->markStepCompleted(RecoveryTransaction::STEP_TOKEN_VALIDATION)
            ->markStepCompleted(RecoveryTransaction::STEP_EVIDENCE_VERIFICATION);

        $next = $transaction->nextPendingStep();

        self::assertNotNull($next);
        self::assertSame(RecoveryTransaction::STEP_PASSWORD_POLICY, $next->stepId);
    }

    public function test_next_pending_step_returns_null_when_all_completed(): void
    {
        $transaction = RecoveryTransaction::create('token-xyz');
        foreach ($transaction->orderedSteps() as $step) {
            $transaction = $transaction->markStepCompleted($step->stepId);
        }

        self::assertNull($transaction->nextPendingStep());
        self::assertTrue($transaction->allCompleted());
    }

    public function test_serialization_round_trip_preserves_step_states(): void
    {
        $transaction = RecoveryTransaction::create('token-xyz')
            ->markStepCompleted(RecoveryTransaction::STEP_TOKEN_VALIDATION)
            ->markStepFailed(RecoveryTransaction::STEP_EVIDENCE_VERIFICATION, 'bad evidence');

        $array = $transaction->toArray();
        $restored = RecoveryTransaction::fromArray($array);

        self::assertSame($transaction->tokenId, $restored->tokenId);
        self::assertTrue($restored->isStepCompleted(RecoveryTransaction::STEP_TOKEN_VALIDATION));

        $failed = $restored->findStep(RecoveryTransaction::STEP_EVIDENCE_VERIFICATION);
        self::assertNotNull($failed);
        self::assertSame(RecoveryStepStatus::Failed, $failed->status);
        self::assertSame('bad evidence', $failed->failureReason);
    }
}
