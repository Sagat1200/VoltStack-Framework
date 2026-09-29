<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Metadata;

use Closure;
use Quantum\Database\ORM\EntityKey;
use ReflectionClass;
use RuntimeException;

final class EntityMetadata
{
    /**
     * Canonical lifecycle event names supported by the ORM in V1.
     *
     * @var array<string, list<Closure>>
     */
    private const DEFAULT_LIFECYCLE_CALLBACKS = [
        'prePersist' => [],
        'postPersist' => [],
        'preUpdate' => [],
        'postUpdate' => [],
        'preRemove' => [],
        'postRemove' => [],
        'postLoad' => [],
    ];

    public const KNOWN_LIFECYCLE_EVENTS = [
        'prePersist',
        'postPersist',
        'preUpdate',
        'postUpdate',
        'preRemove',
        'postRemove',
        'postLoad',
    ];

    /**
     * @param array<string, EntityFieldMetadata>             $fields
     * @param array<string, EntityAssociationMetadata>       $associations
     * @param array<string, EntityEmbeddedMetadata>          $embeddeds
     * @param array<string, list<Closure>>                   $lifecycleCallbacks Map of event-name → list of Closures bound to the entity instance at dispatch-time.
     */
    public function __construct(
        public readonly string $className,
        public readonly string $table,
        public readonly array $fields,
        public readonly EntityFieldMetadata $identifier,
        public readonly array $associations = [],
        public readonly array $embeddeds = [],
        public readonly ?string $repositoryClass = null,
        public readonly array $lifecycleCallbacks = self::DEFAULT_LIFECYCLE_CALLBACKS,
    ) {
        $unknownEvents = array_diff(array_keys($lifecycleCallbacks), self::KNOWN_LIFECYCLE_EVENTS);
        if ($unknownEvents !== []) {
            throw new RuntimeException(sprintf(
                'Entity [%s] declares unknown lifecycle events: %s. Allowed: %s.',
                $className,
                implode(', ', $unknownEvents),
                implode(', ', self::KNOWN_LIFECYCLE_EVENTS),
            ));
        }
    }

    public function hasCallbacks(string $event): bool
    {
        return isset($this->lifecycleCallbacks[$event]) && $this->lifecycleCallbacks[$event] !== [];
    }

    /**
     * @return list<Closure>
     */
    public function callbacksFor(string $event): array
    {
        if (! in_array($event, self::KNOWN_LIFECYCLE_EVENTS, true)) {
            throw new RuntimeException(sprintf(
                'Unknown lifecycle event [%s] for entity [%s]. Allowed: %s.',
                $event,
                $this->className,
                implode(', ', self::KNOWN_LIFECYCLE_EVENTS),
            ));
        }

        return $this->lifecycleCallbacks[$event] ?? [];
    }

    /**
     * @return list<EntityFieldMetadata>
     */
    public function mappedFields(): array
    {
        return array_values($this->fields);
    }

    public function field(string $name): EntityFieldMetadata
    {
        if (! isset($this->fields[$name])) {
            throw new RuntimeException(sprintf(
                'Field [%s] is not mapped for entity [%s].',
                $name,
                $this->className,
            ));
        }

        return $this->fields[$name];
    }

    public function hasField(string $name): bool
    {
        return isset($this->fields[$name]);
    }

    /**
     * @return list<EntityAssociationMetadata>
     */
    public function associations(): array
    {
        return array_values($this->associations);
    }

    public function association(string $name): EntityAssociationMetadata
    {
        if (! isset($this->associations[$name])) {
            throw new RuntimeException(sprintf(
                'Association [%s] is not mapped for entity [%s].',
                $name,
                $this->className,
            ));
        }

        return $this->associations[$name];
    }

    public function hasAssociation(string $name): bool
    {
        return isset($this->associations[$name]);
    }

    /**
     * @return list<EntityEmbeddedMetadata>
     */
    public function embeddeds(): array
    {
        return array_values($this->embeddeds);
    }

    public function embedded(string $name): EntityEmbeddedMetadata
    {
        if (! isset($this->embeddeds[$name])) {
            throw new RuntimeException(sprintf(
                'Embedded [%s] is not mapped for entity [%s].',
                $name,
                $this->className,
            ));
        }

        return $this->embeddeds[$name];
    }

    public function hasEmbedded(string $name): bool
    {
        return isset($this->embeddeds[$name]);
    }

    public function newInstance(): object
    {
        $reflection = new ReflectionClass($this->className);

        return $reflection->newInstanceWithoutConstructor();
    }

    public function identifierValue(object $entity): int|string|null
    {
        if (! $this->identifier->hasValue($entity)) {
            return null;
        }

        $value = $this->identifier->getValue($entity);

        if ($value === null || $value === '') {
            return null;
        }

        return $this->canonicalizeIdentifier($value);
    }

    public function canonicalizeIdentifier(mixed $value): int|string
    {
        $canonical = $this->identifier->databaseValueFrom($this->identifier->castValue($value));

        if (is_int($canonical) || is_string($canonical)) {
            return $canonical;
        }

        if (is_object($canonical) && method_exists($canonical, '__toString')) {
            return (string) $canonical;
        }

        throw new RuntimeException(sprintf(
            'Entity [%s] uses an unsupported identifier value of type [%s].',
            $this->className,
            get_debug_type($canonical),
        ));
    }

    public function keyFor(mixed $identifier): EntityKey
    {
        return new EntityKey($this->className, $this->canonicalizeIdentifier($identifier));
    }

    /**
     * @return array<string, mixed>
     */
    public function extract(object $entity, bool $includeIdentifier = true): array
    {
        $values = [];

        foreach ($this->mappedFields() as $field) {
            if (! $includeIdentifier && $field->identifier) {
                continue;
            }

            if (! $field->hasValue($entity)) {
                continue;
            }

            $values[$field->name] = $field->getValue($entity);
        }

        foreach ($this->embeddeds() as $embedded) {
            if (! $embedded->hasEmbedded($entity)) {
                continue;
            }

            $valueObject = $embedded->getEmbedded($entity);
            foreach ($embedded->mappedInnerFields() as $innerField) {
                if (! $innerField->hasValue($valueObject)) {
                    continue;
                }

                $values[$embedded->name . '.' . $innerField->name] = $innerField->getValue($valueObject);
            }
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    public function extractForWrite(object $entity, bool $includeIdentifier = true): array
    {
        $values = [];

        foreach ($this->mappedFields() as $field) {
            if (! $includeIdentifier && $field->identifier) {
                continue;
            }

            if (! $field->hasValue($entity)) {
                continue;
            }

            $values[$field->column] = $field->databaseValue($entity);
        }

        foreach ($this->embeddeds() as $embedded) {
            if (! $embedded->hasEmbedded($entity)) {
                $valueObject = null;
            } else {
                $valueObject = $embedded->getEmbedded($entity);
            }

            foreach ($embedded->mappedInnerFields() as $innerField) {
                if ($valueObject === null) {
                    $values[$innerField->column] = $innerField->databaseValueFrom(null);

                    continue;
                }

                if (! $innerField->hasValue($valueObject)) {
                    continue;
                }

                $values[$innerField->column] = $innerField->databaseValueFrom($innerField->getValue($valueObject));
            }
        }

        return $values;
    }

    public function assignIdentifier(object $entity, mixed $identifier): void
    {
        $this->identifier->setValue($entity, $identifier);
    }

    public function hydrate(array $row, ?object $entity = null): object
    {
        $entity ??= $this->newInstance();

        foreach ($this->mappedFields() as $field) {
            if (! array_key_exists($field->column, $row)) {
                continue;
            }

            $field->setValue($entity, $row[$field->column]);
        }

        foreach ($this->embeddeds() as $embedded) {
            $hasAnyColumn = false;
            $allNull = true;
            $collected = [];

            foreach ($embedded->mappedInnerFields() as $innerField) {
                if (! array_key_exists($innerField->column, $row)) {
                    continue;
                }

                $hasAnyColumn = true;
                $rowValue = $row[$innerField->column];
                if ($rowValue !== null) {
                    $allNull = false;
                }
                $collected[$innerField->name] = $rowValue;
            }

            if (! $hasAnyColumn) {
                continue;
            }

            if ($allNull) {
                $embedded->setEmbedded($entity, null);

                continue;
            }

            $valueObject = $embedded->newEmbeddableInstance();
            foreach ($collected as $innerName => $rowValue) {
                $innerField = $embedded->innerField($innerName);
                $innerField->setValue($valueObject, $rowValue);
            }

            $embedded->setEmbedded($entity, $valueObject);
        }

        return $entity;
    }
}
