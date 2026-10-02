<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class ManyToMany
{
    /**
     * @param class-string $targetEntity
     * @param list<string> $cascade Values from \Quantum\Database\ORM\Contracts\Cascade::*
     */
    public function __construct(
        public string $targetEntity,
        public ?string $mappedBy = null,
        public ?string $inversedBy = null,
        public array $cascade = [],
    ) {
    }
}
