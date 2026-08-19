<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Enum;

enum SortNulls: string
{
    case Default  = 'default';
    case First    = 'FIRST';
    case Last     = 'LAST';
}
