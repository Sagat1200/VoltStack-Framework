<?php

declare(strict_types=1);

namespace Quantum\Authorization\Policy\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class HandlesAbility
{
    /**
     * @param string|list<string> $abilities
     */
    public function __construct(
        public string|array $abilities,
    ) {}
}
