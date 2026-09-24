<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Metadata;

use ReflectionNamedType;
use ReflectionProperty;

final class EntityFieldMetadata
{
    public function __construct(
        public readonly string $name,
        public readonly string $column,
        public readonly ReflectionProperty $property,
        public readonly bool $identifier = false,
    ) {
        if (method_exists($this->property, 'setAccessible')) {
            $this->property->setAccessible(true);
        }
    }

    public function phpType(): ?string
    {
        $type = $this->property->getType();

        if (! $type instanceof ReflectionNamedType) {
            return null;
        }

        return $type->getName();
    }

    public function hasValue(object $entity): bool
    {
        return $this->property->isInitialized($entity);
    }

    public function getValue(object $entity): mixed
    {
        return $this->property->isInitialized($entity)
            ? $this->property->getValue($entity)
            : null;
    }

    public function setValue(object $entity, mixed $value): void
    {
        $this->property->setValue($entity, $this->castValue($value));
    }

    public function castValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($this->phpType()) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? (bool) $value,
            'string' => (string) $value,
            default => $value,
        };
    }
}
