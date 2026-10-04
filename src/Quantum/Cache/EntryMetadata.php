<?php

declare(strict_types=1);

namespace Quantum\Cache;

final readonly class EntryMetadata
{
    /**
     * @param array<string, mixed> $versions
     */
    public function __construct(
        public string $writeId,
        public int $createdAtMs,
        public ?int $freshUntilMs,
        public ?int $hardUntilMs,
        public ?int $observedAtMs = null,
        public ?int $ageMs = null,
        public ?int $ttlRemainingMs = null,
        public array $versions = [],
        public string $sourceLevel = 'local',
    ) {}
}
