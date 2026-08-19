<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Metadata\Attribute;

use Quantum\Database\Orm\Association\Enum\CascadeKind;
use Quantum\Database\Orm\Association\Enum\FetchMode;

/**
 * #[OneToOne].
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class OneToOne
{
    /**
     * @param class-string $targetEntity
     * @param list<CascadeKind> $cascade
     */
    public function __construct(
        public string $targetEntity,
        public ?string $mappedBy = null,
        public ?string $inversedBy = null,
        public array $cascade = [],
        public FetchMode $fetch = FetchMode::Lazy,
        public bool $orphanRemoval = false,
        public ?string $joinColumn = null,
        public ?string $referencedColumn = null,
        public bool $nullable = true,
    ) {}
}
