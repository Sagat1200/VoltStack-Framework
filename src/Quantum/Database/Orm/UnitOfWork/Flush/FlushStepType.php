<?php declare(strict_types=1);

namespace Quantum\Database\Orm\UnitOfWork\Flush;

enum FlushStepType: string
{
    case Insert = 'INSERT';
    case Update = 'UPDATE';
    case Delete = 'DELETE';
}
