<?php

declare(strict_types=1);

namespace Quantum\Cache\Contracts;

interface InspectableStoreInterface extends StoreInterface
{
    /**
     * @return array{expires_at: int|null, value: mixed, created_at_ms: int}|null
     */
    public function payload(string $key): ?array;

    public function sourceLevel(): string;
}
