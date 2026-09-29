<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class ManyToOne
{
    /**
     * @param class-string  $targetEntity
     * @param list<string>  $cascade Values from \Quantum\Database\ORM\Contracts\Cascade::* (PERSIST / REMOVE supported in V1)
     */
    public function __construct(
        public string $targetEntity,
        public ?string $inversedBy = null,
        public ?string $joinColumn = null,
        public ?string $referencedColumn = null,
        public array $cascade = [],
    ) {
    }
}
