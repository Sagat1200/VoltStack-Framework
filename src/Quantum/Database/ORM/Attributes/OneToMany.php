<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class OneToMany
{
    /**
     * @param class-string $targetEntity
     */
    public function __construct(
        public string $targetEntity,
        public string $mappedBy,
    ) {
    }
}
