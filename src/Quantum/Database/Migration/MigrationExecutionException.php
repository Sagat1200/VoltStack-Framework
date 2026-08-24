<?php

declare(strict_types=1);

namespace Quantum\Database\Migration;

final class MigrationExecutionException extends \RuntimeException
{
    public function __construct(
        public readonly MigrationOperationalFailure $failure,
        public readonly MigrationExecutionCheckpoint $checkpoint,
        public readonly bool $retryable = false,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, (int) ($previous?->getCode() ?? 0), $previous);
    }

    public function recoveryAdvice(): MigrationRecoveryAdvice
    {
        $checkpoint = $this->checkpoint;

        return match ($checkpoint->phase) {
            'rollback' => $this->rollbackRecoveryAdvice(),
            'verify' => $this->verifyRecoveryAdvice(),
            default => $this->migrateRecoveryAdvice(),
        };
    }

    private function migrateRecoveryAdvice(): MigrationRecoveryAdvice
    {
        $checkpoint = $this->checkpoint;
        $commands = ['db:migrate-status'];

        if ($checkpoint->completedCount() > 0) {
            $commands[] = 'db:migrate';
            $commands[] = sprintf('db:rollback --step=%d', $checkpoint->completedCount());

            return new MigrationRecoveryAdvice(
                strategy: $this->retryable ? 'resume_forward' : 'resume_or_revert_partial',
                summary: 'Hay progreso aplicado antes del fallo. Revisa el estado, corrige la causa y continua, o revierte solo lo ya aplicado si necesitas volver al punto inicial.',
                recommendedCommands: $commands,
            );
        }

        $commands[] = 'db:migrate';

        return new MigrationRecoveryAdvice(
            strategy: $this->retryable ? 'retry_forward' : 'fix_and_retry',
            summary: 'No hubo progreso aplicado antes del fallo. Corrige la causa y vuelve a ejecutar la migracion.',
            recommendedCommands: $commands,
        );
    }

    private function rollbackRecoveryAdvice(): MigrationRecoveryAdvice
    {
        $checkpoint = $this->checkpoint;
        $commands = ['db:migrate-status', 'db:rollback'];

        return new MigrationRecoveryAdvice(
            strategy: $checkpoint->completedCount() > 0 ? 'continue_rollback' : 'fix_and_retry_rollback',
            summary: $checkpoint->completedCount() > 0
                ? 'El rollback avanzo parcialmente. Revisa el estado actual y vuelve a ejecutar rollback para continuar desde el punto alcanzado.'
                : 'El rollback no alcanzo a revertir migraciones. Corrige la causa y vuelve a ejecutar rollback.',
            recommendedCommands: $commands,
        );
    }

    private function verifyRecoveryAdvice(): MigrationRecoveryAdvice
    {
        $checkpoint = $this->checkpoint;
        $commands = ['db:migrate-status'];

        if ($checkpoint->completedCount() > 0) {
            $commands[] = sprintf('db:rollback --step=%d', $checkpoint->completedCount());
        }

        return new MigrationRecoveryAdvice(
            strategy: 'reconcile_after_verify',
            summary: 'La ejecucion termino pero la verificacion no cerro limpia. Revisa el historial y reconcilia el estado antes de continuar.',
            recommendedCommands: $commands,
        );
    }
}
