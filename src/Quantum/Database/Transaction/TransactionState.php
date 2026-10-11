<?php

declare(strict_types=1);

namespace Quantum\Database\Transaction;

enum TransactionState: string
{
    case New = 'new';
    case Starting = 'starting';
    case Active = 'active';
    case MarkedRollbackOnly = 'rollback_only';
    case Committing = 'committing';
    case Committed = 'committed';
    case RollingBack = 'rolling_back';
    case RolledBack = 'rolled_back';
    case Failed = 'failed';
    case Unknown = 'unknown';

    /**
     * Returns whether the transaction is considered active for the purposes of
     * accepting new work or nesting. Only Active and MarkedRollbackOnly allow
     * further operations within the transactional boundary.
     */
    public function isActive(): bool
    {
        return $this === self::Active || $this === self::MarkedRollbackOnly;
    }

    /**
     * Returns whether the state is terminal: no further lifecycle transitions
     * are allowed from a terminal state.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Committed,
            self::RolledBack,
            self::Failed,
            self::Unknown,
        ], true);
    }
}
