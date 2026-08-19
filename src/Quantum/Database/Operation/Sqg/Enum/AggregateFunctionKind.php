<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Enum;

/**
 * Funciones de agregación comunes (además FunctionCall permite funciones escalares genéricas).
 */
enum AggregateFunctionKind: string
{
    case Count = 'COUNT';
    case Sum = 'SUM';
    case Avg = 'AVG';
    case Min = 'MIN';
    case Max = 'MAX';
    case GroupConcat = 'GROUP_CONCAT';
    case StringAgg = 'STRING_AGG';
    case ArrayAgg = 'ARRAY_AGG';
    case JsonAgg = 'JSON_AGG';
    case JsonbAgg = 'JSONB_AGG';
    case CountStar = 'COUNT_STAR';
    case BoolAnd = 'BOOL_AND';
    case BoolOr = 'BOOL_OR';
    case BitAnd = 'BIT_AND';
    case BitOr = 'BIT_OR';
    case Every = 'EVERY';
}
