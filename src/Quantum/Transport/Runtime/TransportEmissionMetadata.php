<?php

declare(strict_types=1);

namespace Quantum\Transport\Runtime;

final readonly class TransportEmissionMetadata
{
    public function __construct(
        public int $status,
        public array $headers = [],
    ) {
    }
}

