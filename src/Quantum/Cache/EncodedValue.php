<?php

declare(strict_types=1);

namespace Quantum\Cache;

final readonly class EncodedValue
{
    public function __construct(
        public string $formatId,
        public int $schemaVersion,
        public string $bytes,
    ) {}
}
