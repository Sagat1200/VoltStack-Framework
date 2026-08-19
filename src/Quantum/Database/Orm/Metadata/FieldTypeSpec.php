<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Metadata;

use Quantum\Database\Operation\Sqg\Enum\DataType;

/**
 * Value Object: DataType con length/precision/scale. Usado internamente en
 * CompiledPropertyMetadata y en el Attribute Column para tipado de columnas
 * con dimensión (varchar(255), numeric(10,2), etc.).
 *
 * Inmutable. Serializa nativamente (readonly).
 */
final readonly class FieldTypeSpec implements \Stringable
{
    public function __construct(
        public DataType $type,
        public ?int $length    = null,
        public ?int $precision = null,
        public ?int $scale     = null,
        public ?array $enumValues = null, // para DataType::Varchar enum-backed
    ) {}

    /**
     * Helper constructores named-style fluentes.
     */
    public static function varchar(int $length = 255): self { return new self(type: DataType::Varchar, length: $length); }
    public static function char(int $length = 1): self     { return new self(type: DataType::Char, length: $length); }
    public static function bigint(): self                   { return new self(type: DataType::Int8); }
    public static function integer(): self                  { return new self(type: DataType::Int4); }
    public static function smallint(): self                 { return new self(type: DataType::Int2); }
    public static function boolean(): self                  { return new self(type: DataType::Bool); }
    public static function double(): self                   { return new self(type: DataType::Float8); }
    public static function float(): self                    { return new self(type: DataType::Float4); }
    public static function numeric(int $precision = 18, int $scale = 2): self { return new self(type: DataType::Numeric, precision: $precision, scale: $scale); }
    public static function text(): self                     { return new self(type: DataType::Text); }
    public static function json(): self                     { return new self(type: DataType::Jsonb); }
    public static function date(): self                     { return new self(type: DataType::Date); }
    public static function datetime(): self                 { return new self(type: DataType::Timestamp); }
    public static function timestamptz(): self              { return new self(type: DataType::TimestampTz); }
    public static function uuid(): self                     { return new self(type: DataType::Uuid); }
    public static function blob(): self                     { return new self(type: DataType::Bytea); }

    public function withNullable(bool $nullable): self
    {
        // no-op aquí: nullable es un flag de CompiledPropertyMetadata. Permite fluent chaining en atributos.
        return $this;
    }

    public function __toString(): string
    {
        $t = $this->type->value;
        if ($this->length !== null) {
            return "{$t}({$this->length})";
        }
        if ($this->precision !== null && $this->scale !== null) {
            return "{$t}({$this->precision},{$this->scale})";
        }
        if ($this->precision !== null) {
            return "{$t}({$this->precision})";
        }
        return $t;
    }
}
