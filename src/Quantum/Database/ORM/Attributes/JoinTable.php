<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class JoinTable
{
    /**
     * @param list<string> $joinColumns
     * @param list<string> $inverseJoinColumns
     */
    public function __construct(
        public string $name,
        public array $joinColumns = [],
        public array $inverseJoinColumns = [],
    ) {
    }
}
