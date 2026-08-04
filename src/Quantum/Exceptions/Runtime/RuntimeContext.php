<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Runtime;

final readonly class RuntimeContext
{
    public function __construct(
        public string $environment,
    ) {
    }
}

