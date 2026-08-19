<?php

declare(strict_types=1);

namespace Quantum\Database\Dbal\Enum;

/**
 * Tipos de parámetro para bindings.
 * V1: soporta los 6 tipos más utilizados; tipos BLOB/lob se manejan vía streams/lob.
 */
enum ParamType: int
{
    case Auto = 0;
    case Null = 1;
    case Int  = 2;
    case Str  = 3;
    case Bool = 4;
    case LOB  = 5;
}