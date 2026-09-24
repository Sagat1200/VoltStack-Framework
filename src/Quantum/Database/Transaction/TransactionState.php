<?php

declare(strict_types=1);

namespace Quantum\Database\Transaction;

enum TransactionState: string
{
    case Active = 'active';
    case RollbackOnly = 'rollback_only';
    case Committed = 'committed';
    case RolledBack = 'rolled_back';
}
