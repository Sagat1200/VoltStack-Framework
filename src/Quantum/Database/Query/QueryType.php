<?php

declare(strict_types=1);

namespace Quantum\Database\Query;

enum QueryType: string
{
    case Select = 'select';
    case Insert = 'insert';
    case Update = 'update';
    case Delete = 'delete';
}
