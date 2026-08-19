<?php

declare(strict_types=1);

namespace Quantum\Database\Query\Enum;

/**
 * Tipos JOIN para el Query Builder. Incluye variante LATERAL para PgSQL.
 */
enum JoinType: string
{
    case Inner       = 'INNER';
    case Left        = 'LEFT';
    case Right       = 'RIGHT';
    case FullOuter   = 'FULL OUTER';
    case Cross       = 'CROSS';
    case LeftLateral = 'LEFT LATERAL';
}