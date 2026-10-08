<?php

declare(strict_types=1);

namespace Quantum\Cache;

use JsonSerializable;

final readonly class CachePoolSummary implements JsonSerializable
{
    public function __construct(
        public string $pool,
        public string $store,
        public string $driver,
        public string $prefix,
        public mixed $defaultTtl,
        public string $topology,
        public string $classification,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'pool' => $this->pool,
            'store' => $this->store,
            'driver' => $this->driver,
            'prefix' => $this->prefix,
            'default_ttl' => $this->defaultTtl,
            'topology' => $this->topology,
            'classification' => $this->classification,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
