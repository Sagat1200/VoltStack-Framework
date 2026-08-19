<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Enum;

/**
 * Operadores de comparación binaria usados en Comparison/Expressions.
 */
enum BinaryOperator: string
{
    case Eq          = '=';
    case NotEq       = '<>';
    case Lt          = '<';
    case Lte         = '<=';
    case Gt          = '>';
    case Gte         = '>=';
    case AndAlso     = 'AND';
    case OrElse      = 'OR';
    case Concat      = '||';
    case Plus        = '+';
    case Minus       = '-';
    case Star        = '*';
    case Slash       = '/';
    case Percent     = '%';
    case Like        = 'LIKE';
    case ILike       = 'ILIKE';
    case SimilarTo   = 'SIMILAR TO';
    case BitAnd      = '&';
    case BitOr       = '|';
    case BitXor      = '#';
    case LeftShift   = '<<';
    case RightShift  = '>>';
}
