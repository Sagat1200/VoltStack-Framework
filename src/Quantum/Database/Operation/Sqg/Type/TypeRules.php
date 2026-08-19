<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Type;

use Quantum\Database\Operation\Sqg\Enum\DataType;

/**
 * Inferencia de tipos por familia binaria/unaria (helper V1).
 */
final class TypeRules
{
    public static function inferBinary(DataType $l, DataType $r): DataType
    {
        $a = [$l, $r];
        if (in_array(DataType::Float64, $a, true)) return DataType::Float64;
        if (in_array(DataType::Float32, $a, true)) return DataType::Float32;
        if (in_array(DataType::Int64, $a, true)) return DataType::Int64;
        if (in_array(DataType::Int32, $a, true)) return DataType::Int32;
        if (in_array(DataType::Int16, $a, true)) return DataType::Int16;
        if (in_array(DataType::Decimal, $a, true)) return DataType::Decimal;
        if (in_array(DataType::Utf8Text, $a, true)) return DataType::Utf8Text;
        if (in_array(DataType::Blob, $a, true)) return DataType::Blob;
        return DataType::Unknown;
    }

    public static function inferComparison(DataType $l, DataType $r): DataType
    {
        return DataType::Boolean;
    }

    public static function inferAggregate(DataType $argType, \Quantum\Database\Operation\Sqg\Enum\AggregateFunctionKind $fn): DataType
    {
        return match($fn) {
            \Quantum\Database\Operation\Sqg\Enum\AggregateFunctionKind::Count,
            \Quantum\Database\Operation\Sqg\Enum\AggregateFunctionKind::CountDistinct
                => DataType::Int64,
            \Quantum\Database\Operation\Sqg\Enum\AggregateFunctionKind::Sum => $argType === DataType::Unknown ? DataType::Decimal : $argType,
            \Quantum\Database\Operation\Sqg\Enum\AggregateFunctionKind::Avg => DataType::Decimal,
            \Quantum\Database\Operation\Sqg\Enum\AggregateFunctionKind::Min,
            \Quantum\Database\Operation\Sqg\Enum\AggregateFunctionKind::Max => $argType,
            default => DataType::Unknown,
        };
    }
}
