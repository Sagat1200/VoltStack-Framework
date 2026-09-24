<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Types;

use BackedEnum;
use Quantum\Database\ORM\Metadata\EntityFieldMetadata;
use Quantum\Database\ORM\Types\Contracts\TypeHandlerInterface;
use RuntimeException;

final readonly class BackedEnumTypeHandler implements TypeHandlerInterface
{
    public function id(): string
    {
        return 'enum';
    }

    public function toPhp(mixed $value, EntityFieldMetadata $field): mixed
    {
        if ($value === null) {
            return null;
        }

        $enumClass = $field->enumClass;

        if ($enumClass === null) {
            throw new RuntimeException(sprintf(
                'Field [%s] requires an enum class declaration.',
                $field->name,
            ));
        }

        if ($value instanceof $enumClass) {
            return $value;
        }

        if (! is_subclass_of($enumClass, BackedEnum::class)) {
            throw new RuntimeException(sprintf(
                'Enum field [%s] must use a backed enum class.',
                $field->name,
            ));
        }

        return $enumClass::from($value);
    }

    public function toDatabase(mixed $value, EntityFieldMetadata $field): mixed
    {
        if ($value === null) {
            return null;
        }

        $enum = $this->toPhp($value, $field);

        if (! $enum instanceof BackedEnum) {
            throw new RuntimeException(sprintf(
                'Field [%s] expects a backed enum value.',
                $field->name,
            ));
        }

        return $enum->value;
    }
}
