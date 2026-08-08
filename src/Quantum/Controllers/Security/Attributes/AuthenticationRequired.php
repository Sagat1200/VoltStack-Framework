<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Attributes;

use Attribute;
use Quantum\Controllers\Security\Context\AuthenticationStrength;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class AuthenticationRequired
{
    public function __construct(
        public AuthenticationStrength $minimumStrength = AuthenticationStrength::Password,
        public bool $requireAny = false,
        public ?int $priority = null,
        public bool $final = false,
    ) {
    }
}
