<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class OneToMany
{
    /**
     * @param class-string  $targetEntity
     * @param list<string>  $cascade       Values from \Quantum\Database\ORM\Contracts\Cascade::* (PERSIST / REMOVE supported in V1)
     * @param bool          $orphanRemoval When true, entities removed from the inverse collection are also deleted on flush.
     */
    public function __construct(
        public string $targetEntity,
        public string $mappedBy,
        public array $cascade = [],
        public bool $orphanRemoval = false,
    ) {
    }
}
