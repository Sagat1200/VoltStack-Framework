<?php

declare(strict_types=1);

namespace Quantum\Cache;

enum Effect: string
{
    case Applied = 'applied';
    case Rejected = 'rejected';
    case Unknown = 'unknown';
}
