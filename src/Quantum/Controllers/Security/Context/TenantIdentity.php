<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Context;

final readonly class TenantIdentity
{
    public function __construct(
        public string $id,
        public string $source,
        public bool $verified,
    ) {}
}
