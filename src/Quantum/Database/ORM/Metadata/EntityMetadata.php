<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Metadata;

use Quantum\Database\ORM\EntityKey;
use ReflectionClass;
use RuntimeException;

final class EntityMetadata
{
    /**
     * @param array<string, EntityFieldMetadata> $fields
     */
    public function __construct(
        public readonly string $className,
        public readonly string $table,
        public readonly array $fields,
        public readonly EntityFieldMetadata $identifier,
        public readonly ?string $repositoryClass = null,
    ) {
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
        $canonical = $this->identifier->castValue($value);

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

            $values[$field->column] = $field->getValue($entity);
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

        return $entity;
    }
}
