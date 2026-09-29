<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Metadata;

use Closure;
use Quantum\Database\ORM\Attributes\Column;
use Quantum\Database\ORM\Attributes\Embedded;
use Quantum\Database\ORM\Attributes\Entity;
use Quantum\Database\ORM\Attributes\Id;
use Quantum\Database\ORM\Attributes\ManyToOne;
use Quantum\Database\ORM\Attributes\OneToMany;
use Quantum\Database\ORM\Attributes\PostLoad;
use Quantum\Database\ORM\Attributes\PostPersist;
use Quantum\Database\ORM\Attributes\PostRemove;
use Quantum\Database\ORM\Attributes\PostUpdate;
use Quantum\Database\ORM\Attributes\PrePersist;
use Quantum\Database\ORM\Attributes\PreRemove;
use Quantum\Database\ORM\Attributes\PreUpdate;
use Quantum\Database\ORM\Attributes\Table;
use Quantum\Database\ORM\Contracts\EntityLifecycleListenerInterface;
use Quantum\Database\ORM\CustomRepositoryRegistry;
use Quantum\Database\ORM\Model;
use Quantum\Database\ORM\Types\TypeRegistry;
use BackedEnum;
use DateTimeImmutable;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

final class EntityMetadataRegistry
{
    /**
     * @var array<class-string, EntityMetadata>
     */
    private array $metadata = [];

    public function __construct(
        private readonly ?TypeRegistry $types = null,
        private readonly ?CustomRepositoryRegistry $customRepositories = null,
    ) {
    }

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
        $associationProperties = [];
        $embeddedProperties = [];

        foreach ($reflection->getProperties() as $property) {
            $field = $this->mapProperty($property);

            if ($field !== null) {
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

            if (
                $property->getAttributes(ManyToOne::class, ReflectionAttribute::IS_INSTANCEOF) !== []
                || $property->getAttributes(OneToMany::class, ReflectionAttribute::IS_INSTANCEOF) !== []
            ) {
                $associationProperties[] = $property;
            }

            if ($property->getAttributes(Embedded::class, ReflectionAttribute::IS_INSTANCEOF) !== []) {
                $embeddedProperties[] = $property;
            }
        }

        if ($identifier === null) {
            throw new RuntimeException(sprintf(
                'Entity [%s] must declare one #[Id] field.',
                $entityClass,
            ));
        }

        $embeddeds = [];
        foreach ($embeddedProperties as $property) {
            $embedded = $this->mapEmbedded($property);
            if ($embedded !== null) {
                $embeddeds[$embedded->name] = $embedded;
            }
        }

        $lifecycleCallbacks = $this->buildLifecycleCallbacks($reflection, $entityAttribute?->lifecycleListeners);

        $shell = new EntityMetadata(
            className: $entityClass,
            table: $table,
            fields: $fields,
            identifier: $identifier,
            associations: [],
            embeddeds: $embeddeds,
            repositoryClass: $this->resolveRepositoryClass($entityClass, $entityAttribute?->repository),
            lifecycleCallbacks: $lifecycleCallbacks,
        );
        $this->metadata[$entityClass] = $shell;

        $associations = [];
        foreach ($associationProperties as $property) {
            $association = $this->mapAssociation($property, $entityClass);
            if ($association !== null) {
                $associations[$association->name] = $association;
            }
        }

        $final = new EntityMetadata(
            className: $entityClass,
            table: $table,
            fields: $fields,
            identifier: $identifier,
            associations: $associations,
            embeddeds: $embeddeds,
            repositoryClass: $this->resolveRepositoryClass($entityClass, $entityAttribute?->repository),
            lifecycleCallbacks: $lifecycleCallbacks,
        );
        $this->metadata[$entityClass] = $final;

        return $final;
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
        $type = $this->resolveType($property, $column);
        $enumClass = $this->resolveEnumClass($property, $column, $type);

        return new EntityFieldMetadata(
            name: $property->getName(),
            column: $column?->name !== null && trim($column->name) !== ''
                ? trim($column->name)
                : $this->toSnakeCase($property->getName()),
            property: $property,
            type: $type,
            enumClass: $enumClass,
            typeHandler: $type !== null ? $this->typeRegistry()->for($type) : null,
            identifier: $hasId,
        );
    }

    private function resolveType(ReflectionProperty $property, ?Column $column): ?string
    {
        $explicit = $column?->type !== null && trim($column->type) !== ''
            ? trim($column->type)
            : null;

        if ($explicit !== null) {
            return $explicit;
        }

        $phpType = $this->propertyType($property);

        if ($phpType === null) {
            return null;
        }

        if (enum_exists($phpType) && is_subclass_of($phpType, BackedEnum::class)) {
            return 'enum';
        }

        return match ($phpType) {
            'int', 'float', 'bool', 'string' => $phpType,
            'array' => 'json',
            DateTimeImmutable::class => 'datetime_immutable',
            default => null,
        };
    }

    /**
     * @param class-string<BackedEnum>|null $enumClass
     * @return class-string<BackedEnum>|null
     */
    private function resolveEnumClass(ReflectionProperty $property, ?Column $column, ?string $type): ?string
    {
        $enumClass = $column?->enumType;

        if ($enumClass === null && $type === 'enum') {
            $phpType = $this->propertyType($property);
            $enumClass = is_string($phpType) && enum_exists($phpType) ? $phpType : null;
        }

        if ($enumClass === null) {
            return null;
        }

        if (! enum_exists($enumClass) || ! is_subclass_of($enumClass, BackedEnum::class)) {
            throw new RuntimeException(sprintf(
                'Field [%s::%s] must declare a valid backed enum class.',
                $property->getDeclaringClass()->getName(),
                $property->getName(),
            ));
        }

        return $enumClass;
    }

    private function propertyType(ReflectionProperty $property): ?string
    {
        $type = $property->getType();

        if (! $type instanceof \ReflectionNamedType) {
            return null;
        }

        return $type->getName();
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

    private function typeRegistry(): TypeRegistry
    {
        return $this->types ?? new TypeRegistry();
    }

    private function mapAssociation(ReflectionProperty $property, string $declaringEntity): ?EntityAssociationMetadata
    {
        $manyToOneAttrs = $property->getAttributes(ManyToOne::class, ReflectionAttribute::IS_INSTANCEOF);
        $oneToManyAttrs = $property->getAttributes(OneToMany::class, ReflectionAttribute::IS_INSTANCEOF);

        if ($manyToOneAttrs === [] && $oneToManyAttrs === []) {
            return null;
        }

        $propertyName = $property->getName();

        if ($manyToOneAttrs !== []) {
            /** @var ManyToOne $attr */
            $attr = $manyToOneAttrs[0]->newInstance();
            $target = $attr->targetEntity;

            if (! $this->validateEntityTarget($target, $declaringEntity, $propertyName)) {
                return null;
            }

            $sourceField = $this->guessManyToOneSourceField($propertyName, $attr);
            $targetMetadata = $this->for($target);
            $targetColumn = $attr->referencedColumn !== null && trim($attr->referencedColumn) !== ''
                ? trim($attr->referencedColumn)
                : $targetMetadata->identifier->column;

            return new EntityAssociationMetadata(
                name: $propertyName,
                kind: EntityAssociationMetadata::KIND_MANY_TO_ONE,
                targetEntity: $target,
                property: $property,
                sourceField: $sourceField,
                sourceColumn: $attr->joinColumn !== null && trim($attr->joinColumn) !== ''
                    ? trim($attr->joinColumn)
                    : $this->guessManyToOneJoinColumn($sourceField),
                targetField: $targetMetadata->identifier->name,
                targetColumn: $targetColumn,
                inversedBy: $attr->inversedBy,
                cascade: $attr->cascade,
            );
        }

        /** @var OneToMany $attr */
        $attr = $oneToManyAttrs[0]->newInstance();
        $target = $attr->targetEntity;

        if (! $this->validateEntityTarget($target, $declaringEntity, $propertyName)) {
            return null;
        }

        $targetMetadata = $this->for($target);
        $mappedBy = $attr->mappedBy;

        if (! $targetMetadata->hasAssociation($mappedBy)) {
            throw new RuntimeException(sprintf(
                'Entity [%s] association [%s::$%s] maps to [%s::$%s], but target has no such association.',
                $declaringEntity,
                $declaringEntity,
                $propertyName,
                $target,
                $mappedBy,
            ));
        }

        $mappedByAssociation = $targetMetadata->association($mappedBy);

        return new EntityAssociationMetadata(
            name: $propertyName,
            kind: EntityAssociationMetadata::KIND_ONE_TO_MANY,
            targetEntity: $target,
            property: $property,
            sourceField: $mappedByAssociation->targetField,
            sourceColumn: $mappedByAssociation->targetColumn,
            targetField: $mappedByAssociation->sourceField,
            targetColumn: $mappedByAssociation->sourceColumn,
            mappedBy: $mappedBy,
            cascade: $attr->cascade,
            orphanRemoval: $attr->orphanRemoval,
        );
    }

    private function validateEntityTarget(string $target, string $declaringEntity, string $propertyName): bool
    {
        if (! class_exists($target)) {
            throw new RuntimeException(sprintf(
                'Entity [%s] association [%s::$%s] references unknown target entity [%s].',
                $declaringEntity,
                $declaringEntity,
                $propertyName,
                $target,
            ));
        }

        if (! $this->has($target)) {
            throw new RuntimeException(sprintf(
                'Entity [%s] association [%s::$%s] targets [%s], which is not a valid ORM entity.',
                $declaringEntity,
                $declaringEntity,
                $propertyName,
                $target,
            ));
        }

        return true;
    }

    private function guessManyToOneSourceField(string $associationName, ManyToOne $attr): string
    {
        if ($attr->joinColumn !== null && trim($attr->joinColumn) !== '') {
            $snake = trim($attr->joinColumn);
            $candidate = $this->snakeToCamel($snake);
            if (str_ends_with($candidate, 'Id') && $candidate !== 'Id') {
                return substr($candidate, 0, -2);
            }
        }

        return $associationName;
    }

    private function guessManyToOneJoinColumn(string $sourceField): string
    {
        return $this->toSnakeCase($sourceField) . '_id';
    }

    private function snakeToCamel(string $value): string
    {
        return lcfirst(str_replace('_', '', ucwords($value, '_')));
    }

    private function mapEmbedded(ReflectionProperty $property): ?EntityEmbeddedMetadata
    {
        $attributes = $property->getAttributes(Embedded::class, ReflectionAttribute::IS_INSTANCEOF);

        if ($attributes === []) {
            return null;
        }

        /** @var Embedded $attr */
        $attr = $attributes[0]->newInstance();
        $propertyName = $property->getName();
        $embeddableClass = $attr->class;

        if (! class_exists($embeddableClass)) {
            throw new RuntimeException(sprintf(
                'Entity [%s] declares embedded [%s] with unknown class [%s].',
                $property->getDeclaringClass()->getName(),
                $propertyName,
                $embeddableClass,
            ));
        }

        $prefix = $attr->prefix !== null && trim($attr->prefix) !== ''
            ? trim($attr->prefix)
            : $this->defaultEmbeddedPrefix($propertyName);

        $embeddableReflection = new ReflectionClass($embeddableClass);
        $innerFields = [];

        foreach ($embeddableReflection->getProperties() as $innerProperty) {
            $innerField = $this->mapEmbeddedProperty($innerProperty, $prefix);

            if ($innerField === null) {
                continue;
            }

            if (isset($innerFields[$innerField->name])) {
                throw new RuntimeException(sprintf(
                    'Embedded [%s::$%s] of class [%s] declares duplicate inner field [%s].',
                    $property->getDeclaringClass()->getName(),
                    $propertyName,
                    $embeddableClass,
                    $innerField->name,
                ));
            }

            $innerFields[$innerField->name] = $innerField;
        }

        if ($innerFields === []) {
            throw new RuntimeException(sprintf(
                'Embedded [%s::$%s] of class [%s] has no #[Column] fields declared.',
                $property->getDeclaringClass()->getName(),
                $propertyName,
                $embeddableClass,
            ));
        }

        return new EntityEmbeddedMetadata(
            name: $propertyName,
            embeddableClass: $embeddableClass,
            columnPrefix: $prefix,
            property: $property,
            innerFields: $innerFields,
        );
    }

    private function defaultEmbeddedPrefix(string $propertyName): string
    {
        return $this->toSnakeCase($propertyName) . '_';
    }

    private function mapEmbeddedProperty(ReflectionProperty $property, string $prefix): ?EntityEmbeddedFieldMetadata
    {
        $columnAttributes = $property->getAttributes(Column::class, ReflectionAttribute::IS_INSTANCEOF);

        if ($columnAttributes === []) {
            return null;
        }

        /** @var Column $column */
        $column = $columnAttributes[0]->newInstance();
        $type = $this->resolveEmbeddedType($property, $column);
        $enumClass = $this->resolveEnumClass($property, $column, $type);
        $baseColumn = $column->name !== null && trim($column->name) !== ''
            ? trim($column->name)
            : $this->toSnakeCase($property->getName());

        return new EntityEmbeddedFieldMetadata(
            name: $property->getName(),
            column: $prefix . $baseColumn,
            property: $property,
            type: $type,
            enumClass: $enumClass,
            typeHandler: $type !== null ? $this->typeRegistry()->for($type) : null,
        );
    }

    private function resolveEmbeddedType(ReflectionProperty $property, Column $column): ?string
    {
        $explicit = $column->type !== null && trim($column->type) !== ''
            ? trim($column->type)
            : null;

        if ($explicit !== null) {
            return $explicit;
        }

        $phpType = $this->propertyType($property);

        if ($phpType === null) {
            return null;
        }

        if (enum_exists($phpType) && is_subclass_of($phpType, BackedEnum::class)) {
            return 'enum';
        }

        return match ($phpType) {
            'int', 'float', 'bool', 'string' => $phpType,
            'array' => 'json',
            DateTimeImmutable::class => 'datetime_immutable',
            default => null,
        };
    }

    /**
     * @return array<class-string, string> Map of lifecycle attribute FQCN → canonical event name.
     */
    private function lifecycleAttributeMap(): array
    {
        return [
            PrePersist::class => 'prePersist',
            PostPersist::class => 'postPersist',
            PreUpdate::class => 'preUpdate',
            PostUpdate::class => 'postUpdate',
            PreRemove::class => 'preRemove',
            PostRemove::class => 'postRemove',
            PostLoad::class => 'postLoad',
        ];
    }

    /**
     * Build the lifecycle callbacks map for an entity.
     *
     * Produces a shape compatible with EntityMetadata::$lifecycleCallbacks:
     *   event-name → list<Closure(object $entity, EntityManagerInterface $em, array<string, mixed> $context): void>
     *
     * @param ReflectionClass<object>                                         $reflection
     * @param list<class-string<EntityLifecycleListenerInterface>>|null      $classListeners
     *
     * @return array<string, list<Closure>>
     */
    private function buildLifecycleCallbacks(ReflectionClass $reflection, ?array $classListeners): array
    {
        $result = [];
        foreach (EntityMetadata::KNOWN_LIFECYCLE_EVENTS as $event) {
            $result[$event] = [];
        }

        $attrMap = $this->lifecycleAttributeMap();

        // 1. Method-level lifecycle attributes declared on the entity itself.
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED | ReflectionMethod::IS_PRIVATE) as $method) {
            foreach ($attrMap as $attrClass => $eventName) {
                $attrs = $method->getAttributes($attrClass, ReflectionAttribute::IS_INSTANCEOF);
                if ($attrs === []) {
                    continue;
                }

                $method->setAccessible(true);
                $methodRef = $method;
                $result[$eventName][] = static function (object $entity, object $em, array $context = []) use ($methodRef): void {
                    $methodRef->invoke($entity);
                };
            }
        }

        // 2. Class-level external listeners declared via #[Entity(lifecycleListeners: [X::class])].
        $listeners = $classListeners ?? [];
        foreach ($listeners as $listenerClass) {
            if (! is_string($listenerClass) || ! class_exists($listenerClass)) {
                throw new RuntimeException(sprintf(
                    'Entity [%s] declares lifecycle listener [%s] that does not exist.',
                    $reflection->getName(),
                    is_string($listenerClass) ? $listenerClass : get_debug_type($listenerClass),
                ));
            }

            if (! is_subclass_of($listenerClass, EntityLifecycleListenerInterface::class)) {
                throw new RuntimeException(sprintf(
                    'Entity [%s] declares lifecycle listener [%s] that does not implement %s.',
                    $reflection->getName(),
                    $listenerClass,
                    EntityLifecycleListenerInterface::class,
                ));
            }

            $listenerInstance = new $listenerClass();
            foreach (EntityMetadata::KNOWN_LIFECYCLE_EVENTS as $eventName) {
                $result[$eventName][] = static function (object $entity, object $em, array $context = []) use ($listenerInstance, $eventName): void {
                    $listenerInstance->$eventName($entity, $em, $context);
                };
            }
        }

        return $result;
    }

    /**
     * @param class-string                 $entityClass
     * @param class-string|null            $fromEntityAttribute
     *
     * @return class-string|null
     */
    private function resolveRepositoryClass(string $entityClass, ?string $fromEntityAttribute): ?string
    {
        if ($fromEntityAttribute !== null && $fromEntityAttribute !== '') {
            return $fromEntityAttribute;
        }

        return $this->customRepositories?->repositoryFor($entityClass);
    }
}
