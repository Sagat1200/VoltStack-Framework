<?php

declare(strict_types=1);

namespace Quantum\Database\Transaction\Exception;

use Quantum\Database\Transaction\TransactionException;

/**
 * Raised when a transaction's outcome cannot be determined (e.g. connection
 * lost after COMMIT was dispatched). This is a terminal state and must never
 * be silently converted to a rollback.
 */
final class TransactionOutcomeUnknownException extends TransactionException
{
}
