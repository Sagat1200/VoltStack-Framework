<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Metadata;

use Quantum\Database\ORM\Types\Contracts\TypeHandlerInterface;
use ReflectionProperty;

final class EntityEmbeddedFieldMetadata implements EntityTypedFieldInterface
{
    public function __construct(
        public readonly string $name,
        public readonly string $column,
        public readonly ReflectionProperty $property,
        public readonly ?string $type = null,
        public readonly ?string $enumClass = null,
        private readonly ?TypeHandlerInterface $typeHandler = null,
    ) {
        if (method_exists($this->property, 'setAccessible')) {
            $this->property->setAccessible(true);
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    public function column(): string
    {
        return $this->column;
    }

    public function type(): ?string
    {
        return $this->type;
    }

    public function enumClass(): ?string
    {
        return $this->enumClass;
    }

    public function hasValue(object $embeddable): bool
    {
        return $this->property->isInitialized($embeddable);
    }

    public function getValue(object $embeddable): mixed
    {
        return $this->property->isInitialized($embeddable)
            ? $this->property->getValue($embeddable)
            : null;
    }

    public function setValue(object $embeddable, mixed $value): void
    {
        $this->property->setValue($embeddable, $this->castValue($value));
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
