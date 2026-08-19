<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Metadata\Attribute;

use Quantum\Database\Orm\Metadata\FieldTypeSpec;

/**
 * #[Column] define el mapeo de una propiedad a una columna física.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class Column
{
    /**
     * @param FieldTypeSpec|string|null $type V1 acepta FieldTypeSpec VO o string (enum/legacy).
     *        Si null → inferir desde PHP reflection type.
     * @param ?class-string $enumClass para columnas backed-enum
     * @param ?class-string $customType custom type converter class
     */
    public function __construct(
        public ?string $name = null,
        public mixed $type = null,
        public bool $nullable = false,
        public bool $unique = false,
        public ?int $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
        public mixed $default = null,
        public bool $insertable = true,
        public bool $updatable = true,
        public ?string $enumClass = null,
        public ?string $customType = null,
    ) {}
}
