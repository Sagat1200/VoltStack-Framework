<?php

declare(strict_types=1);

namespace Quantum\Metadata\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Meta
{
    public function __construct(
        public string $key,
        public mixed $value,
        public ?int $priority = null,
        public bool $final = false,
    ) {
    }
}

