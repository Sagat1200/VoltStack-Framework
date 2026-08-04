<?php

declare(strict_types=1);

namespace Quantum\Metadata;

enum MetadataValueType: string
{
    case Mixed = 'mixed';
    case Array = 'array';
    case String = 'string';
    case Int = 'int';
    case Float = 'float';
    case Bool = 'bool';
}
