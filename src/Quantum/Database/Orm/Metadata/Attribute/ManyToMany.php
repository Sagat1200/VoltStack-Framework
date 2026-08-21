<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Metadata\Attribute;

use Quantum\Database\Orm\Association\Enum\CascadeKind;
use Quantum\Database\Orm\Association\Enum\CollectionKind;
use Quantum\Database\Orm\Association\Enum\FetchMode;

/**
 * #[ManyToMany] con pivot table joinTable.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class ManyToMany
{
    /**
     * @param class-string $targetEntity
     * @param list<CascadeKind> $cascade
     */
    public function __construct(
        public string $targetEntity,
        public ?string $mappedBy = null,
        public ?string $inversedBy = null,
        public ?string $joinTable = null,
        public ?string $joinColumn = null,
        public ?string $inverseJoinColumn = null,
        public array $cascade = [],
        public FetchMode $fetch = FetchMode::Lazy,
        public CollectionKind $collection = CollectionKind::ArrayCollection,
        public bool $orphanRemoval = false,
        public ?string $indexBy = null,
        public array $orderBy = [],
    ) {}
}
