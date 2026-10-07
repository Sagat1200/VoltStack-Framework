<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Enums;

enum Effect: string
{
    case None = 'none';
    case Committed = 'committed';
    case Partial = 'partial';
    case Unknown = 'unknown';
}
