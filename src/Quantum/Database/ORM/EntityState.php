<?php

declare(strict_types=1);

namespace Quantum\Database\ORM;

enum EntityState: string
{
    case New = 'new';
    case Managed = 'managed';
    case Removed = 'removed';
    case Detached = 'detached';
}
