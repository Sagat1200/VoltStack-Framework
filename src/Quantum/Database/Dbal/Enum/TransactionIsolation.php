<?php declare(strict_types=1);

namespace Quantum\Database\Dbal\Enum;

/**
 * Niveles de aislamiento transaccional.
 */
enum TransactionIsolation: string
{
    case ReadUncommitted = 'READ UNCOMMITTED';
    case ReadCommitted   = 'READ COMMITTED';
    case RepeatableRead  = 'REPEATABLE READ';
    case Serializable    = 'SERIALIZABLE';
}
