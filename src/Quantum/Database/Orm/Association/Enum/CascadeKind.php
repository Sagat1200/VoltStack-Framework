<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Association\Enum;

/**
 * Cascadas permitidas en associations. 'ALL' incluye las 5.
 */
enum CascadeKind: string
{
    case Persist = 'PERSIST';
    case Remove  = 'REMOVE';
    case Merge   = 'MERGE';
    case Detach  = 'DETACH';
    case Refresh = 'REFRESH';
    case All     = 'ALL';
}
