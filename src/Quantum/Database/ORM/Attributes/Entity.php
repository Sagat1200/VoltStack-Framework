<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Entity
{
    /**
     * @param class-string|null $repository
     */
    public function __construct(
        public ?string $repository = null,
    ) {
    }
}
