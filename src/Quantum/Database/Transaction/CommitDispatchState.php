<?php

declare(strict_types=1);

namespace Quantum\Database\Transaction;

/**
 * Tracks the dispatch lifecycle of the COMMIT statement itself.
 *
 * This is what makes UNKNOWN outcome detectable: once commit has been
 * dispatched (Dispatched) but confirmation is lost, the outcome is Unknown
 * rather than RolledBack.
 */
enum CommitDispatchState: string
{
    case NotDispatched = 'not_dispatched';
    case Dispatching = 'dispatching';
    case Dispatched = 'dispatched';
    case Confirmed = 'confirmed';
}
