<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Embedded
{
    /**
     * @param class-string $class
     */
    public function __construct(
        public string $class,
        public ?string $prefix = null,
    ) {
    }
}
