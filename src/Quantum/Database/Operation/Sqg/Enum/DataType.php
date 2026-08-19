<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Enum;

/**
 * Tipos de dato portátiles del SQG.
 * Mapping db→php se completa en la capa Mapping (DDD-V1-05).
 */
enum DataType: string
{
    case Bool      = 'bool';
    case Int2      = 'int2';
    case Int4      = 'int4';
    case Int8      = 'int8';
    case Float4    = 'float4';
    case Float8    = 'float8';
    case Numeric   = 'numeric';
    case Text      = 'text';
    case Varchar   = 'varchar';
    case Char      = 'char';
    case ByteA     = 'bytea';
    case Blob      = 'blob';
    case Date      = 'date';
    case Time      = 'time';
    case Timestamp = 'timestamp';
    case Timestamptz = 'timestamptz';
    case Interval  = 'interval';
    case Json      = 'json';
    case JsonB     = 'jsonb';
    case Xml       = 'xml';
    case Uuid      = 'uuid';
    case ArrayInt  = 'array_int';
    case ArrayText = 'array_text';
    case Enum      = 'enum';
    case Unknown   = 'unknown';
}
