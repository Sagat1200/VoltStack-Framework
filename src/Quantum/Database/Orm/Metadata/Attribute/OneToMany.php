<?php

declare(strict_types=1);

namespace Quantum\Database\Orm\Metadata\Attribute;

use Quantum\Database\Orm\Association\Enum\CascadeKind;
use Quantum\Database\Orm\Association\Enum\CollectionKind;
use Quantum\Database\Orm\Association\Enum\FetchMode;

/**
 * #[OneToMany]. Inverse side: siempre requiere mappedBy.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class OneToMany
{
    /**
     * @param class-string $targetEntity
     * @param list<CascadeKind> $cascade
     * @param array<string,string> $orderBy ej: ["createdAt" => "DESC"]
     */
    public function __construct(
        public string $targetEntity,
        public string $mappedBy,
        public array $cascade = [],
        public FetchMode $fetch = FetchMode::Lazy,
        public CollectionKind $collection = CollectionKind::ArrayCollection,
        public bool $orphanRemoval = false,
        public array $orderBy = [],
        public ?int $defaultLimit = null,
        public ?string $indexBy = null,
    ) {}
}