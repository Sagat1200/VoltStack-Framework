<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class PolicyClass
{
    public function __construct(
        public ?string $id = null,
        public int $priority = 1000,
        public bool $enabled = true,
    ) {
    }
}
