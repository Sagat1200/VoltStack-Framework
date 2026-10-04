<?php

declare(strict_types=1);

namespace Quantum\Cache;

final readonly class Lookup
{
    public function __construct(
        public HitState $state,
        public mixed $value,
        public ?EntryMetadata $metadata = null,
        public ?string $missReason = null,
    ) {}
}
