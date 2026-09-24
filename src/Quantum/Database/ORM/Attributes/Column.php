<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Column
{
    /**
     * @param class-string<\BackedEnum>|null $enumType
     */
    public function __construct(
        public ?string $name = null,
        public ?string $type = null,
        public ?string $enumType = null,
    ) {
    }
}
