<?php declare(strict_types=1);

namespace Quantum\Database\Dbal\Enum;

/**
 * Modo fetch de filas. Por defecto Assoc.
 */
enum FetchMode: int
{
    case Assoc  = 0;
    case Num    = 1;
    case Both   = 2;
    case Object = 3;
    case Column = 4;
}
