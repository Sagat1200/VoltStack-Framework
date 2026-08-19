<?php declare(strict_types=1);

namespace Quantum\Database\Query\Enum;

/**
 * Dirección de ORDER BY (DBAL fluent level, usada internamente).
 */
enum Order: string
{
    case Asc  = 'ASC';
    case Desc = 'DESC';
}
