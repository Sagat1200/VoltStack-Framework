<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Metadata;

use Quantum\Database\ORM\Attributes\Column;
use Quantum\Database\ORM\Attributes\Entity;
use Quantum\Database\ORM\Attributes\Id;
use Quantum\Database\ORM\Attributes\Table;
use Quantum\Database\ORM\Model;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

final class EntityMetadataRegistry
{
    /**
     * @var array<class-string, EntityMetadata>
     */
    private array $metadata = [];

    public function has(string $entityClass): bool
    {
        return class_exists($entityClass)
            && ($this->isEntityClass($entityClass) || is_subclass_of($entityClass, Model::class));
    }

    public function for(string $entityClass): EntityMetadata
    {
        if (isset($this->metadata[$entityClass])) {
            return $this->metadata[$entityClass];
        }

        if (! class_exists($entityClass)) {
            throw new RuntimeException(sprintf('Entity class [%s] does not exist.', $entityClass));
        }

        return $this->metadata[$entityClass] = $this->build($entityClass);
    }

    private function build(string $entityClass): EntityMetadata
    {
        $reflection = new ReflectionClass($entityClass);
        $entityAttribute = $this->entityAttribute($reflection);

        if ($entityAttribute === null && ! $reflection->isSubclassOf(Model::class)) {
            throw new RuntimeException(sprintf(
                'Entity class [%s] must declare #[Entity].',
                $entityClass,
            ));
        }

        $table = $this->resolveTable($reflection);
        $fields = [];
        $identifier = null;

        foreach ($reflection->getProperties() as $property) {
            $field = $this->mapProperty($property);

            if ($field === null) {
                continue;
            }

            $fields[$field->name] = $field;

            if ($field->identifier) {
                if ($identifier !== null) {
                    throw new RuntimeException(sprintf(
                        'Entity [%s] declares more than one identifier field.',
                        $entityClass,
                    ));
                }

                $identifier = $field;
            }
        }

        if ($identifier === null) {
            throw new RuntimeException(sprintf(
                'Entity [%s] must declare one #[Id] field.',
                $entityClass,
            ));
        }

        return new EntityMetadata(
            className: $entityClass,
            table: $table,
            fields: $fields,
            identifier: $identifier,
            repositoryClass: $entityAttribute?->repository,
        );
    }

    private function mapProperty(ReflectionProperty $property): ?EntityFieldMetadata
    {
        $hasId = $property->getAttributes(Id::class, ReflectionAttribute::IS_INSTANCEOF) !== [];
        $columnAttributes = $property->getAttributes(Column::class, ReflectionAttribute::IS_INSTANCEOF);

        if (! $hasId && $columnAttributes === []) {
            return null;
        }

        /** @var Column|null $column */
        $column = $columnAttributes !== []
            ? $columnAttributes[0]->newInstance()
            : null;

        return new EntityFieldMetadata(
            name: $property->getName(),
            column: $column?->name !== null && trim($column->name) !== ''
                ? trim($column->name)
                : $this->toSnakeCase($property->getName()),
            property: $property,
            identifier: $hasId,
        );
    }

    private function resolveTable(ReflectionClass $reflection): string
    {
        $attributes = $reflection->getAttributes(Table::class, ReflectionAttribute::IS_INSTANCEOF);

        if ($attributes !== []) {
            /** @var Table $table */
            $table = $attributes[0]->newInstance();

            return trim($table->name);
        }

        if ($reflection->isSubclassOf(Model::class) && $reflection->hasProperty('table')) {
            $property = $reflection->getProperty('table');
            if (method_exists($property, 'setAccessible')) {
                $property->setAccessible(true);
            }

            $value = $property->isStatic() ? $property->getValue() : null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $this->defaultTableName($reflection->getShortName());
    }

    private function entityAttribute(ReflectionClass $reflection): ?Entity
    {
        $attributes = $reflection->getAttributes(Entity::class, ReflectionAttribute::IS_INSTANCEOF);

        if ($attributes === []) {
            return null;
        }

        return $attributes[0]->newInstance();
    }

    private function isEntityClass(string $entityClass): bool
    {
        $reflection = new ReflectionClass($entityClass);

        return $reflection->getAttributes(Entity::class, ReflectionAttribute::IS_INSTANCEOF) !== [];
    }

    private function defaultTableName(string $className): string
    {
        return $this->toSnakeCase($className) . 's';
    }

    private function toSnakeCase(string $value): string
    {
        $normalized = preg_replace('/(?<!^)[A-Z]/', '_$0', $value);

        return strtolower($normalized ?? $value);
    }
}
