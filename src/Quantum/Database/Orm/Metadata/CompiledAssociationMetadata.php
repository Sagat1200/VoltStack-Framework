<?php

declare(strict_types=1);

namespace Quantum\Database\Orm\Metadata;

use Quantum\Database\Orm\Association\Enum\AssociationKind;
use Quantum\Database\Orm\Association\Enum\CascadeKind;
use Quantum\Database\Orm\Association\Enum\CollectionKind;
use Quantum\Database\Orm\Association\Enum\FetchMode;

/**
 * Metadata compilada de 1 asociación (One/OneToMany/ManyToOne/ManyToMany).
 */
final readonly class CompiledAssociationMetadata
{
    /**
     * @param AssociationKind $kind
     * @param class-string $targetEntityClass
     * @param list<CascadeKind> $cascades
     * @param array<string,string> $defaultOrderBy
     * @param class-string|null $collectionEntryClass for ManyToMany/OneToMany collection (null = infer targetEntity)
     */
    public function __construct(
        public AssociationKind $kind,
        public string $propertyName,
        public string $targetEntityClass,
        public bool $isOwningSide,
        public ?string $mappedBy = null,
        public ?string $inversedBy = null,
        public array $cascades = [],
        public FetchMode $fetch = FetchMode::Lazy,
        public ?string $joinColumnName = null,
        public ?string $referencedColumnName = null,
        public bool $joinColumnNullable = true,
        public ?string $joinTableName = null,
        public ?string $joinColumnThisSide = null,
        public ?string $joinColumnTargetSide = null,
        public bool $orphanRemoval = false,
        public CollectionKind $collectionKind = CollectionKind::ArrayCollection,
        public array $defaultOrderBy = [],
        public ?int $defaultLimit = null,
        public ?string $indexBy = null,
        public ?string $collectionEntryClass = null,
    ) {}

    public function hasCascade(CascadeKind $k): bool
    {
        if (in_array(CascadeKind::All, $this->cascades, true)) {
            return true;
        }
        return in_array($k, $this->cascades, true);
    }
}