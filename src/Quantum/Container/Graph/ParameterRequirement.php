<?php

declare(strict_types=1);

namespace Quantum\Container\Graph;

final readonly class ParameterRequirement
{
    public function __construct(
        public string $name,
        public ?string $type,
        public bool $builtin,
        public bool $hasDefault,
    ) {
    }
}
