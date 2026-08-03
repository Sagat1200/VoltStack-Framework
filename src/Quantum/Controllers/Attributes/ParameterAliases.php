<?php

declare(strict_types=1);

namespace Quantum\Controllers\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class ParameterAliases
{
    public function __construct(
        public array $aliases,
        public ?int $priority = null,
        public bool $final = false,
    ) {
    }
}

