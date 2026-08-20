<?php

declare(strict_types=1);

namespace Quantum\Database\Orm\UnitOfWork\Enum;

/**
 * Estado de entidad en el IdentityMap / UnitOfWork.
 */
enum EntityState: string
{
    case NEW      = 'new';
    case MANAGED  = 'managed';
    case REMOVED  = 'removed';
    case DETACHED = 'detached';
    case LOADING  = 'loading';
    case FLUSHING = 'flushing';
}