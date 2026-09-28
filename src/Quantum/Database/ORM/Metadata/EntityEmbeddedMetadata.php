<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Metadata;

use ReflectionClass;
use ReflectionProperty;

final class EntityEmbeddedMetadata
{
    /**
     * @param array<string, EntityEmbeddedFieldMetadata> $innerFields
     * @param class-string $embeddableClass
     */
    public function __construct(
        public readonly string $name,
        public readonly string $embeddableClass,
        public readonly string $columnPrefix,
        public readonly ReflectionProperty $property,
        public readonly array $innerFields,
    ) {
        if (method_exists($this->property, 'setAccessible')) {
            $this->property->setAccessible(true);
        }
    }

    public function hasEmbedded(object $entity): bool
    {
        return $this->property->isInitialized($entity)
            && $this->property->getValue($entity) !== null;
    }

    public function getEmbedded(object $entity): ?object
    {
        if (! $this->property->isInitialized($entity)) {
            return null;
        }

        return $this->property->getValue($entity);
    }

    public function setEmbedded(object $entity, ?object $value): void
    {
        $this->property->setValue($entity, $value);
    }

    public function newEmbeddableInstance(): object
    {
        $reflection = new ReflectionClass($this->embeddableClass);

        return $reflection->newInstanceWithoutConstructor();
    }

    public function hasInnerField(string $name): bool
    {
        return isset($this->innerFields[$name]);
    }

    public function innerField(string $name): EntityEmbeddedFieldMetadata
    {
        if (! isset($this->innerFields[$name])) {
            throw new \RuntimeException(sprintf(
                'Embedded field [%s::$%s] does not define inner field [%s].',
                $this->name,
                $this->embeddableClass,
                $name,
            ));
        }

        return $this->innerFields[$name];
    }

    /**
     * @return list<EntityEmbeddedFieldMetadata>
     */
    public function mappedInnerFields(): array
    {
        return array_values($this->innerFields);
    }
}
