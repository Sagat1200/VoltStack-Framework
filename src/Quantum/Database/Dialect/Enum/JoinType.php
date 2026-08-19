<?php declare(strict_types=1);

namespace Quantum\Database\Dialect\Enum;

/**
 * Modos JOIN que emite la dialect.
 */
enum JoinType: string
{
    case Inner = 'INNER';
    case Left  = 'LEFT';
    case Right = 'RIGHT';
    case Full  = 'FULL';
    case Cross = 'CROSS';
}
