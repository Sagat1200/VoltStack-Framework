<?php declare(strict_types=1);

namespace Quantum\Database\Dialect\Enum;

enum OrderDirection: string
{
    case Asc  = 'ASC';
    case Desc = 'DESC';
}
