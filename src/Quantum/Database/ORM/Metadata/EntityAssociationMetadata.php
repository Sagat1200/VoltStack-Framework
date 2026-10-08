<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Metadata;

use Quantum\Database\ORM\Contracts\Cascade;
use ReflectionProperty;

final class EntityAssociationMetadata
{
    public const KIND_MANY_TO_ONE = 'many_to_one';
    public const KIND_ONE_TO_MANY = 'one_to_many';
    public const KIND_ONE_TO_ONE = 'one_to_one';
    public const KIND_MANY_TO_MANY = 'many_to_many';
    public const FETCH_LAZY = 'lazy';
    public const FETCH_EAGER = 'eager';

    /**
     * @param class-string  $targetEntity
     * @param list<string>  $cascade      Values from Cascade::* (V1 supports PERSIST and REMOVE)
     */
    public function __construct(
        public readonly string $name,
        public readonly string $kind,
        /** @var class-string */
        public readonly string $targetEntity,
        public readonly ReflectionProperty $property,
        public readonly ?string $sourceField = null,
        public readonly ?string $sourceColumn = null,
        public readonly ?string $targetField = null,
        public readonly ?string $targetColumn = null,
        public readonly ?string $mappedBy = null,
        public readonly ?string $inversedBy = null,
        public readonly array $cascade = [],
        public readonly bool $orphanRemoval = false,
        public readonly ?string $joinTable = null,
        public readonly ?string $joinTableSourceColumn = null,
        public readonly ?string $joinTableTargetColumn = null,
        public readonly string $fetch = self::FETCH_LAZY,
    ) {
        if (! in_array($this->fetch, [self::FETCH_LAZY, self::FETCH_EAGER], true)) {
            throw new \RuntimeException(sprintf(
                'Association [%s::$%s] declares unsupported fetch strategy [%s]. Allowed: %s.',
                $property->getDeclaringClass()->getName(),
                $name,
                $this->fetch,
                implode(', ', [self::FETCH_LAZY, self::FETCH_EAGER]),
            ));
        }
    }

    public function isOwningSide(): bool
    {
        return $this->kind === self::KIND_MANY_TO_ONE
            || ($this->kind === self::KIND_ONE_TO_ONE && $this->mappedBy === null)
            || ($this->kind === self::KIND_MANY_TO_MANY && $this->mappedBy === null);
    }

    public function isInverseSide(): bool
    {
        return $this->kind === self::KIND_ONE_TO_MANY
            || ($this->kind === self::KIND_ONE_TO_ONE && $this->mappedBy !== null)
            || ($this->kind === self::KIND_MANY_TO_MANY && $this->mappedBy !== null);
    }

    public function isManyToOne(): bool
    {
        return $this->kind === self::KIND_MANY_TO_ONE;
    }

    public function isOneToMany(): bool
    {
        return $this->kind === self::KIND_ONE_TO_MANY;
    }

    public function isOneToOne(): bool
    {
        return $this->kind === self::KIND_ONE_TO_ONE;
    }

    public function isManyToMany(): bool
    {
        return $this->kind === self::KIND_MANY_TO_MANY;
    }

    public function isToOne(): bool
    {
        return $this->isManyToOne() || $this->isOneToOne();
    }

    public function isToMany(): bool
    {
        return $this->isOneToMany() || $this->isManyToMany();
    }

    public function cascadesPersist(): bool
    {
        return in_array(Cascade::PERSIST, $this->cascade, true);
    }

    public function cascadesRemove(): bool
    {
        return in_array(Cascade::REMOVE, $this->cascade, true);
    }

    public function usesJoinTable(): bool
    {
        return $this->joinTable !== null
            && $this->joinTableSourceColumn !== null
            && $this->joinTableTargetColumn !== null;
    }

    public function isEager(): bool
    {
        return $this->fetch === self::FETCH_EAGER;
    }

    public function isLazy(): bool
    {
        return $this->fetch === self::FETCH_LAZY;
    }
}
