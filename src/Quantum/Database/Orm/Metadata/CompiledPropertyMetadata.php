<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Metadata;

use Quantum\Database\Orm\Metadata\FieldTypeSpec;

/**
 * Metadata compilada de 1 propiedad columnar (no asociación).
 * INMUTABLE, cacheable via serialize().
 */
final readonly class CompiledPropertyMetadata
{
    public function __construct(
        public string $propertyName,
        public string $columnName,
        public FieldTypeSpec $type,
        public bool $isNullable,
        public bool $isIdentifier,
        public bool $isInsertable,
        public bool $isUpdatable,
        public bool $isUnique,
        public mixed $defaultValue = null,
        public ?string $enumClass = null,
        public bool $isGenerated = false,
        public ?string $customTypeClass = null,
        public PropertyAccessInfo $access,
    ) {}
}
