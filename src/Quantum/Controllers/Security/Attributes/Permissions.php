<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Permissions
{
    public function __construct(
        public array $permissions,
        public ?int $priority = null,
        public bool $final = false,
    ) {
    }
}
