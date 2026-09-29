<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Metadata;

use Quantum\Database\ORM\Contracts\Cascade;
use ReflectionProperty;

final class EntityAssociationMetadata
{
    public const KIND_MANY_TO_ONE = 'many_to_one';
    public const KIND_ONE_TO_MANY = 'one_to_many';

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
    ) {
    }

    public function isOwningSide(): bool
    {
        return $this->kind === self::KIND_MANY_TO_ONE;
    }

    public function isInverseSide(): bool
    {
        return $this->kind === self::KIND_ONE_TO_MANY;
    }

    public function isManyToOne(): bool
    {
        return $this->kind === self::KIND_MANY_TO_ONE;
    }

    public function isOneToMany(): bool
    {
        return $this->kind === self::KIND_ONE_TO_MANY;
    }

    public function cascadesPersist(): bool
    {
        return in_array(Cascade::PERSIST, $this->cascade, true);
    }

    public function cascadesRemove(): bool
    {
        return in_array(Cascade::REMOVE, $this->cascade, true);
    }
}
