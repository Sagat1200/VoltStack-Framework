<?php

declare(strict_types=1);

namespace Quantum\Database\Transaction;

/**
 * Transaction propagation policies.
 *
 * RequiresNew is explicitly not supported in this iteration and must raise
 * PropagationNotSupportedException rather than being emulated with savepoints.
 */
enum TransactionPropagation: string
{
    case Required = 'required';
    case RequiresNew = 'requires_new';
    case Mandatory = 'mandatory';
    case Never = 'never';
    case Supports = 'supports';
}
