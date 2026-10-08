<?php

declare(strict_types=1);

namespace Quantum\Container\Graph;

final readonly class DependencyReference
{
    public function __construct(
        public string $abstract,
        public bool $optional = false,
    ) {
    }
}
