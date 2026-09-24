<?php

declare(strict_types=1);

namespace Quantum\Authorization\Policy\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class PolicyFor
{
    /**
     * @param class-string|list<class-string> $subject
     */
    public function __construct(
        public string|array $subject,
    ) {}
}
