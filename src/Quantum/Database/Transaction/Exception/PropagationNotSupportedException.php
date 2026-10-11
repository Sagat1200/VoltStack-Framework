<?php

declare(strict_types=1);

namespace Quantum\Database\Transaction\Exception;

use Quantum\Database\Transaction\TransactionException;

/**
 * Raised when a propagation policy cannot be fulfilled. In this iteration
 * REQUIRES_NEW is explicitly unsupported and raises this exception.
 */
final class PropagationNotSupportedException extends TransactionException
{
}
