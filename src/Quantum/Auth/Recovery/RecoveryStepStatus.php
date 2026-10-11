<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

/**
 * Estado de un paso dentro de una transacción de recuperación.
 *
 * - pending: paso aún no ejecutado.
 * - in_progress: paso en ejecución (transitorio).
 * - completed: paso ejecutado con éxito; puede re-ejecutarse en roll-forward.
 * - failed: paso falló; requiere reintento o rollback.
 * - rolled_back: paso completado previamente pero revertido tras un fallo
 *   de un paso posterior pre-commit.
 */
enum RecoveryStepStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';
    case RolledBack = 'rolled_back';
}
