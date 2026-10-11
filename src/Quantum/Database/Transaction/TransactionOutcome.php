<?php

declare(strict_types=1);

namespace Quantum\Database\Transaction;

/**
 * Represents the final observable outcome of a transactional unit.
 *
 * Unknown is terminal and must never be silently converted to RolledBack:
 * the database may have committed even though the client could not confirm it.
 */
enum TransactionOutcome: string
{
    case Committed = 'committed';
    case RolledBack = 'rolled_back';
    case NotStarted = 'not_started';
    case Unknown = 'unknown';

    public function isKnown(): bool
    {
        return $this === self::Committed || $this === self::RolledBack;
    }
}
