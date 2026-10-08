<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class OneToOne
{
    /**
     * @param class-string $targetEntity
     * @param list<string> $cascade Values from \Quantum\Database\ORM\Contracts\Cascade::*
     * @param string       $fetch   Association fetch strategy (`lazy` by default, `eager` opt-in in V1)
     */
    public function __construct(
        public string $targetEntity,
        public ?string $mappedBy = null,
        public ?string $inversedBy = null,
        public ?string $joinColumn = null,
        public ?string $referencedColumn = null,
        public array $cascade = [],
        public string $fetch = 'lazy',
    ) {
    }
}
