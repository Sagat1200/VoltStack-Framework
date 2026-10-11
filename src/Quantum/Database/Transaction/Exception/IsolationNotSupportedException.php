<?php

declare(strict_types=1);

namespace Quantum\Database\Transaction\Exception;

use Quantum\Database\Transaction\TransactionException;

/**
 * Raised when the requested isolation level cannot be honoured by the platform.
 * VoltStack never silently downgrades isolation.
 */
final class IsolationNotSupportedException extends TransactionException
{
}
