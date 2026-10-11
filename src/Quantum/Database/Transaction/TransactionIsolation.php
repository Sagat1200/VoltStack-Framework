<?php

declare(strict_types=1);

namespace Quantum\Database\Transaction;

/**
 * SQL isolation levels. Values are the raw SQL tokens used in
 * `SET TRANSACTION ISOLATION LEVEL <value>`.
 */
enum TransactionIsolation: string
{
    case ReadUncommitted = 'READ UNCOMMITTED';
    case ReadCommitted = 'READ COMMITTED';
    case RepeatableRead = 'REPEATABLE READ';
    case Serializable = 'SERIALIZABLE';
}
