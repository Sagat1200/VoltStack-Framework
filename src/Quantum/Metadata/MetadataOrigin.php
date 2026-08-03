<?php

declare(strict_types=1);

namespace Quantum\Metadata;

final readonly class MetadataOrigin
{
    public function __construct(
        public string $provider,
        public string $type,
        public ?string $location = null,
    ) {
    }
}

