<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Enum;

enum UnaryOperator: string
{
    case Not = 'NOT';
    case Neg = '-';
    case Pos = '+';
    case BitNot = '~';
}
