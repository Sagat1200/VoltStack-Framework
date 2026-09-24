<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Metadata;

use ReflectionNamedType;
use ReflectionProperty;
use Quantum\Database\ORM\Types\Contracts\TypeHandlerInterface;

final class EntityFieldMetadata
{
    /**
     * @param class-string<\BackedEnum>|null $enumClass
     */
    public function __construct(
        public readonly string $name,
        public readonly string $column,
        public readonly ReflectionProperty $property,
        public readonly ?string $type = null,
        public readonly ?string $enumClass = null,
        private readonly ?TypeHandlerInterface $typeHandler = null,
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

    public function databaseValue(object $entity): mixed
    {
        return $this->databaseValueFrom($this->getValue($entity));
    }

    public function databaseValueFrom(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($this->typeHandler === null) {
            return $value;
        }

        return $this->typeHandler->toDatabase($value, $this);
    }

    public function castValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($this->typeHandler !== null) {
            return $this->typeHandler->toPhp($value, $this);
        }

        return $value;
    }
}
