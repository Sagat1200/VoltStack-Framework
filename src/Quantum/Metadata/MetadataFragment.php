<?php

declare(strict_types=1);

namespace Quantum\Metadata;

final readonly class MetadataFragment
{
    public function __construct(
        public string $key,
        public mixed $value,
        public MetadataOrigin $origin,
        public int $priority = 0,
        public bool $final = false,
    ) {
    }
}

