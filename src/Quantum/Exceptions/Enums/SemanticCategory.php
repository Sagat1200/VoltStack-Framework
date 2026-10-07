<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Enums;

enum SemanticCategory: string
{
    case Validation = 'validation';
    case Authentication = 'authentication';
    case Authorization = 'authorization';
    case NotFound = 'not_found';
    case Conflict = 'conflict';
    case Throttled = 'throttled';
    case Dependency = 'dependency';
    case Configuration = 'configuration';
    case Cancelled = 'cancelled';
    case Internal = 'internal';
}
