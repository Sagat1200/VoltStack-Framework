<?php

declare(strict_types=1);

namespace Quantum\Database\Orm\Mapping;

use Quantum\Database\Operation\Sqg\Enum\DataType;
use Quantum\Database\Orm\Metadata\CompiledPropertyMetadata;

/**
 * TypeSystem: casting bidireccional DB ↔ PHP siguiendo algoritmo 5.2 + invariante MAP-007.
 *
 * Operaciones:
 *   castDbToPhp(mixed $rawValue, CompiledPropertyMetadata $ctx): mixed
 *   castPhpToDb(mixed $phpValue, CompiledPropertyMetadata $ctx): mixed
 */
final class TypeSystem
{
    /**
     * Valor desde DB (raw) → valor PHP (según type de PropertyMetadata).
     *
     * @throws \ValueError si el casteo no es posible.
     */
    public static function castDbToPhp(mixed $rawValue, CompiledPropertyMetadata $ctx): mixed
    {
        // Backed/Unit enum prioridad alta incluso si dataType != Enum (ej: Varchar + enumClass)
        if ($ctx->enumClass !== null && $ctx->enumClass !== '' && enum_exists($ctx->enumClass) && !($rawValue instanceof \UnitEnum)) {
            return self::toEnum($rawValue, $ctx->enumClass);
        }

        $type = $ctx->type->type ?? DataType::Unknown;
        $nullable = $ctx->isNullable;

        if ($rawValue === null) {
            if ($nullable) return null;
            // Invariante MAP-006: PK null. Otros nulls también lo son.
            $msg = $ctx->isIdentifier
                ? "Hydration fatal: PK column '{$ctx->columnName}' is null but property isIdentifier && !nullable (corrupt data)"
                : "Column '{$ctx->columnName}' is not nullable but DB returned NULL";
            throw new \ValueError($msg);
        }

        return match ($type) {
            DataType::Bool => self::toBool($rawValue),
            DataType::Int2, DataType::Int4, DataType::Int8 => (int)$rawValue,
            DataType::Float4, DataType::Float8 => (float)$rawValue,
            DataType::Numeric => is_numeric($rawValue) && str_contains((string)$rawValue, '.') ? (float)$rawValue : $rawValue,
            DataType::Varchar, DataType::Char, DataType::Text, DataType::Xml => (string)$rawValue,
            DataType::ByteA, DataType::Blob => is_string($rawValue) ? $rawValue : throw new \ValueError("Expected binary string for column '{$ctx->columnName}'"),
            DataType::Date, DataType::Time, DataType::Timestamp, DataType::Timestamptz => self::toDateTimeImmutable($rawValue),
            DataType::Json, DataType::JsonB => self::fromJson($rawValue),
            DataType::Uuid => self::toUuidString($rawValue),
            DataType::Enum => self::toEnum($rawValue, $ctx->enumClass),
            default => $rawValue,
        };
    }

    /**
     * Valor PHP → DB bindable value (idempotente: toDb(toPhp(x)) === x salvo float rounding).
     */
    public static function castPhpToDb(mixed $phpValue, CompiledPropertyMetadata $ctx): mixed
    {
        if ($phpValue === null) return null;

        // Enum → backed value
        if ($phpValue instanceof \BackedEnum) {
            return $phpValue->value;
        }
        if ($phpValue instanceof \UnitEnum) {
            return $phpValue->name;
        }

        $type = $ctx->type->type ?? DataType::Unknown;

        return match (true) {
            $phpValue instanceof \DateTimeInterface => match ($type) {
                DataType::Date => $phpValue->format('Y-m-d'),
                DataType::Time => $phpValue->format('H:i:s'),
                default => $phpValue->format('Y-m-d H:i:s'),
            },
            $type === DataType::Bool => self::toDbBool($phpValue),
            $type === DataType::Json || $type === DataType::JsonB => self::toJson($phpValue),
            default => $phpValue,
        };
    }

    // ============================ INTERNAL ====================================

    private static function toBool(mixed $raw): bool
    {
        if (is_bool($raw)) return $raw;
        if (is_int($raw)) return $raw !== 0;
        if (is_float($raw)) return $raw !== 0.0;
        if (!is_string($raw)) return (bool)$raw;
        $lower = strtolower(trim($raw));
        return match ($lower) {
            '1', 'true', 't', 'yes', 'on' => true,
            '0', 'false', 'f', 'no', 'off', '' => false,
            default => (bool)$raw,
        };
    }

    private static function toDbBool(mixed $v): int|string|bool
    {
        if (is_bool($v)) return $v;
        return match (true) {
            in_array($v, ['1', 'true', 't', 'yes', 'on', 1, 1.0, 'y'], true) => true,
            default => false,
        };
    }

    private static function toDateTimeImmutable(mixed $raw): \DateTimeImmutable
    {
        if ($raw instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($raw);
        }
        $str = (string)$raw;
        $dt = \DateTimeImmutable::createFromFormat('!' . \DateTimeInterface::RFC3339, $str);
        if ($dt === false) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $str);
        }
        if ($dt === false) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $str);
        }
        if ($dt === false) {
            try {
                $dt = new \DateTimeImmutable($str);
            } catch (\Throwable) {
                throw new \ValueError("Cannot parse datetime value: {$str}");
            }
        }
        return $dt;
    }

    private static function fromJson(mixed $raw): mixed
    {
        if (is_array($raw) || is_object($raw)) return $raw;
        if ($raw === null) return null;
        $s = (string)$raw;
        if ($s === '') return null;
        try {
            return json_decode($s, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \ValueError("Invalid JSON: {$s}. {$e->getMessage()}", previous: $e);
        }
    }

    private static function toJson(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \ValueError("Cannot encode JSON: " . $e->getMessage(), previous: $e);
        }
    }

    private static function toUuidString(mixed $raw): string
    {
        if (is_object($raw) && method_exists($raw, '__toString')) {
            $str = (string)$raw;
        } else {
            $str = (string)$raw;
        }
        if ($raw instanceof \Stringable) $str = (string)$raw;
        if (preg_match('/^[0-9a-fA-F]{32}$/', $str)) {
            // Formato binario compacto: añadir guiones
            $str = substr($str, 0, 8) . '-' . substr($str, 8, 4) . '-' . substr($str, 12, 4) . '-' . substr($str, 16, 4) . '-' . substr($str, 20);
        }
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $str)) {
            throw new \ValueError("Invalid UUID string: {$str}");
        }
        return \strtolower($str);
    }

    /**
     * @param class-string<\BackedEnum>|null $enumClass
     */
    private static function toEnum(mixed $raw, ?string $enumClass): mixed
    {
        if ($enumClass === null || !enum_exists($enumClass)) {
            return $raw;
        }
        if (is_subclass_of($enumClass, \BackedEnum::class)) {
            return $enumClass::from($raw instanceof \Stringable ? (string)$raw : $raw);
        }
        if (is_a($enumClass, \UnitEnum::class, true)) {
            $cases = $enumClass::cases();
            $needle = (string)$raw;
            foreach ($cases as $c) {
                if ($c->name === $needle) return $c;
            }
        }
        return $raw;
    }
}