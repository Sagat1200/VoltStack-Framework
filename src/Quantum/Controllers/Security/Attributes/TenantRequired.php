<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class TenantRequired
{
    public function __construct(
        public bool $verified = true,
        public ?array $allowedTenants = null,
        public ?int $priority = null,
        public bool $final = false,
    ) {
    }
}
